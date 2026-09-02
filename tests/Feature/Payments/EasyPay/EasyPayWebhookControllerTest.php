<?php

use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Tests\Fakes\FakeEasyPayHttpClient;

/**
 * EasyPay's counterpart to Tests\Feature\Payments\Stripe\StripeWebhookControllerTest.
 *
 * The controller under test never trusts the posted notification body's
 * `status` — see App\Payments\EasyPay\EasyPayWebhookController's own
 * docblock — so every test here installs a FakeEasyPayHttpClient standing in
 * for the mandatory "call back the API to verify" step, keyed by the
 * notification's `id`. What the *notification* claims and what the *fake
 * verification response* says are deliberately allowed to differ in some
 * tests below, proving the controller acts on the verified response, never
 * the raw payload.
 */
beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->wallet = $this->store->wallets()->first();
    $this->order = Order::factory()->forStore($this->store)->amount('100.00')->create();

    app(WalletTransactionService::class)->record(
        wallet: $this->wallet,
        categorySlug: 'sale',
        amount: $this->order->amount,
        reference: new WalletTransactionReference('easypay', 'ep_test_123'),
        options: [
            'status' => 'pending',
            'referenceable' => $this->order,
        ],
    );
});

function fakeEasyPayVerification(string $id, array $resource): void
{
    (new FakeEasyPayHttpClient(responsesById: [$id => $resource]))->install();
}

it('confirms the wallet transaction and marks the order paid once the notification verifies as success', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123', ['status' => 'pending']));

    $response->assertOk();

    $transaction = StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail();

    expect($transaction->status->slug)
        ->toBe('completed')
        ->and($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
});

it('is idempotent when a success notification is delivered twice', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));

    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();
    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});

it('does not re-touch the order on a duplicate success delivery', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));

    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    $updatedAt = $this->order->fresh()->updated_at;
    $this->travelTo(now()->addMinute());

    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    expect($this->order->fresh()->updated_at)->toEqual($updatedAt);
});

it('rolls back the Wallet confirmation when marking the order paid fails, keeping the pair atomic', function () {
    OrderStatus::where('slug', 'paid')->delete();

    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'));

    $response->assertStatus(404);

    expect(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('marks the transaction and the order failed once the verified payment is terminally failed', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, [
        'status' => 'failed',
        'messages' => ['Payment declined by the issuer.'],
    ]));

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'));

    $response->assertOk();

    $transaction = StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail();

    expect($transaction->status->slug)
        ->toBe('failed')
        ->and($transaction->description)
        ->toContain('Payment declined by the issuer.')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('failed');
});

it('is idempotent when a failed notification is delivered twice', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'failed']));

    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();
    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'));

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('failed');
});

it('never touches the Wallet or the Order while the verified payment is still resolving', function (string $status) {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => $status]));

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'));

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
})->with(['pending', 'waiting', 'delayed']);

it('confirms the transaction on a later success notification after an intermediate pending state', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'waiting']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
});

it('does nothing when the verified payment reference is unknown', function () {
    fakeEasyPayVerification('ep_unknown', easyPayPaymentBody('ep_unknown', 'irrelevant', ['status' => 'success']));

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_unknown'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending')
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_unknown')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);
});

it('acts on the verified API response, not the (possibly stale or forged) status in the raw notification body', function () {
    // The notification claims 'success' — but verification says 'failed'.
    // Only the verified response may ever be trusted.
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'failed']));

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123', ['status' => 'success']));

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($this->order->fresh()->status->slug)
        ->toBe('failed');
});

it('rejects a payload missing the notification id', function () {
    $response = test()->postJson(route('easypay.webhook'), ['type' => 'capture']);

    $response->assertStatus(400);

    expect(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('pending');
});

it('returns 400 when the EasyPay API verification callback itself fails', function () {
    (new FakeEasyPayHttpClient(responseCode: 500))->install();

    $response = postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'));

    $response->assertStatus(400);

    expect(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('pending');
});

// --- Refunds ---------------------------------------------------------------

it('reverses the wallet transaction and marks the order refunded on a verified full refund', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    (new FakeEasyPayHttpClient(responsesById: [
        'ref_123' => easyPayRefundBody('ref_123', 'ep_test_123'),
    ]))->install();

    $response = postEasyPayWebhook(easyPayNotification('refund', 'ref_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2)
        ->and($this->order->fresh()->status->slug)
        ->toBe('refunded');
});

it('is idempotent when a refund notification is delivered twice', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    (new FakeEasyPayHttpClient(responsesById: [
        'ref_123' => easyPayRefundBody('ref_123', 'ep_test_123'),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('refund', 'ref_123'))->assertOk();
    $response = postEasyPayWebhook(easyPayNotification('refund', 'ref_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});

it('skips a partial refund instead of reversing the full transaction amount', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    (new FakeEasyPayHttpClient(responsesById: [
        'ref_partial' => easyPayRefundBody('ref_partial', 'ep_test_123', ['value' => '40.00']),
    ]))->install();

    $response = postEasyPayWebhook(easyPayNotification('refund', 'ref_partial'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1)
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
});

it('does nothing when a refund arrives before the payment has been confirmed', function () {
    (new FakeEasyPayHttpClient(responsesById: [
        'ref_early' => easyPayRefundBody('ref_early', 'ep_test_123'),
    ]))->install();

    $response = postEasyPayWebhook(easyPayNotification('refund', 'ref_early'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending')
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_test_123')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);
});

it('does nothing when a refund arrives for a transaction that was already marked failed', function () {
    fakeEasyPayVerification('ep_test_123', easyPayPaymentBody('ep_test_123', (string) $this->order->id, ['status' => 'failed']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_test_123'))->assertOk();

    (new FakeEasyPayHttpClient(responsesById: [
        'ref_after_fail' => easyPayRefundBody('ref_after_fail', 'ep_test_123'),
    ]))->install();

    $response = postEasyPayWebhook(easyPayNotification('refund', 'ref_after_fail'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_test_123')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($this->order->fresh()->status->slug)
        ->toBe('failed');
});
