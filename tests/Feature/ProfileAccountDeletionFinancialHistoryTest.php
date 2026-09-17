<?php

use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\PayoutProviderManager;
use App\Domain\Payouts\Services\PayoutService;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use App\Payouts\Testing\FakePayoutProvider;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Tests\Fakes\FakeTestPaymentProvider;

/**
 * Regression coverage for the CROSS-14 application-level guard added by
 * this branch: App\Http\Controllers\User\ProfileController::destroy() must
 * refuse to hard-delete a vendor's account — before any mutation, with a
 * controlled redirect, never a QueryException — whenever that user owns a
 * Store, trashed or not. See docs/financial/FAILURE-MODEL.md's "Critical
 * finding" for the exact reproduction this closes, and
 * tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest for proof
 * (against the real migrated schema, via PRAGMA foreign_key_list) of the
 * database-level restrictOnDelete() constraint this application-level
 * check exists to pre-empt.
 *
 * A1 is also covered by tests/Feature/ProfileTest.php's own "user can
 * delete their account" test; it's repeated here so this file is a
 * self-contained proof that the new guard didn't regress the unrelated,
 * pre-existing case of a customer who owns no Store.
 */
function vendorWithStore(): array
{
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);

    return [$user, $store];
}

function fakePaymentProvider(string $name, string $orderId): FakeTestPaymentProvider
{
    $provider = new FakeTestPaymentProvider($name, new ProviderPaymentResult(
        providerReference: "{$name}-ref",
        amountMinorUnits: 4000,
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: $orderId,
    ));

    app(PaymentProviderManager::class)->extend($name, fn () => $provider);

    return $provider;
}

function fakePayoutProvider(): FakePayoutProvider
{
    $provider = new FakePayoutProvider;

    app(PayoutProviderManager::class)->extend('fake', fn () => $provider);

    return $provider;
}

