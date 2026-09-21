<?php

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Exceptions\OrderLineIntegrityException;
use App\Domain\Orders\Services\OrderCreationService;
use App\Domain\Orders\Services\OrderLineIntegrityChecker;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\Currency;
use Tests\Fakes\FakeTestPaymentProvider;

/**
 * ORDER-ITEM-19: line-backed provenance is PERSISTENT (`orders.is_line_backed`),
 * never inferred from "does this Order have lines right now". An Order created
 * by OrderCreationService that later loses every line to a raw write must stay
 * line-backed, keep its aggregate fields protected, and fail closed at the
 * payment gate — instead of quietly looking like a legacy Order.
 */
function opvEur(): int
{
    return Currency::query()->where('code', 'EUR')->value('id');
}

function opvLineBacked(): Order
{
    $store = Store::factory()->create();
    $product = app(ProductService::class)->create($store, 'Widget', '10.00', opvEur());

    return app(OrderCreationService::class)->create($store, [['product' => $product, 'quantity' => 3]]);
}

function opvProvider(string $name, Order $order, int $minorUnits = 3000): FakeTestPaymentProvider
{
    $provider = new FakeTestPaymentProvider($name, new ProviderPaymentResult(
        providerReference: "{$name}_ref_{$order->id}",
        amountMinorUnits: $minorUnits,
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));
    app(PaymentProviderManager::class)->extend($name, fn () => $provider);

    return $provider;
}

