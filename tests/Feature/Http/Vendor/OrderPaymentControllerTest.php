<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\UserType;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeEasyPayHttpClient;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * Covers the HTTP surface the master prompt asks for: a vendor selects a
 * payment METHOD for their own Order, never a provider, and every security
 * invariant App\Domain\Payments\Services\PaymentService already enforces
 * (gating, terminal-failure retries, amount/currency trust) survives being
 * reached through App\Http\Controllers\Vendor\OrderPaymentController and
 * App\Http\Requests\Vendor\SelectPaymentMethodRequest instead of being
 * called directly. Provider settlement itself (webhooks, reconciliation) is
 * already covered under tests/Feature/Payments — not duplicated here.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

/** @return array{0: User, 1: Store, 2: Order} */
function createVendorOrder(string $amount = '42.50'): array
{
    $vendor = User::factory()->create([
        'user_type_id' => UserType::where('name', 'Vendor')->firstOrFail()->id,
    ]);
    $store = Store::factory()->create(['user_id' => $vendor->id]);
    $order = Order::factory()->forStore($store)->amount($amount)->create();

    return [$vendor, $store, $order];
}

function installStripeFake(string $paymentIntentId, string $orderId, array $overrides = []): void
{
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(array_merge([
        'id' => $paymentIntentId,
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'metadata' => ['order_id' => $orderId],
    ], $overrides)));
}

// --- Authorization / ownership ---

it('redirects a guest to login', function () {
    [, , $order] = createVendorOrder();

    $this->get(route('vendor.orders.payment.show', $order))->assertRedirect(route('login'));
    $this->post(route('vendor.orders.payment.store', $order), ['method' => 'card'])
        ->assertRedirect(route('login'));
});

it('denies a plain "user"-role account, even authenticated', function () {
    [, , $order] = createVendorOrder();

    $user = User::factory()->create([
        'user_type_id' => UserType::where('name', 'User')->firstOrFail()->id,
    ]);

    $this->actingAs($user)
        ->get(route('vendor.orders.payment.show', $order))
        ->assertRedirect(route('home'));
});

it('denies a vendor who does not own the order\'s store', function () {
    [, , $order] = createVendorOrder();

    $otherVendor = User::factory()->create([
        'user_type_id' => UserType::where('name', 'Vendor')->firstOrFail()->id,
    ]);

    $this->actingAs($otherVendor)
        ->get(route('vendor.orders.payment.show', $order))
        ->assertForbidden();

    $this->actingAs($otherVendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'card'])
        ->assertForbidden();

    expect(Payment::where('order_id', $order->id)->exists())->toBeFalse();
});

// --- Checkout is method-driven, not provider/Stripe-centric ---

it('shows the owning vendor a provider-agnostic list of payment methods', function () {
    [$vendor, , $order] = createVendorOrder();

    $this->actingAs($vendor)
        ->get(route('vendor.orders.payment.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Vendor/Orders/Payment')
            ->where('methods', ['card' => 'Card', 'mbway' => 'MB WAY', 'multibanco' => 'Multibanco'])
            ->where('payment', null));
});

it('selecting "card" resolves to the stripe provider without the client ever naming it', function () {
    [$vendor, , $order] = createVendorOrder();
    installStripeFake('pi_select_card', (string) $order->id);

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'card'])
        ->assertRedirect(route('vendor.orders.payment.show', $order));

    $attempt = PaymentAttempt::sole();

    expect($attempt->provider)->toBe('stripe')
        ->and($attempt->method)->toBe('card')
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Claimed);
});

it('selecting a second-provider method (mbway) resolves to easypay', function () {
    [$vendor, , $order] = createVendorOrder();
    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_select_mbway', (string) $order->id)))->install();

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'mbway'])
        ->assertRedirect(route('vendor.orders.payment.show', $order));

    $attempt = PaymentAttempt::sole();

    expect($attempt->provider)->toBe('easypay')
        ->and($attempt->method)->toBe('mbway');
});

it('selecting multibanco also resolves to easypay, distinctly from mbway', function () {
    [$vendor, , $order] = createVendorOrder();
    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_select_mb', (string) $order->id, ['method' => 'mb'])))->install();

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'multibanco'])
        ->assertRedirect(route('vendor.orders.payment.show', $order));

    $attempt = PaymentAttempt::sole();

    expect($attempt->provider)->toBe('easypay')
        ->and($attempt->method)->toBe('multibanco');
});

