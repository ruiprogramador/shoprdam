<?php

use App\Domain\Catalog\Exceptions\InvalidProductPriceException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Currency;

/**
 * docs/catalog/CATALOG-DOMAIN.md — the canonical Product mutation boundary.
 * Every business mutation here goes through the real ProductService, exactly
 * as tests/Feature/Domain/Orders/OrderLifecycleServiceTest exercises the real
 * OrderLifecycleService rather than faking state.
 */
function pcCurrency(string $code = 'EUR'): Currency
{
    return Currency::query()->where('code', $code)->firstOrFail();
}

function pcStore(): Store
{
    return Store::factory()->create();
}

function pcProduct(?Store $store = null, string $price = '19.99'): Product
{
    return app(ProductService::class)->create(
        store: $store ?? pcStore(),
        name: 'Test Product',
        priceAmount: $price,
        currencyId: pcCurrency()->id,
    );
}

// ---------------------------------------------------------------------
// Creation (CATALOG-01, CATALOG-04)
// ---------------------------------------------------------------------

it('creates a Product owned by exactly one Store, with an explicit price and currency', function () {
    $store = pcStore();
    $eur = pcCurrency();

    $product = app(ProductService::class)->create($store, 'Widget', '19.99', $eur->id);

    expect($product->exists)->toBeTrue()
        ->and($product->store_id)->toBe($store->id)
        ->and($product->store->is($store))->toBeTrue()
        ->and($product->name)->toBe('Widget')
        ->and($product->price_amount)->toBe('19.99')
        ->and($product->currency_id)->toBe($eur->id)
        ->and($product->currency->is($eur))->toBeTrue()
        ->and($product->is_active)->toBeTrue()
        ->and($product->deleted_at)->toBeNull();
});

it('stores the exact price representation given, at 2 decimal places, never a float-rounded approximation', function (string $input, string $stored) {
    $product = pcProduct(price: $input);

    expect($product->fresh()->price_amount)->toBe($stored);
})->with([
    'whole number normalizes to 2 places' => ['20', '20.00'],
    'one decimal place normalizes' => ['5.5', '5.50'],
    'already 2 decimal places is preserved exactly' => ['19.99', '19.99'],
    'zero is accepted and preserved' => ['0', '0.00'],
    'zero with decimals is accepted' => ['0.00', '0.00'],
]);

it('creates with an explicit initial visibility when given', function () {
    $product = app(ProductService::class)->create(pcStore(), 'Widget', '10.00', pcCurrency()->id, isActive: false);

    expect($product->is_active)->toBeFalse();
});

it('refuses to create a Product for a Store that has not been persisted', function () {
    expect(fn () => app(ProductService::class)->create(new Store, 'Widget', '10.00', pcCurrency()->id))
        ->toThrow(InvalidArgumentException::class, 'has not been persisted');

    expect(Product::count())->toBe(0);
});

// ---------------------------------------------------------------------
// Price/currency correctness (CATALOG-05/06)
// ---------------------------------------------------------------------

it('rejects malformed price strings before writing anything', function (mixed $malformed) {
    expect(fn () => app(ProductService::class)->create(pcStore(), 'Widget', $malformed, pcCurrency()->id))
        ->toThrow(InvalidProductPriceException::class);

    expect(Product::count())->toBe(0);
})->with([
    'empty string' => [''],
    'whitespace only' => ['   '],
    'leading/trailing whitespace' => [' 10.00 '],
    'more than 2 decimal places' => ['10.999'],
    'scientific notation' => ['1e3'],
    'thousands separator' => ['1,000.00'],
    'trailing dot, no digits' => ['10.'],
    'non-numeric' => ['abc'],
    'plus sign' => ['+10.00'],
]);

it('rejects a negative price with a distinct exception', function () {
    expect(fn () => app(ProductService::class)->create(pcStore(), 'Widget', '-5.00', pcCurrency()->id))
        ->toThrow(InvalidProductPriceException::class, 'must not be negative');

    expect(Product::count())->toBe(0);
});

it('changePrice() changes price and currency together, atomically, as one commercial fact', function () {
    $product = pcProduct(price: '10.00');
    $usd = Currency::query()->where('code', '!=', 'EUR')->first() ?? Currency::factory()->create(['code' => 'USD']);

    $result = app(ProductService::class)->changePrice($product, '25.50', $usd->id);

    expect($result->price_amount)->toBe('25.50')
        ->and($result->currency_id)->toBe($usd->id)
        ->and($product->fresh()->price_amount)->toBe('25.50')
        ->and($product->fresh()->currency_id)->toBe($usd->id);
});

it('changePrice() validates the new price exactly as create() does, and writes nothing on rejection', function () {
    $product = pcProduct(price: '10.00');
    $before = $product->fresh();

    expect(fn () => app(ProductService::class)->changePrice($product, '-1.00', pcCurrency()->id))
        ->toThrow(InvalidProductPriceException::class);

    expect($product->fresh()->price_amount)->toBe($before->price_amount)
        ->and($product->fresh()->currency_id)->toBe($before->currency_id);
});

// ---------------------------------------------------------------------
// Ownership immutability (CATALOG-02) — every Eloquent route, mirroring
// OrderLifecycleServiceTest's own ORDER-01 runtime-guard matrix.
// ---------------------------------------------------------------------