function opvRawLineInsert(Order $order, string $price = '1.00', int $quantity = 1): void
{
    DB::table('order_items')->insert([
        'order_id' => $order->id,
        'product_id' => app(ProductService::class)->create(Store::find($order->store_id), 'Raw', '1.00', opvEur())->id,
        'product_name' => 'Raw',
        'unit_price_amount' => $price,
        'currency_id' => opvEur(),
        'quantity' => $quantity,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------
// Provenance is set atomically and correctly
// ---------------------------------------------------------------------

it('marks an Order created by OrderCreationService as line-backed, in the same commit as its lines', function () {
    $order = opvLineBacked();

    expect($order->is_line_backed)->toBeTrue()
        ->and((bool) DB::table('orders')->where('id', $order->id)->value('is_line_backed'))->toBeTrue()
        ->and($order->items)->toHaveCount(1);
});

it('never leaves a line-backed-marked Order behind when creation fails (provenance and lines commit or roll back together)', function () {
    $store = Store::factory()->create();
    $product = app(ProductService::class)->create($store, 'Widget', '10.00', opvEur());

    DB::unprepared("CREATE TRIGGER fail_any_item BEFORE INSERT ON order_items BEGIN SELECT RAISE(ABORT, 'simulated'); END");

    expect(fn () => app(OrderCreationService::class)->create($store, [['product' => $product, 'quantity' => 1]]))->toThrow(QueryException::class);

    expect(Order::count())->toBe(0)
        ->and(Order::where('is_line_backed', true)->count())->toBe(0);
});

it('identifies factory-built Orders as legacy, and leaves them fully mutable exactly as before', function () {
    $legacy = Order::factory()->amount('42.50')->create();
    $usd = Currency::query()->where('code', 'USD')->value('id');

    expect((bool) $legacy->fresh()->is_line_backed)->toBeFalse();

    $legacy->update(['amount' => '10.00', 'currency_id' => $usd]);

    expect((string) $legacy->fresh()->amount)->toBe('10.00');
});

it('gives a raw insert that omits the column — every pre-existing writer — legacy provenance, via a NOT NULL DEFAULT false column', function () {
    $store = Store::factory()->create();

    $id = DB::table('orders')->insertGetId([
        'store_id' => $store->id,
        'currency_id' => opvEur(),
        'order_status_id' => OrderStatus::bySlugOrFail('pending')->id,
        'amount' => '5.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $column = collect(Schema::getColumns('orders'))->firstWhere('name', 'is_line_backed');

    expect((int) DB::table('orders')->where('id', $id)->value('is_line_backed'))->toBe(0)
        ->and($column['nullable'])->toBeFalse();
});

it('migration backfill: an Order that exists BEFORE the migration is legacy afterwards, its row otherwise untouched, and no OrderItem is invented', function () {
    $migration = require database_path('migrations/2026_09_22_100000_add_is_line_backed_to_orders_table.php');
    $store = Store::factory()->create();

    $migration->down();

    expect(Schema::hasColumn('orders', 'is_line_backed'))->toBeFalse();

    $id = DB::table('orders')->insertGetId([
        'store_id' => $store->id,
        'currency_id' => opvEur(),
        'order_status_id' => OrderStatus::bySlugOrFail('paid')->id,
        'amount' => '77.70',
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-02 10:00:00',
    ]);

    $migration->up();

    $row = DB::table('orders')->where('id', $id)->first();

    expect((int) $row->is_line_backed)->toBe(0)
        ->and((string) $row->amount)->toBe('77.7')
        ->and($row->created_at)->toBe('2026-01-01 10:00:00')
        ->and($row->updated_at)->toBe('2026-01-02 10:00:00')
        ->and(DB::table('order_items')->count())->toBe(0);
});

// ---------------------------------------------------------------------
// The integrity checker's four-way matrix
// ---------------------------------------------------------------------

it('checker: legacy + zero lines is allowed (existing compatibility)', function () {
    $legacy = Order::factory()->amount('20.00')->create();

    expect(fn () => app(OrderLineIntegrityChecker::class)->assertConsistent($legacy))->not->toThrow(OrderLineIntegrityException::class);
});

it('checker: line-backed + lines validates amount and currency against the snapshots', function () {
    $order = opvLineBacked();

    expect(fn () => app(OrderLineIntegrityChecker::class)->assertConsistent($order))->not->toThrow(OrderLineIntegrityException::class);

    DB::table('orders')->where('id', $order->id)->update(['amount' => '29.99']);

    expect(fn () => app(OrderLineIntegrityChecker::class)->assertConsistent($order))
        ->toThrow(OrderLineIntegrityException::class, 'does not equal the exact sum');
});

it('checker: line-backed + zero lines is corrupt and fails closed — it is never mistaken for a legacy Order', function () {
    $order = opvLineBacked();
    DB::table('order_items')->where('order_id', $order->id)->delete();

    expect(fn () => app(OrderLineIntegrityChecker::class)->assertConsistent($order))
        ->toThrow(OrderLineIntegrityException::class, 'line-backed but has no lines');
});

it('checker: a legacy Order that somehow has lines is contradictory and fails closed', function () {
    $legacy = Order::factory()->amount('1.00')->create();
    opvRawLineInsert($legacy);

    expect(fn () => app(OrderLineIntegrityChecker::class)->assertConsistent($legacy))
        ->toThrow(OrderLineIntegrityException::class, 'legacy Order but has lines');
});

it('checker: flipping the provenance flag to legacy with a raw write does not hide the lines that remain', function () {
    $order = opvLineBacked();
    DB::table('orders')->where('id', $order->id)->update(['is_line_backed' => false]);

    expect(fn () => app(OrderLineIntegrityChecker::class)->assertConsistent($order))
        ->toThrow(OrderLineIntegrityException::class, 'legacy Order but has lines');
});

// ---------------------------------------------------------------------
// Raw deletion of every line of a line-backed Order
// ---------------------------------------------------------------------

it('after every line is raw-deleted, the Order is STILL line-backed', function () {
    $order = opvLineBacked();

    DB::table('order_items')->where('order_id', $order->id)->delete();

    $fresh = $order->fresh();

    expect($fresh->items()->count())->toBe(0)
        ->and($fresh->is_line_backed)->toBeTrue();
});

it('after every line is raw-deleted, Eloquent still refuses to change amount, currency or store — the guard does not vanish with the lines', function (string $field, mixed $value) {
    $order = opvLineBacked();
    DB::table('order_items')->where('order_id', $order->id)->delete();
    $order = $order->fresh();
    $before = (array) DB::table('orders')->where('id', $order->id)->first();

    $attempts = [
        'update()' => fn () => $order->update([$field => $value]),
        'forceFill()->save()' => fn () => $order->forceFill([$field => $value])->save(),
        'updateQuietly()' => fn () => $order->updateQuietly([$field => $value]),
        'saveQuietly()' => function () use ($order, $field, $value) {
            $order->{$field} = $value;
            $order->saveQuietly();
        },
        'withoutEvents()' => fn () => Order::withoutEvents(fn () => $order->forceFill([$field => $value])->save()),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(LogicException::class, 'line-backed Order');
        $order = $order->fresh();
    }

    expect((array) DB::table('orders')->where('id', $order->id)->first())->toBe($before);
})->with([
    'amount' => ['amount', '0.01'],
    'currency_id' => ['currency_id', 148],
    'store_id' => ['store_id', 999],
]);

it('guards an Order instance loaded without the provenance column: an unloaded flag never reads as legacy', function () {
    $order = opvLineBacked();
    DB::table('order_items')->where('order_id', $order->id)->delete();

    $partial = Order::query()->select(['id', 'amount'])->findOrFail($order->id);

    expect(fn () => $partial->update(['amount' => '0.01']))->toThrow(LogicException::class, 'line-backed Order');
    expect((string) $order->fresh()->amount)->toBe('30.00');
});

it('refuses to change the provenance flag itself through Eloquent, in either direction, for any Order', function () {
    $lineBacked = opvLineBacked();
    $legacy = Order::factory()->create();

    expect(fn () => $lineBacked->forceFill(['is_line_backed' => false])->save())->toThrow(LogicException::class, 'provenance')
        ->and(fn () => $legacy->forceFill(['is_line_backed' => true])->save())->toThrow(LogicException::class, 'provenance');

    // Mass assignment silently drops a non-fillable key: update() cannot set the flag either.
    $legacy->fresh()->update(['is_line_backed' => true, 'amount' => '3.00']);

    expect((bool) $lineBacked->fresh()->is_line_backed)->toBeTrue()
        ->and((bool) $legacy->fresh()->is_line_backed)->toBeFalse();
});

it('has no mass-assignable provenance: is_line_backed is not fillable', function () {
    expect((new Order)->isFillable('is_line_backed'))->toBeFalse();
});

// ---------------------------------------------------------------------
// Payment is never reached on a corrupted zero-line line-backed Order
// ---------------------------------------------------------------------

it('PaymentService::startAttempt() fails closed on a line-backed Order whose lines were raw-deleted, before any Payment, attempt, provider call or Wallet row', function () {
    $order = opvLineBacked();
    $provider = opvProvider('opv_zero', $order);
    DB::table('order_items')->where('order_id', $order->id)->delete();

    $walletBefore = StoreWalletTransaction::count();

    expect(fn () => app(PaymentService::class)->startAttempt($order->fresh(), 'opv_zero', 'wallet'))
        ->toThrow(OrderLineIntegrityException::class, 'line-backed but has no lines');

    expect(Payment::count())->toBe(0)
        ->and(PaymentAttempt::count())->toBe(0)
        ->and($provider->calledForAttemptIds)->toBe([])
        ->and(StoreWalletTransaction::count())->toBe($walletBefore);
});

it('the claim step (finalizeAttempt) also refuses a zero-line line-backed Order, so an earlier attempt row cannot reach the provider', function () {
    $order = opvLineBacked();
    $provider = opvProvider('opv_claim', $order);

    $payment = Payment::create(['order_id' => $order->id, 'status' => PaymentStatus::Pending]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'opv_claim',
        'method' => 'wallet',
        'idempotency_key' => 'k-opv-claim',
        'status' => PaymentAttemptStatus::Pending,
    ]);

    DB::table('order_items')->where('order_id', $order->id)->delete();

    expect(fn () => app(PaymentService::class)->finalizeAttempt($attempt))
        ->toThrow(OrderLineIntegrityException::class, 'line-backed but has no lines');

    expect($provider->calledForAttemptIds)->toBe([])
        ->and($attempt->fresh()->provider_reference)->toBeNull()
        ->and(StoreWalletTransaction::where('referenceable_id', $order->id)->count())->toBe(0);
});

it('a legacy zero-line Order is still paid exactly as before', function () {
    $order = Order::factory()->amount('20.00')->create();
    $provider = new FakeTestPaymentProvider('opv_legacy', new ProviderPaymentResult(
        providerReference: 'opv_legacy_ref',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));
    app(PaymentProviderManager::class)->extend('opv_legacy', fn () => $provider);

    $attempt = app(PaymentService::class)->startAttempt($order, 'opv_legacy', 'wallet');

    expect($attempt->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and($provider->calledForAttemptIds)->toHaveCount(1);
});
