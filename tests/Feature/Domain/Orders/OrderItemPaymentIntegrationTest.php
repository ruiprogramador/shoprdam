<?php

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Exceptions\OrderLineIntegrityException;
use App\Domain\Orders\Services\OrderCreationService;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentAttemptMismatchException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Currency;
use Tests\Fakes\FakeTestPaymentProvider;

/**
 * The financial boundary of feat/order-items, end to end against the real
 * PaymentService and Wallet (only the remote provider is a fake):
 *
 *   Product --snapshot once--> OrderItem --exact sum--> Order.amount
 *           --> Payment --> Wallet sale
 *
 * Payment settlement behavior is unchanged; these tests prove Payment reads
 * the Order's own amount (never a live Product price) and fails closed on a
 * line-backed Order that disagrees with its lines.
 */
function oipCurrency(string $code): int
{
    return Currency::query()->where('code', $code)->value('id');
}

/** @return array{0: Store, 1: Order, 2: array} */
function oipOrder(): array
{
    $store = Store::factory()->create();
    $service = app(ProductService::class);
    $a = $service->create($store, 'Widget A', '10.00', oipCurrency('EUR'));
    $b = $service->create($store, 'Widget B', '0.35', oipCurrency('EUR'));

    $order = app(OrderCreationService::class)->create($store, [
        ['product' => $a, 'quantity' => 3],
        ['product' => $b, 'quantity' => 2],
    ]);

    return [$store, $order, [$a, $b]];
}

function oipProvider(string $name, Order $order, int $minorUnits = 3070, string $currency = 'eur'): FakeTestPaymentProvider
{
    $provider = new FakeTestPaymentProvider($name, new ProviderPaymentResult(
        providerReference: "{$name}_ref_{$order->id}",
        amountMinorUnits: $minorUnits,
        currency: $currency,
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));
    app(PaymentProviderManager::class)->extend($name, fn () => $provider);

    return $provider;
}

function oipSale(Order $order): ?StoreWalletTransaction
{
    return StoreWalletTransaction::query()
        ->where('referenceable_type', Order::class)
        ->where('referenceable_id', $order->id)
        ->first();
}

it('A: pays a line-backed Order using Order.amount, which equals the exact sum of its lines, all the way to the Wallet sale', function () {
    [, $order] = oipOrder();
    oipProvider('oip_a', $order);

    $sum = $order->items->reduce(fn ($carry, $i) => bcadd($carry, $i->lineTotal(), 2), '0.00');

    expect((string) $order->amount)->toBe($sum)->toBe('30.70');

    $attempt = app(PaymentService::class)->startAttempt($order, 'oip_a', 'wallet');

    expect($attempt->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and((string) oipSale($order)->amount)->toBe('30.70');
});

it('B/C: repricing and re-currencying the Product after Order creation cannot change what Payment charges', function () {
    [, $order, [$a]] = oipOrder();

    app(ProductService::class)->changePrice($a, '999.00', oipCurrency('USD'));

    $order = $order->fresh();
    expect((string) $order->amount)->toBe('30.70')
        ->and($order->currency_id)->toBe(oipCurrency('EUR'))
        ->and($order->items->first()->currency_id)->toBe(oipCurrency('EUR'))
        ->and((string) $order->items->first()->unit_price_amount)->toBe('10.00');

    // A provider that "charged" the CURRENT Product price would be rejected by the existing settlement validation…
    oipProvider('oip_new_price', $order, minorUnits: 99900 + 70, currency: 'usd');
    expect(fn () => app(PaymentService::class)->startAttempt($order, 'oip_new_price', 'wallet'))
        ->toThrow(PaymentAttemptMismatchException::class);

    // …while the historical amount and currency are what a correct charge matches.
    $order2 = $order->fresh();
    oipProvider('oip_old_price', $order2);
    // (the mismatched attempt above left the Payment `pending`; a new provider resumes/creates as usual)
    PaymentAttempt::query()->update(['status' => PaymentAttemptStatus::Failed]);

    app(PaymentService::class)->startAttempt($order2, 'oip_old_price', 'wallet');

    expect((string) oipSale($order2)->amount)->toBe('30.70');
});

it('D: a Product soft-deleted after Order creation leaves the Order fully meaningful and payable', function () {
    [, $order, [$a, $b]] = oipOrder();

    app(ProductService::class)->delete($a);
    app(ProductService::class)->deactivate($b);

    oipProvider('oip_d', $order);
    app(PaymentService::class)->startAttempt($order->fresh(), 'oip_d', 'wallet');

    expect($order->fresh()->items)->toHaveCount(2)
        ->and((string) oipSale($order)->amount)->toBe('30.70');
});

it('F: a line-backed Order whose stored amount was tampered with fails closed before any Payment, attempt or provider call', function () {
    [, $order] = oipOrder();
    $provider = oipProvider('oip_f', $order);

    // A raw write: exactly the bypass no model guard can see.
    DB::table('orders')->where('id', $order->id)->update(['amount' => '0.01']);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'oip_f', 'wallet'))
        ->toThrow(OrderLineIntegrityException::class, 'does not equal the exact sum');

    expect(Payment::count())->toBe(0)
        ->and(PaymentAttempt::count())->toBe(0)
        ->and($provider->calledForAttemptIds)->toBe([])
        ->and(oipSale($order))->toBeNull()
        // Detected, never silently repaired.
        ->and((string) $order->fresh()->amount)->toBe('0.01');
});

