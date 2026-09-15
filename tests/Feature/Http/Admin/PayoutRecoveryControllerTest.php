<?php

use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\Models\PayoutRecoveryAction;
use App\Domain\Payouts\PayoutProviderManager;
use App\Domain\Payouts\Services\PayoutService;
use App\Models\Admin;
use App\Models\Store;
use App\Payouts\Testing\FakePayoutProvider;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

/**
 * Proves App\Http\Controllers\Admin\PayoutRecoveryController::confirm()
 * never becomes a second, informal settlement path: every mutation goes
 * through PayoutEventProcessor::apply() and every invocation is durably
 * audited. Own copy of the claimed-attempt fixture builder (distinct name
 * from PayoutEventProcessorTest's) — Pest merges every Feature test file's
 * top-level functions into one shared namespace for a full-suite run; see
 * PaymentRecoveryControllerTest's own docblock for why this file keeps its
 * own copy instead of reusing that one.
 */
function httpClaimedPayoutAttempt(string $balance = '100.00', string $amount = '80.00'): PayoutAttempt
{
    app(PayoutProviderManager::class)->extend('fake-http', fn () => new FakePayoutProvider);

    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', $balance);

    $service = app(PayoutService::class);
    $payout = $service->request($store, 'EUR', $amount, 'key-'.$store->id);
    $attempt = $service->createDurableAttempt($payout, 'fake-http');

    return $service->finalizeAttempt($attempt);
}

function confirmPayload(array $overrides = []): array
{
    return array_merge([
        'outcome' => 'succeeded',
        'external_transfer_reference' => 'sepa-'.str()->random(10),
        'amount' => '80.00',
        'currency' => 'EUR',
        'executed_at' => now()->subMinute()->toDateTimeString(),
        'failure_note' => null,
    ], $overrides);
}

it('lets an authorized admin confirm a succeeded manual transfer through the canonical settlement path', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload(['amount' => '80.00']))
        ->assertRedirect();

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($attempt->payout->fresh()->status)->toBe(PayoutStatus::Succeeded)
        ->and(PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->count())->toBe(1)
        ->and(PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->first()->outcome)->toBe('applied');
});

it('records the started audit row before the confirmation and finishes it once resolved', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload());

    $record = PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->firstOrFail();

    expect($record->admin_id)->toBe($admin->id)
        ->and($record->action)->toBe('manual_confirmation')
        ->and($record->outcome)->toBe('applied')
        ->and($record->metadata['external_transfer_reference'])->not->toBeNull();
});

it('confirms a failed manual transfer without reversing the reservation', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload([
            'outcome' => 'failed',
            'external_transfer_reference' => null,
            'failure_note' => 'IBAN rejected by bank',
        ]))
        ->assertRedirect();

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Failed)
        ->and($attempt->payout->fresh()->status)->toBe(PayoutStatus::Reserved)
        ->and($attempt->payout->wallet->fresh()->balance)->toBe('20.00');
});

it('fails closed on a sequential resubmission once the attempt has already settled — never a second settlement', function () {
    // A resubmission that reaches PayoutEventProcessor concurrently (both
    // requests pass authorization before either commits) is proven safe by
    // PayoutEventProcessorTest's own CAS-level test — this one instead
    // proves the HTTP layer's own defense-in-depth: once the first request
    // has actually committed, PayoutRecoveryPolicy fails a *sequential*
    // resubmission closed before it ever reaches the processor again,
    // rather than silently accepting a confirmation for an attempt whose
    // outcome is already permanently recorded.
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();
    $payload = confirmPayload();

    $this->actingAs($admin, 'admin')->post(route('admin.payouts.recovery.confirm', $attempt), $payload)->assertRedirect();
    $this->actingAs($admin, 'admin')->post(route('admin.payouts.recovery.confirm', $attempt), $payload)->assertForbidden();

    expect(PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->count())->toBe(1);
});

it('requires an external transfer reference for a succeeded outcome', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload(['external_transfer_reference' => null]))
        ->assertSessionHasErrors('external_transfer_reference');

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Claimed);
});

it('rejects a confirmation whose amount does not match the payout', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload(['amount' => '999.00']))
        ->assertRedirect();

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Claimed)
        ->and(PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->first()->outcome)->toBe('rejected');
});

it('rejects reusing an external transfer reference already confirmed for another payout', function () {
    $admin = Admin::factory()->create();
    $attemptA = httpClaimedPayoutAttempt();
    $attemptB = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attemptA), confirmPayload(['external_transfer_reference' => 'shared-sepa-ref']));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attemptB), confirmPayload(['external_transfer_reference' => 'shared-sepa-ref']))
        ->assertRedirect();

    expect($attemptB->fresh()->status)->toBe(PayoutAttemptStatus::Claimed)
        ->and(PayoutRecoveryAction::where('payout_attempt_id', $attemptB->id)->first()->outcome)->toBe('rejected');
});

it('fails closed for an attempt that has not been claimed yet', function () {
    $admin = Admin::factory()->create();

    app(PayoutProviderManager::class)->extend('fake-http-pending', fn () => new FakePayoutProvider);
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '100.00');
    $payout = app(PayoutService::class)->request($store, 'EUR', '80.00', 'unclaimed-key');
    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake-http-pending');

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload())
        ->assertForbidden();
});

it('fails closed for an already-terminal attempt', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();
    $attempt->update(['status' => PayoutAttemptStatus::Succeeded]);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload())
        ->assertForbidden();
});

it('rejects an unauthenticated request', function () {
    $attempt = httpClaimedPayoutAttempt();

    $this->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload())
        ->assertRedirect(route('admin.login'));
});

it('never exposes a raw exception message in the audit trail', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.confirm', $attempt), confirmPayload(['amount' => '999.00']));

    $record = PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->firstOrFail();

    expect($record->detail)->not->toContain('999.00')
        ->and($record->detail)->toContain('PayoutAttemptMismatchException');
});

it('lets an authorized admin retry a stale unclaimed attempt, which claims it via the normal provider call', function () {
    $admin = Admin::factory()->create();

    app(PayoutProviderManager::class)->extend('fake-http-retry', fn () => new FakePayoutProvider);
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '100.00');
    $payout = app(PayoutService::class)->request($store, 'EUR', '80.00', 'stale-key');
    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake-http-retry');
    $attempt->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.retry', $attempt))
        ->assertRedirect();

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Claimed)
        ->and($attempt->fresh()->provider_reference)->not->toBeNull()
        ->and(PayoutRecoveryAction::where('payout_attempt_id', $attempt->id)->first()->action)->toBe('retry_recovery');
});

it('rejects a retry for a fresh pending attempt still within the automatic reconciliation window', function () {
    $admin = Admin::factory()->create();

    app(PayoutProviderManager::class)->extend('fake-http-fresh', fn () => new FakePayoutProvider);
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '100.00');
    $payout = app(PayoutService::class)->request($store, 'EUR', '80.00', 'fresh-key');
    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake-http-fresh');

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.retry', $attempt))
        ->assertForbidden();
});

it('rejects a retry for an attempt that is already claimed', function () {
    $admin = Admin::factory()->create();
    $attempt = httpClaimedPayoutAttempt();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payouts.recovery.retry', $attempt))
        ->assertForbidden();
});