it('A1: deletes the account of a plain customer with no Store, exactly as before this branch', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/profile', [
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/');

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

it('A2: blocks deletion before any mutation when the user owns an empty Store', function () {
    [$user, $store] = vendorWithStore();

    $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('account')->assertRedirect('/profile');

    // Controlled response, not a crash: a 302 back to the form, not a 500.
    $response->assertStatus(302);

    // No logout: the store check runs before Auth::guard('web')->logout().
    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull()
        ->and($store->fresh())->not->toBeNull();
});

it('A3: still blocks deletion when the only Store is soft-deleted, proving withTrashed() closes the bypass', function () {
    [$user, $store] = vendorWithStore();
    $store->delete();

    // Confirms the fixture is actually soft-deleted (default queries
    // exclude it) — if this ever returned the store, the test below would
    // be proving nothing.
    expect(Store::find($store->id))->toBeNull()
        ->and(Store::withTrashed()->find($store->id))->not->toBeNull();

    $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('account')->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull()
        ->and(Store::withTrashed()->find($store->id))->not->toBeNull();
});

it('A4: blocks deletion and leaves Wallet/ledger history byte-for-byte unchanged', function () {
    [$user, $store] = vendorWithStore();

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $transaction = app(WalletTransactionService::class)->record($wallet, 'sale', '150.00');

    $walletBalanceBefore = $wallet->fresh()->balance;
    $transactionCountBefore = StoreWalletTransaction::where('store_wallet_id', $wallet->id)->count();

    $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('account')->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull()
        ->and($store->fresh())->not->toBeNull()
        ->and($wallet->fresh()->balance)->toBe($walletBalanceBefore)
        ->and(StoreWalletTransaction::where('store_wallet_id', $wallet->id)->count())->toBe($transactionCountBefore);

    $transactionAfter = StoreWalletTransaction::find($transaction->id);

    expect($transactionAfter)->not->toBeNull()
        ->and($transactionAfter->amount)->toBe($transaction->amount)
        ->and($transactionAfter->transaction_status_id)->toBe($transaction->transaction_status_id)
        ->and($transactionAfter->transaction_category_id)->toBe($transaction->transaction_category_id)
        ->and($transactionAfter->external_reference)->toBe($transaction->external_reference);
});

it('A5: blocks deletion and leaves Order/Payment/PaymentAttempt evidence untouched', function () {
    [$user, $store] = vendorWithStore();
    $order = Order::factory()->forStore($store)->amount('40.00')->create();

    fakePaymentProvider('fake_a5', (string) $order->id);
    app(PaymentService::class)->startAttempt($order, 'fake_a5', 'card');

    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    $attempt = PaymentAttempt::where('payment_id', $payment->id)->firstOrFail();
    $attemptReferenceBefore = $attempt->provider_reference;
    $attemptStatusBefore = $attempt->status;

    $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('account')->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull()
        ->and(Payment::find($payment->id))->not->toBeNull()
        ->and(PaymentAttempt::find($attempt->id))->not->toBeNull();

    $attemptAfter = PaymentAttempt::find($attempt->id);

    expect($attemptAfter->provider_reference)->toBe($attemptReferenceBefore)
        ->and($attemptAfter->status)->toBe($attemptStatusBefore);
});

it('A6: blocks deletion before the RESTRICT constraint would surface as an unhandled QueryException, leaving Payout evidence and the Wallet untouched', function () {
    [$user, $store] = vendorWithStore();

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '200.00');

    $payout = app(PayoutService::class)->request($store, 'EUR', '80.00', 'a6-key');

    fakePayoutProvider();
    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake');

    $walletBalanceBefore = $wallet->fresh()->balance;

    $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
        'password' => 'password',
    ]);

    // If the app-level guard weren't here, this DELETE would instead
    // resolve to a 500 (an unhandled QueryException from stores.user_id's
    // restrictOnDelete()) — a 302 with a validation error proves the
    // guard, not the database, stopped this.
    $response->assertStatus(302);
    $response->assertSessionHasErrors('account')->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull()
        ->and(Payout::find($payout->id))->not->toBeNull()
        ->and(PayoutAttempt::find($attempt->id))->not->toBeNull()
        ->and($wallet->fresh()->balance)->toBe($walletBalanceBefore);
});

it('A7: blocks deletion when the vendor has a mix of Wallet, Payment, and Payout history, and no financial evidence disappears', function () {
    [$user, $store] = vendorWithStore();

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $saleTransaction = app(WalletTransactionService::class)->record($wallet, 'sale', '300.00');

    $order = Order::factory()->forStore($store)->amount('40.00')->create();
    fakePaymentProvider('fake_a7', (string) $order->id);
    app(PaymentService::class)->startAttempt($order, 'fake_a7', 'card');
    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    $paymentAttempt = PaymentAttempt::where('payment_id', $payment->id)->firstOrFail();

    $payout = app(PayoutService::class)->request($store, 'EUR', '50.00', 'a7-key');
    fakePayoutProvider();
    $payoutAttempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake');

    $walletBalanceBefore = $wallet->fresh()->balance;
    $transactionCountBefore = StoreWalletTransaction::where('store_wallet_id', $wallet->id)->count();

    $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('account')->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull()
        ->and($store->fresh())->not->toBeNull()
        ->and($wallet->fresh()->balance)->toBe($walletBalanceBefore)
        ->and(StoreWalletTransaction::where('store_wallet_id', $wallet->id)->count())->toBe($transactionCountBefore)
        ->and(StoreWalletTransaction::find($saleTransaction->id))->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull()
        ->and(Payment::find($payment->id))->not->toBeNull()
        ->and(PaymentAttempt::find($paymentAttempt->id))->not->toBeNull()
        ->and(Payout::find($payout->id))->not->toBeNull()
        ->and(PayoutAttempt::find($payoutAttempt->id))->not->toBeNull();
});
