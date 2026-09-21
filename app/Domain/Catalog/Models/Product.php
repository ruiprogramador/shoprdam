<?php

namespace App\Domain\Catalog\Models;

use App\Models\Store;
use App\Traits\HasActiveScope;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Nnjeim\World\Models\Currency;

/**
 * The minimum real, persisted sellable-unit identity in shoprdam — see
 * docs/catalog/CATALOG-DOMAIN.md. A Product belongs to exactly one Store,
 * has one exact price in one explicit currency, and a visibility flag
 * independent of any inventory concept (inventory does not exist yet).
 *
 * Every production business mutation goes through
 * App\Domain\Catalog\Services\ProductService — enforced mechanically by
 * tests/Architecture/CatalogDomainBoundaryTest, plus the two runtime guards
 * on this model below.
 *
 * `HasActiveScope` is a *local* scope only (`scopeActive()` — verified by
 * reading the trait and confirming no model anywhere registers it, or
 * anything else, as a global scope). `Product::find()`, `Product::all()`,
 * eager-loaded relations, and every other ordinary query still return
 * inactive Products by default — only an explicit `Product::active()` call
 * filters. Catalog visibility is therefore never mistaken for object
 * existence, which is the entire reason this trait was safe to reuse rather
 * than inventing a separate one: the previous three users of this trait
 * (OrderStatus, StoreStatus, TransactionCategory) are all small, seeded
 * lookup tables, not transactional business entities like this one — the
 * trait's own mechanics, not "what similar models do," is what justifies
 * reuse here.
 */
class Product extends Model
{
    use HasActiveScope, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'price_amount',
        'currency_id',
        'is_active',
    ];

    protected $casts = [
        'price_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Ownership immutability (CATALOG-02): any Eloquent save of an existing
     * Product that changes `store_id` throws. Guards `performUpdate()`, not
     * the `updating` model event — the same choice made for
     * App\Models\Order after that codebase's own event-based guard was
     * proven bypassable by `saveQuietly()`/`updateQuietly()`/
     * `Order::withoutEvents(...)`, all of which suppress events but still
     * go through `performUpdate()`. Creation is unaffected: `store_id` is
     * never "dirty" relative to a prior persisted value on a brand-new row.
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->exists && $this->isDirty('store_id')) {
            throw new LogicException(
                'Product.store_id is immutable once set — ownership can only be established at creation, '.
                'via App\Domain\Catalog\Services\ProductService\'s own create method. There is no product-transfer operation.'
            );
        }

        return parent::performUpdate($query);
    }

    /**
     * Hard deletion is deliberately blocked at the model level (§11 of the
     * design: "prefer fail-closed history semantics over convenient hard
     * deletion"). Nothing today has a foreign key into `products` to make
     * this impossible at the database level the way CROSS-14 protects
     * financial history — that backstop only arrives once `feat/order-items`
     * adds `order_items.product_id` (`restrictOnDelete()`). Until then, this
     * is the only thing standing between "soft delete is the norm" and
     * "someone quietly calls forceDelete() because nothing stops them."
     *
     * Known, stated limitation (CATALOG-09 — never overstated as a complete
     * guarantee): this blocks only the instance-level API
     * (`$product->forceDelete()`/`forceDeleteQuietly()`). A query-builder-level
     * mass force-delete (`Product::withTrashed()->where(...)->forceDelete()`)
     * bypasses the model instance entirely — closed instead by
     * tests/Architecture/CatalogDomainBoundaryTest's static scan, which
     * rejects every *known* shape of that pattern (and its raw-SQL/
     * DB::table('products') equivalents) found in production code today.
     * Neither layer is a database constraint: see
     * docs/catalog/CATALOG-DOMAIN.md §8/§12 for why CATALOG-09 is
     * PARTIALLY ENFORCED, not ENFORCED.
     */
    public function forceDelete(): ?bool
    {
        throw new LogicException(
            'Product cannot be hard-deleted. Soft deletion only — see docs/catalog/CATALOG-DOMAIN.md §11.'
        );
    }

    public function forceDeleteQuietly(): bool
    {
        throw new LogicException(
            'Product cannot be hard-deleted. Soft deletion only — see docs/catalog/CATALOG-DOMAIN.md §11.'
        );
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * HasFactory's own default namespace-guessing resolves a model outside
     * `App\Models` to `Database\Factories\Domain\Catalog\Models\...` (see
     * Illuminate\Database\Eloquent\Factories\Factory::resolveFactoryName) —
     * not this project's actual, flat `database/factories/` layout. Every
     * other domain model with a factory needs this same override; Product
     * is simply the first Domain model to have one.
     */
    protected static function newFactory(): Factory
    {
        return ProductFactory::new();
    }
}
