<?php

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Services\OrderCreationService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Store;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Currency;

/**
 * ORDER-ITEM-03 / ORDER-ITEM-18 / ORDER-ITEM-08 (mutation half): the runtime
 * guards on OrderItem and on a line-backed Order, exercised through every
 * Eloquent write style — including the quiet ones a model-event guard would
 * miss. What no model guard can see (query-builder/raw SQL) is deliberately
 * NOT asserted as prevented; it is covered by the static scan and by the
 * payment-time drift check (OrderItemPaymentIntegrationTest).
 */
function imOrder(string $price = '10.00', int $quantity = 2): Order
{
    $store = Store::factory()->create();
    $product = app(ProductService::class)->create($store, 'Widget', $price, Currency::query()->where('code', 'EUR')->value('id'));

    return app(OrderCreationService::class)->create($store, [['product' => $product, 'quantity' => $quantity]]);
}

/**
 * Asserts a write was refused. Paths that go through `fill()` (update(),
 * create(), updateQuietly()) are stopped even earlier by the empty
 * `$fillable` (MassAssignmentException); paths that bypass mass-assignment
 * (forceFill(), attribute assignment, saveQuietly(), withoutEvents(), touch())
 * can ONLY be stopped by the perform*() guards — those must throw the
 * model's own LogicException.
 */
function imRefused(Closure $attempt, bool $guardMustFire): void
{
    try {
        $attempt();
    } catch (MassAssignmentException $e) {
        expect($guardMustFire)->toBeFalse('expected the perform*() guard, got MassAssignmentException');

        return;
    } catch (LogicException $e) {
        expect(true)->toBeTrue();

        return;
    }

    throw new RuntimeException('Write was NOT refused.');
}

function imItemRow(OrderItem $item): array
{
    return (array) DB::table('order_items')->where('id', $item->id)->first();
}

// ---------------------------------------------------------------------
// OrderItem: no Eloquent update / insert / delete at all
// ---------------------------------------------------------------------

it('refuses every Eloquent style of updating a persisted OrderItem, for every snapshot field, and changes nothing (ORDER-ITEM-03)', function (string $field, mixed $value) {
    $item = imOrder()->items->first();
    $before = imItemRow($item);

    $attempts = [
        'update()' => [false, fn () => $item->update([$field => $value])],
        'forceFill()->save()' => [true, fn () => $item->forceFill([$field => $value])->save()],
        'attribute + save()' => [true, function () use ($item, $field, $value) {
            $item->{$field} = $value;
            $item->save();
        }],
        'updateQuietly()' => [false, fn () => $item->updateQuietly([$field => $value])],
        'saveQuietly()' => [true, function () use ($item, $field, $value) {
            $item->{$field} = $value;
            $item->saveQuietly();
        }],
        'withoutEvents()' => [true, fn () => OrderItem::withoutEvents(function () use ($item, $field, $value) {
            $item->forceFill([$field => $value])->save();
        })],
        'mixed update' => [true, fn () => $item->forceFill([$field => $value, 'quantity' => 99])->save()],
    ];

    foreach ($attempts as $label => [$guardMustFire, $attempt]) {
        imRefused($attempt, $guardMustFire);
        $item = $item->fresh();
    }

    expect(imItemRow($item))->toBe($before);
})->with([
    'order_id' => ['order_id', 999],
    'product_id' => ['product_id', 999],
    'product_name' => ['product_name', 'Changed'],
    'unit_price_amount' => ['unit_price_amount', '0.01'],
    'currency_id' => ['currency_id', 148],
    'quantity' => ['quantity', 50],
]);

it('refuses touch() and relationship re-association on a persisted OrderItem', function () {
    $item = imOrder()->items->first();
    $other = imOrder();

    // touch() only reaches performUpdate() when updated_at actually changes
    // (a same-second touch is a clean no-op that writes nothing), so move the
    // clock to make it a genuine write.
    Carbon::setTestNow(now()->addHour());

    expect(fn () => $item->touch())->toThrow(LogicException::class)
        ->and(function () use ($item, $other) {
            $item->order()->associate($other);
            $item->save();
        })->toThrow(LogicException::class);
});

it('refuses every Eloquent style of inserting an OrderItem — the only writer is the creation service (ORDER-ITEM-18)', function () {
    $order = imOrder();
    $attributes = [
        'order_id' => $order->id, 'product_id' => $order->items->first()->product_id, 'product_name' => 'Extra',
        'unit_price_amount' => '1.00', 'currency_id' => $order->currency_id, 'quantity' => 1,
    ];

    $attempts = [
        'create()' => [false, fn () => OrderItem::create($attributes)],
        'forceCreate()' => [true, fn () => OrderItem::forceCreate($attributes)],
        'relation create()' => [false, fn () => $order->items()->create($attributes)],
        'relation save()' => [true, fn () => $order->items()->save((new OrderItem)->forceFill($attributes))],
        'saveQuietly()' => [true, fn () => (new OrderItem)->forceFill($attributes)->saveQuietly()],
        'withoutEvents()' => [true, fn () => OrderItem::withoutEvents(fn () => (new OrderItem)->forceFill($attributes)->save())],
    ];

    foreach ($attempts as $label => [$guardMustFire, $attempt]) {
        imRefused($attempt, $guardMustFire);
    }

    expect($order->fresh()->items)->toHaveCount(1);
});