it('rejects an unsupported method server-side and creates nothing', function () {
    [$vendor, , $order] = createVendorOrder();

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'paypal'])
        ->assertSessionHasErrors('method');

    expect(Payment::where('order_id', $order->id)->exists())->toBeFalse();
});

it('ignores a client-supplied provider field entirely — the method alone decides the provider', function () {
    [$vendor, , $order] = createVendorOrder();
    installStripeFake('pi_manipulated', (string) $order->id);

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), [
            'method' => 'card',
            'provider' => 'easypay',
        ])
        ->assertRedirect(route('vendor.orders.payment.show', $order));

    // Stripe's fake was called (not EasyPay's) and the persisted provider
    // is 'stripe' — proof the 'provider' field in the payload was never read.
    expect(PaymentAttempt::sole()->provider)->toBe('stripe');
});

it('cannot override amount/currency from client input — the Order remains the source of truth', function () {
    [$vendor, , $order] = createVendorOrder('42.50');
    installStripeFake('pi_amount_guard', (string) $order->id, ['amount' => 4250, 'currency' => 'eur']);

    $this->actingAs($vendor)->post(route('vendor.orders.payment.store', $order), [
        'method' => 'card',
        'amount' => '999.99',
        'currency' => 'usd',
    ])->assertRedirect(route('vendor.orders.payment.show', $order));

    expect($order->fresh()->amount)->toBe('42.50')
        ->and(PaymentAttempt::sole()->status)->toBe(PaymentAttemptStatus::Claimed);
});

// --- Gating: PaymentService remains the sole authority ---

it('refuses to start a new attempt once the Payment is already paid', function () {
    [$vendor, , $order] = createVendorOrder();
    Payment::create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'card'])
        ->assertRedirect(route('vendor.orders.payment.show', $order))
        ->assertSessionHas('error');

    expect(PaymentAttempt::count())->toBe(0);
});

it('an active blocking attempt is resumed, never raced with a second charge path', function () {
    [$vendor, , $order] = createVendorOrder();
    installStripeFake('pi_active_block', (string) $order->id, ['status' => 'requires_action']);

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'card'])
        ->assertRedirect();

    $first = PaymentAttempt::sole();
    expect($first->status)->toBe(PaymentAttemptStatus::Claimed); // non-terminal, blocking

    // A second submission — even naming a different method — must not
    // create a competing attempt.
    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_should_not_be_used', (string) $order->id)))->install();

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'mbway'])
        ->assertRedirect();

    expect(PaymentAttempt::count())->toBe(1)
        ->and(PaymentAttempt::sole()->id)->toBe($first->id)
        ->and(PaymentAttempt::sole()->provider)->toBe('stripe')
        ->and(PaymentAttempt::sole()->method)->toBe('card');
});

it('a terminally failed attempt allows selecting another method, producing a genuinely new attempt', function () {
    [$vendor, , $order] = createVendorOrder();
    installStripeFake('pi_terminal_retry', (string) $order->id);

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'card'])
        ->assertRedirect();

    $first = PaymentAttempt::sole();
    $first->update(['status' => PaymentAttemptStatus::Failed]);

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_after_failure', (string) $order->id)))->install();

    $this->actingAs($vendor)
        ->post(route('vendor.orders.payment.store', $order), ['method' => 'mbway'])
        ->assertRedirect(route('vendor.orders.payment.show', $order))
        ->assertSessionHas('success');

    expect(PaymentAttempt::count())->toBe(2);

    $second = PaymentAttempt::where('id', '!=', $first->id)->sole();

    expect($second->provider)->toBe('easypay')
        ->and($second->method)->toBe('mbway')
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->current_payment_attempt_id)
        ->toBe($second->id);

    // The retry UX (show page) reflects the *new* current attempt, not the stale failed one.
    $this->actingAs($vendor)
        ->get(route('vendor.orders.payment.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('payment.attempt.method', 'mbway')
            ->where('payment.attempt.status', 'claimed'));
});
