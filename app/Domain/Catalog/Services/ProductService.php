<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidProductPriceException;
use App\Domain\Catalog\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single canonical boundary through which Product business state
 * changes — see docs/catalog/CATALOG-DOMAIN.md. Nothing else in `app/` may
 * construct or mutate a Product's business fields (enforced mechanically by
 * tests/Architecture/CatalogDomainBoundaryTest, plus the two runtime guards
 * on App\Domain\Catalog\Models\Product itself).
 *
 * Deliberately no generic `update(array $anything)` — each method is a
 * named operation with its own, narrow contract, exactly mirroring
 * App\Domain\Orders\Services\OrderLifecycleService's own "no
 * setStatus($anything)" choice for the same reason: a generic setter would
 * make every invariant below merely advisory.
 *
 * Deliberately has no dependency on anything Wallet/Payment-shaped — this
 * class never creates a StoreWallet, mutates one, creates a
 * StoreWalletTransaction, or calls PaymentService/WalletTransactionService.
 * Product price is commercial intent, never proof of settlement
 * (CATALOG-05/06). It performs no external/network call and holds no
 * transaction open across one — the one multi-write operation here
 * (`create()`) is a single, short `DB::transaction()` around a single
 * `Eloquent::create()` call, kept only for symmetry with this codebase's
 * own convention of wrapping every domain-service write in an explicit
 * transaction boundary, not because Product creation is actually
 * multi-step.
 *
 * ## Concurrency — stated plainly, not overstated
 *
 * Product is not a financial aggregate. There is no row lock, no
 * compare-and-set, no version column here. Two concurrent `rename()` or
 * `changePrice()` calls on the same Product are ordinary last-write-wins —
 * whichever `UPDATE` commits last determines the stored value, and neither
 * caller is told the other one happened. This is a deliberate, documented
 * non-guarantee (docs/catalog/CATALOG-DOMAIN.md's Concurrency section), not
 * an oversight: nothing about "which price wins a race" is a correctness
 * invariant the way "which Order status wins" was for
 * OrderLifecycleService — there is no financial effect, no double-spend,
 * and no impossible state reachable from two administrative writes racing.
 * `activate()`/`deactivate()` re-check the current value before writing
 * only to keep their own idempotency semantics honest (§ below), never as a
 * concurrency guarantee.
 */
class ProductService
{
    /**
     * Establishes Store ownership, exact price, currency and initial
     * visibility atomically (CATALOG-12). `$store` must already be
     * persisted — a Product can never exist without a real owning Store.
     */
    public function create(Store $store, string $name, string $priceAmount, int $currencyId, bool $isActive = true): Product
    {
        if (! $store->exists) {
            throw new InvalidArgumentException('Cannot create a Product for a Store that has not been persisted yet.');
        }

        $name = $this->normalizeName($name);
        $normalizedPrice = $this->normalizePrice($priceAmount);

        return DB::transaction(fn () => Product::create([
            'store_id' => $store->getKey(),
            'name' => $name,
            'price_amount' => $normalizedPrice,
            'currency_id' => $currencyId,
            'is_active' => $isActive,
        ]));
    }

    /** Changes only the display name. Every other field is untouched. */
    public function rename(Product $product, string $name): Product
    {
        $product->update(['name' => $this->normalizeName($name)]);

        return $product->fresh();
    }

    /**
     * Changes price and currency together, as one atomic commercial fact —
     * deliberately not split into a separate `changeCurrency()`. A price
     * amount has no meaning without its currency (CATALOG-04); allowing the
     * two to be set independently would let a caller silently leave a
     * Product in a state where "10.00" quietly changed from meaning ten
     * euros to meaning ten dollars with no explicit decision that the price
     * itself changed. Requiring both together forecloses that ambiguity by
     * construction rather than by convention.
     */
    public function changePrice(Product $product, string $priceAmount, int $currencyId): Product
    {
        $product->update([
            'price_amount' => $this->normalizePrice($priceAmount),
            'currency_id' => $currencyId,
        ]);

        return $product->fresh();
    }

    /**
     * Idempotent: activating an already-active Product is a no-op write
     * (still safe to call, never throws), matching the idempotency
     * semantics §10 of the design calls for.
     */
    public function activate(Product $product): Product
    {
        if (! $product->is_active) {
            $product->update(['is_active' => true]);
        }

        return $product->fresh();
    }

    /** Idempotent, mirroring activate(). Deactivation is visibility only — see the model's own docblock. */
    public function deactivate(Product $product): Product
    {
        if ($product->is_active) {
            $product->update(['is_active' => false]);
        }

        return $product->fresh();
    }

    /**
     * Soft-deletes the Product. The row survives (SoftDeletes) — this never
     * hard-deletes, and App\Domain\Catalog\Models\Product's own
     * forceDelete() guard refuses to either, independent of this method.
     * Idempotent: soft-deleting an already-deleted Product is Eloquent's own
     * safe no-op (a second `delete()` call on a trashed model does nothing).
     */
    public function delete(Product $product): void
    {
        $product->delete();
    }

    private function normalizeName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Product name must not be empty.');
        }

        return $trimmed;
    }

    /**
     * The accepted representation: a plain decimal string, no sign, no
     * exponent, no thousands separator, no surrounding whitespace, at most
     * two decimal places — mirroring
     * App\Console\Commands\CreateTestStripeOrder's own money-input
     * validation exactly (`^\d+(?:\.\d{1,2})?$`), the only existing
     * precedent in this codebase for validating a raw decimal-money string
     * from a caller rather than trusting an already-settled financial
     * value. Never introduces a new Money value-object — there isn't one to
     * reuse, and this branch doesn't invent one.
     *
     * Deliberately never coerces or rounds a malformed value into something
     * "close enough" — every rejection throws before anything is written.
     *
     * Zero (`"0"`/`"0.00"`) is accepted. There is no repository evidence
     * either way on whether a free Product should exist or be sellable —
     * rejecting zero would silently invent that business rule with no more
     * justification than accepting it would. This is the narrowest honest
     * rule: reject only what is unambiguous (malformed input, negative
     * amounts), and leave "should a zero-priced Product be orderable" as
     * the open product decision it actually is (docs/catalog/CATALOG-DOMAIN.md).
     */
    private function normalizePrice(string $priceAmount): string
    {
        if (trim($priceAmount) !== $priceAmount || $priceAmount === '') {
            throw InvalidProductPriceException::malformed($priceAmount);
        }

        if (str_starts_with($priceAmount, '-')) {
            throw InvalidProductPriceException::negative($priceAmount);
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $priceAmount)) {
            throw InvalidProductPriceException::malformed($priceAmount);
        }

        return bcadd($priceAmount, '0', 2);
    }
}