it('offers no mass-assignment surface at all', function () {
    expect((new OrderItem)->getFillable())->toBe([])
        ->and((new OrderItem)->isFillable('quantity'))->toBeFalse();
});

it('refuses every Eloquent style of deleting an OrderItem (ORDER-ITEM-18)', function () {
    $item = imOrder()->items->first();

    expect(fn () => $item->delete())->toThrow(LogicException::class)
        ->and(fn () => $item->deleteQuietly())->toThrow(LogicException::class)
        ->and(fn () => $item->forceDelete())->toThrow(LogicException::class)
        ->and(fn () => OrderItem::destroy($item->id))->toThrow(LogicException::class);

    expect(OrderItem::find($item->id))->not->toBeNull();
});

it('does not use SoftDeletes — a historical line either exists or it is a bug', function () {
    expect(in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive(OrderItem::class), true))->toBeFalse();
});

// ---------------------------------------------------------------------
// Line-backed Order: store / currency / amount are frozen (ORDER-ITEM-08)
// ---------------------------------------------------------------------

it('refuses every Eloquent style of changing store_id, currency_id or amount on a line-backed Order, and changes nothing', function (string $field, mixed $value) {
    $order = imOrder();
    $before = (array) DB::table('orders')->where('id', $order->id)->first();

    $attempts = [
        'update()' => fn () => $order->update([$field => $value]),
        'forceFill()->save()' => fn () => $order->forceFill([$field => $value])->save(),
        'attribute + save()' => function () use ($order, $field, $value) {
            $order->{$field} = $value;
            $order->save();
        },
        'updateQuietly()' => fn () => $order->updateQuietly([$field => $value]),
        'saveQuietly()' => function () use ($order, $field, $value) {
            $order->{$field} = $value;
            $order->saveQuietly();
        },
        'withoutEvents()' => fn () => Order::withoutEvents(fn () => $order->forceFill([$field => $value])->save()),
    ];

    foreach ($attempts as $label => $attempt) {
        expect($attempt)->toThrow(LogicException::class, 'line-backed Order');
        $order = $order->fresh();
    }

    expect((array) DB::table('orders')->where('id', $order->id)->first())->toBe($before);
})->with([
    'amount' => ['amount', '0.01'],
    'currency_id' => ['currency_id', 148],
    'store_id' => ['store_id', 999],
]);

it('refuses a currency()/store() association followed by save on a line-backed Order', function () {
    $order = imOrder();
    $usd = Currency::query()->where('code', 'USD')->firstOrFail();

    expect(function () use ($order, $usd) {
        $order->currency()->associate($usd);
        $order->save();
    })->toThrow(LogicException::class);

    expect(function () use ($order) {
        $order->store()->associate(Store::factory()->create());
        $order->save();
    })->toThrow(LogicException::class);
});

it('refuses a mixed update on a line-backed Order before any column is written', function () {
    $order = imOrder();

    expect(fn () => $order->forceFill(['amount' => '0.01', 'updated_at' => now()->addDay()])->save())->toThrow(LogicException::class);

    expect((string) $order->fresh()->amount)->toBe('20.00');
});

it('leaves legacy line-less Orders fully mutable exactly as before — fixtures keep working (ORDER-ITEM-11)', function () {
    $legacy = Order::factory()->amount('42.50')->create();
    $usd = Currency::query()->where('code', 'USD')->firstOrFail();

    $legacy->update(['amount' => '10.00']);
    $legacy->update(['currency_id' => $usd->id]);
    $legacy->saveQuietly();

    expect((string) $legacy->fresh()->amount)->toBe('10.00')
        ->and($legacy->fresh()->currency_id)->toBe($usd->id);
});

it('does not interfere with the existing status guard: status changes are still refused through Eloquent on a line-backed Order', function () {
    $order = imOrder();
    $paid = OrderStatus::bySlugOrFail('paid');

    expect(fn () => $order->update(['order_status_id' => $paid->id]))->toThrow(LogicException::class, 'OrderLifecycleService');
});

// ---------------------------------------------------------------------
// The derived line total is exact and never stored
// ---------------------------------------------------------------------

it('derives lineTotal() exactly from the immutable fields and stores no line-total column', function () {
    $item = imOrder('19.99', 3)->items->first();

    expect($item->lineTotal())->toBe('59.97')
        ->and(array_key_exists('line_total_amount', imItemRow($item)))->toBeFalse();
});
