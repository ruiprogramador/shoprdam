<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Services\OrderCreationService;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\Currency;

/**
 * Mirrors ProductSchemaDeletePolicyTest / PayoutSchemaDeletePolicyTest: reads
 * the ACTUAL constraint from the migrated schema (`PRAGMA foreign_key_list`)
 * and then proves it by attempting the forbidden deletes. This is the
 * database backstop `feat/catalog-domain` (CATALOG-09) was waiting for.
 *
 * FK direction, spelled out because it is easy to invert: `order_items` is the
 * CHILD. `restrictOnDelete()` on `order_items.order_id` means "deleting the
 * PARENT Order is refused while a child line exists" — history is preserved,
 * nothing cascades.
 */
function oiForeignKeyDeleteRules(string $table): array
{
    return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->mapWithKeys(fn ($row) => [$row->from => [$row->table, $row->on_delete]])
        ->all();
}

function oiSeedOrder(): array
{
    $store = Store::factory()->create();
    $product = app(ProductService::class)->create($store, 'Widget', '10.00', Currency::query()->where('code', 'EUR')->value('id'));
    $order = app(OrderCreationService::class)->create($store, [['product' => $product, 'quantity' => 2]]);

    return [$store, $product, $order];
}

it('has exactly the approved minimal columns — nothing ecommerce-generic added speculatively', function () {
    expect(Schema::getColumnListing('order_items'))->toBe([
        'id', 'order_id', 'product_id', 'product_name', 'unit_price_amount', 'currency_id', 'quantity', 'created_at', 'updated_at',
    ]);
});

it('declares RESTRICT on every order_items foreign key, pointing at the right parent (ORDER-ITEM-12)', function () {
    expect(oiForeignKeyDeleteRules('order_items'))->toEqual([
        'order_id' => ['orders', 'RESTRICT'],
        'product_id' => ['products', 'RESTRICT'],
        'currency_id' => ['currencies', 'RESTRICT'],
    ]);
});

it('does not weaken CROSS-14: orders.store_id and payments.order_id stay RESTRICT', function () {
    expect(oiForeignKeyDeleteRules('orders')['store_id'])->toBe(['stores', 'RESTRICT'])
        ->and(oiForeignKeyDeleteRules('payments')['order_id'])->toBe(['orders', 'RESTRICT']);
});

it('refuses to hard-delete a Product that an OrderItem references, at the database (ORDER-ITEM-13, upgrades CATALOG-09)', function () {
    [, $product] = oiSeedOrder();

    expect(fn () => DB::table('products')->where('id', $product->id)->delete())->toThrow(QueryException::class);

    expect(DB::table('products')->where('id', $product->id)->exists())->toBeTrue()
        ->and(DB::table('order_items')->where('product_id', $product->id)->count())->toBe(1);
});

it('still lets the database delete a Product NO OrderItem references — the backstop is per referenced row, not absolute', function () {
    $unreferenced = Product::factory()->create();

    DB::table('products')->where('id', $unreferenced->id)->delete();

    expect(DB::table('products')->where('id', $unreferenced->id)->exists())->toBeFalse();
});

it('refuses to hard-delete an Order that has OrderItems — lines are never cascaded away (ORDER-ITEM-12)', function () {
    [, , $order] = oiSeedOrder();

    expect(fn () => DB::table('orders')->where('id', $order->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => $order->delete())->toThrow(QueryException::class);

    expect(Order::find($order->id))->not->toBeNull()
        ->and(DB::table('order_items')->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses to delete a Currency that an OrderItem references', function () {
    oiSeedOrder();
    $eur = Currency::query()->where('code', 'EUR')->firstOrFail();

    expect(fn () => DB::table('currencies')->where('id', $eur->id)->delete())->toThrow(QueryException::class);
});

it('refuses to delete the Store of a line-backed Order (orders.store_id is RESTRICT)', function () {
    [$store] = oiSeedOrder();

    expect(fn () => DB::table('stores')->where('id', $store->id)->delete())->toThrow(QueryException::class);
});

it('soft-deleting the Product preserves the OrderItem and its snapshot (ORDER-ITEM-09)', function () {
    [, $product, $order] = oiSeedOrder();

    app(ProductService::class)->delete($product);

    $row = DB::table('order_items')->where('order_id', $order->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->product_name)->toBe('Widget')
        ->and($row->quantity)->toBe(2);
});

it('enforces quantity >= 1 as an integer in the database itself, not only in the service (ORDER-ITEM-04)', function (mixed $quantity) {
    [, $product, $order] = oiSeedOrder();

    $row = fn () => [
        'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'X',
        'unit_price_amount' => '1.00', 'currency_id' => $order->currency_id, 'quantity' => $quantity,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::table('order_items')->insert($row()))->toThrow(QueryException::class, 'quantity must be a positive integer');
})->with(['zero' => [0], 'negative' => [-3], 'fractional' => [1.5], 'text' => ['abc'], 'null' => [null]]);

it('also refuses to raw-update an existing line\'s quantity to an invalid value', function () {
    [, , $order] = oiSeedOrder();

    expect(fn () => DB::table('order_items')->where('order_id', $order->id)->update(['quantity' => 0]))
        ->toThrow(QueryException::class, 'quantity must be a positive integer');
});

it('requires every snapshot column (NOT NULL)', function (string $column) {
    [, $product, $order] = oiSeedOrder();

    $row = [
        'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'X',
        'unit_price_amount' => '1.00', 'currency_id' => $order->currency_id, 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];
    $row[$column] = null;

    expect(fn () => DB::table('order_items')->insert($row))->toThrow(QueryException::class);
})->with(['order_id', 'product_id', 'product_name', 'unit_price_amount', 'currency_id', 'quantity']);

it('rejects an OrderItem pointing at a nonexistent Order, Product or Currency (FKs are enforced)', function () {
    [, $product, $order] = oiSeedOrder();

    $base = [
        'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'X',
        'unit_price_amount' => '1.00', 'currency_id' => $order->currency_id, 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];

    foreach (['order_id', 'product_id', 'currency_id'] as $column) {
        expect(fn () => DB::table('order_items')->insert([...$base, $column => 999999]))->toThrow(QueryException::class);
    }
});
