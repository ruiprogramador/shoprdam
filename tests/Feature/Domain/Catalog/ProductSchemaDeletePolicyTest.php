<?php

use App\Domain\Catalog\Models\Product;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Currency;

/**
 * Mirrors tests/Feature/Domain/Payouts/PayoutSchemaDeletePolicyTest — checks
 * the *actual* constraint SQLite enforces, read straight from the migrated
 * schema via `PRAGMA foreign_key_list`, rather than trusting the migration's
 * source text. A Feature test (not Architecture) because it needs a real
 * migrated database.
 */
function catalogForeignKeyDeleteRules(string $table): array
{
    return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->mapWithKeys(fn ($row) => [$row->from => $row->on_delete])
        ->all();
}

it('enforces RESTRICT at the database level for every foreign key on products', function () {
    expect(catalogForeignKeyDeleteRules('products'))->toMatchArray([
        'store_id' => 'RESTRICT',
        'currency_id' => 'RESTRICT',
    ]);
});

it('never lets any products foreign key use CASCADE at the database level', function () {
    $offenders = [];

    foreach (catalogForeignKeyDeleteRules('products') as $column => $rule) {
        if ($rule === 'CASCADE') {
            $offenders[] = "products.{$column}";
        }
    }

    expect($offenders)->toBe([]);
});

it('proves the Store FK is enforced: deleting a Store with a Product is refused, and the Product row survives', function () {
    $store = Store::factory()->create();
    $product = Product::factory()->forStore($store)->create();

    expect(fn () => DB::table('stores')->where('id', $store->id)->delete())
        ->toThrow(QueryException::class);

    expect(Product::find($product->id))->not->toBeNull()
        ->and(Store::find($store->id))->not->toBeNull();
});

it('proves the Currency FK is enforced: deleting a Currency with a Product is refused', function () {
    $currency = Currency::query()->where('code', 'EUR')->firstOrFail();
    Product::factory()->create(['currency_id' => $currency->id]);

    expect(fn () => DB::table('currencies')->where('id', $currency->id)->delete())
        ->toThrow(QueryException::class);
});

it('soft-deleting a Product preserves the row and its foreign keys intact', function () {
    $product = Product::factory()->create();

    $product->delete();

    $row = DB::table('products')->where('id', $product->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        ->and($row->store_id)->toBe($product->store_id)
        ->and($row->currency_id)->toBe($product->currency_id);
});