it('rejects EVERY Eloquent route to changing store_id on an existing Product', function (Closure $mutate) {
    $product = pcProduct();
    $otherStore = pcStore();

    expect(fn () => $mutate(Product::find($product->id), $otherStore))->toThrow(LogicException::class, 'immutable');

    expect($product->fresh()->store_id)->toBe($product->store_id);
})->with([
    'update()' => [fn (Product $p, Store $s) => $p->update(['store_id' => $s->id])],
    'forceFill()->save()' => [fn (Product $p, Store $s) => $p->forceFill(['store_id' => $s->id])->save()],
    'fill()->save()' => [fn (Product $p, Store $s) => $p->fill(['store_id' => $s->id])->save()],
    'attribute assignment + save()' => [function (Product $p, Store $s) {
        $p->store_id = $s->id;
        $p->save();
    }],
    'saveQuietly()' => [function (Product $p, Store $s) {
        $p->store_id = $s->id;
        $p->saveQuietly();
    }],
    'updateQuietly()' => [fn (Product $p, Store $s) => $p->updateQuietly(['store_id' => $s->id])],
    'Product::withoutEvents(update())' => [fn (Product $p, Store $s) => Product::withoutEvents(fn () => $p->update(['store_id' => $s->id]))],
    'store()->associate()->save()' => [function (Product $p, Store $s) {
        $p->store()->associate($s);
        $p->save();
    }],
]);

it('refuses a mixed update atomically: the ownership guard throws before ANY column is written', function () {
    $product = pcProduct();
    $otherStore = pcStore();

    expect(fn () => $product->update(['name' => 'Renamed', 'store_id' => $otherStore->id]))
        ->toThrow(LogicException::class);

    expect($product->fresh()->name)->not->toBe('Renamed')
        ->and($product->fresh()->store_id)->not->toBe($otherStore->id);
});

it('does not treat store_id as dirty on creation — the guard never fires for a brand-new row', function () {
    expect(fn () => pcProduct())->not->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------
// Rename, activate/deactivate idempotency (CATALOG-07)
// ---------------------------------------------------------------------

it('rename() changes only the name', function () {
    $product = pcProduct();
    $before = $product->fresh();

    $result = app(ProductService::class)->rename($product, '  New Name  ');

    expect($result->name)->toBe('New Name')
        ->and($result->price_amount)->toBe($before->price_amount)
        ->and($result->currency_id)->toBe($before->currency_id)
        ->and($result->store_id)->toBe($before->store_id);
});

it('rename() rejects an empty name', function (string $blank) {
    $product = pcProduct();

    expect(fn () => app(ProductService::class)->rename($product, $blank))
        ->toThrow(InvalidArgumentException::class, 'must not be empty');
})->with(['', '   ']);

it('activate() and deactivate() are idempotent no-ops when already in the target state', function () {
    $service = app(ProductService::class);
    $product = pcProduct();
    expect($product->is_active)->toBeTrue();

    $service->activate($product);
    expect($product->fresh()->is_active)->toBeTrue();

    $service->deactivate($product);
    expect($product->fresh()->is_active)->toBeFalse();

    $service->deactivate($product);
    expect($product->fresh()->is_active)->toBeFalse();

    $service->activate($product);
    expect($product->fresh()->is_active)->toBeTrue();
});

// ---------------------------------------------------------------------
// HasActiveScope: local scope only, never a global one (CATALOG-07)
// ---------------------------------------------------------------------

it('never hides an inactive Product from ordinary queries — only an explicit active() call filters', function () {
    $service = app(ProductService::class);
    $active = pcProduct();
    $inactive = pcProduct();
    $service->deactivate($inactive);

    expect(Product::find($inactive->id))->not->toBeNull()
        ->and(Product::all()->pluck('id'))->toContain($inactive->id)
        ->and(Product::active()->pluck('id'))->toContain($active->id)
        ->and(Product::active()->pluck('id'))->not->toContain($inactive->id);
});

// ---------------------------------------------------------------------
// Soft delete only (CATALOG-08, §11)
// ---------------------------------------------------------------------

it('delete() soft-deletes: the row survives and is reachable only via an explicit trashed query', function () {
    $product = pcProduct();

    app(ProductService::class)->delete($product);

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull()
        ->and(Product::onlyTrashed()->find($product->id))->not->toBeNull()
        // fresh() uses newQueryWithoutScopes(), so it already bypasses the
        // SoftDeletingScope global scope and finds the trashed row.
        ->and($product->fresh()->deleted_at)->not->toBeNull();
});

it('delete() is idempotent on an already-deleted Product', function () {
    $product = pcProduct();
    $service = app(ProductService::class);

    $service->delete($product);
    $deletedAt = $product->fresh()->deleted_at;

    $service->delete($product->fresh());

    expect($product->fresh()->deleted_at->equalTo($deletedAt))->toBeTrue();
});

it('refuses to hard-delete a Product through either instance-level API', function () {
    $product = pcProduct();

    expect(fn () => $product->forceDelete())->toThrow(LogicException::class, 'cannot be hard-deleted');
    expect(fn () => $product->forceDeleteQuietly())->toThrow(LogicException::class, 'cannot be hard-deleted');

    expect(DB::table('products')->where('id', $product->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------
// No Wallet/Payment mutation (CATALOG-05/06)
// ---------------------------------------------------------------------

it('never mutates the Wallet or creates any financial row, for any Product operation', function () {
    $store = pcStore();
    $wallet = app(WalletService::class)->getOrCreateWallet($store, 'EUR');
    $balance = $wallet->fresh()->balance;
    $transactions = StoreWalletTransaction::count();

    $service = app(ProductService::class);
    $product = $service->create($store, 'Widget', '10.00', pcCurrency()->id);
    $service->rename($product, 'Renamed');
    $service->changePrice($product, '20.00', pcCurrency()->id);
    $service->deactivate($product);
    $service->delete($product);

    expect($wallet->fresh()->balance)->toBe($balance)
        ->and(StoreWalletTransaction::count())->toBe($transactions);
});