it('F: tampering with an Order\'s currency, or with a line\'s quantity or price, is detected the same way', function (Closure $tamper, string $message) {
    [, $order] = oipOrder();
    $provider = oipProvider('oip_f2', $order);

    $tamper($order);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'oip_f2', 'wallet'))
        ->toThrow(OrderLineIntegrityException::class, $message);

    expect(Payment::count())->toBe(0)->and($provider->calledForAttemptIds)->toBe([]);
})->with([
    'order currency' => [fn (Order $o) => DB::table('orders')->where('id', $o->id)->update(['currency_id' => oipCurrency('USD')]), 'different currency'],
    'line quantity' => [fn (Order $o) => DB::table('order_items')->where('order_id', $o->id)->limit(1)->update(['quantity' => 4]), 'does not equal the exact sum'],
    'line price' => [fn (Order $o) => DB::table('order_items')->where('order_id', $o->id)->update(['unit_price_amount' => '1.00']), 'does not equal the exact sum'],
]);

it('F: a drifted Order is also refused at the claim step, so an attempt row created earlier cannot reach the provider (finalizeAttempt)', function () {
    [, $order] = oipOrder();
    $provider = oipProvider('oip_claim', $order);

    $payment = Payment::create(['order_id' => $order->id, 'status' => PaymentStatus::Pending]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'oip_claim',
        'method' => 'wallet',
        'idempotency_key' => 'k-oip-claim',
        'status' => PaymentAttemptStatus::Pending,
    ]);

    DB::table('orders')->where('id', $order->id)->update(['amount' => '0.01']);

    expect(fn () => app(PaymentService::class)->finalizeAttempt($attempt))->toThrow(OrderLineIntegrityException::class);

    expect($provider->calledForAttemptIds)->toBe([])
        ->and(oipSale($order))->toBeNull()
        ->and($attempt->fresh()->provider_reference)->toBeNull();
});

it('G: a legitimate legacy zero-line Order is still payable, unchanged (ORDER-ITEM-11)', function () {
    $order = Order::factory()->amount('20.00')->create();
    oipProvider('oip_g', $order, minorUnits: 2000);

    expect($order->items()->count())->toBe(0);

    $attempt = app(PaymentService::class)->startAttempt($order, 'oip_g', 'wallet');

    expect($attempt->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and((string) oipSale($order)->amount)->toBe('20.00');
});

it('G: a legacy Order whose amount is arbitrary (no lines to contradict it) is not judged by the integrity gate', function () {
    $order = Order::factory()->amount('0.01')->create();
    oipProvider('oip_g2', $order, minorUnits: 1);

    expect(app(PaymentService::class)->startAttempt($order, 'oip_g2', 'wallet')->status)->toBe(PaymentAttemptStatus::Claimed);
});
