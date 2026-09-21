<?php

namespace App\Domain\Orders\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Orders\Exceptions\InvalidOrderCreationException;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * The single canonical boundary through which a line-backed Order comes into
 * existence — see docs/orders/ORDER-ITEMS.md. It receives a Store and the
 * Products/quantities being bought, and *derives* everything commercial
 * itself: the caller can supply neither a product name, a unit price, a
 * currency, a line total nor `Order.amount`.
 *
 * ## What one call does (one transaction, no external calls)
 *
 * 1. validate and normalize the request (pure, no database);
 * 2. read every requested Product in ONE query;
 * 3. per Product: exists, not soft-deleted, active, same Store as the Order,
 *    well-formed stored name/price/currency;
 * 4. one currency across all Products — never converted, mixed is refused;
 * 5. exact BCMath line totals and Order total, overflow-checked; the total
 *    must be strictly positive (no free-order settlement policy exists);
 * 6. insert the Order (existing initial `pending` state, persistently marked
 *    `is_line_backed`) and every OrderItem.
 *
 * Any failure before or during step 6 leaves no Order and no OrderItem.
 *
 * ## Duplicate Products
 *
 * The same Product supplied more than once is merged into one line with the
 * summed quantity, and lines are written in ascending Product-id order — so
 * the result is identical for any permutation/splitting of the same request.
 *
 * ## Snapshot consistency — stated plainly
 *
 * A Product's name, price, currency, store and active flag are all read from
 * the same row by the same single SELECT, so a snapshot can never be "torn"
 * (old price + new currency) by a concurrent ProductService write: the
 * database returns one committed version of the row. No row lock is taken —
 * a Product edited after that read but before this transaction commits is
 * indistinguishable from the order having been placed just before the edit,
 * and a lock would only add contention (and is a no-op on SQLite anyway). No
 * cross-Product consistency is claimed: Products are independent rows, and no
 * invariant relates one Product's state to another's.
 *
 * ## Deliberately not here
 *
 * No Payment is created and no provider is contacted (payment starts
 * separately via PaymentService, from the Order's own amount). No stock check
 * (Inventory does not exist). No discounts/tax/shipping. No cart, no update
 * or append-line operation: after this call the lines are historical facts.
 * No Store status/soft-delete check either — the repository defines no rule
 * for "a Store may sell".
 */
class OrderCreationService
{
    /** Portable signed 32-bit ceiling (PostgreSQL `integer`); a single line's quantity. */
    public const MAX_QUANTITY = 2147483647;

    /** `decimal(18,2)` — the largest amount `orders.amount` can hold. */
    private const MAX_AMOUNT = '9999999999999999.99';

    /**
     * @param  array<int|string, array{product: Product|int, quantity: int}>  $lines
     *                                                                                `product` contributes only its primary key — its in-memory attributes
     *                                                                                are never trusted or read; the row is re-read inside the transaction.
     *
     * @throws InvalidOrderCreationException
     */
    public function create(Store $store, array $lines): Order
    {
        if (! $store->exists) {
            throw InvalidOrderCreationException::storeNotPersisted();
        }

        $requested = $this->normalizeRequest($lines);

        return DB::transaction(function () use ($store, $requested) {
            ['snapshots' => $snapshots, 'currencyId' => $currencyId, 'total' => $total] = $this->snapshot($store, $requested);

            // forceCreate: `is_line_backed` is deliberately not mass-assignable
            // — this is the only place that ever writes it as true, in the
            // same transaction as the lines.
            $order = Order::forceCreate([
                'store_id' => $store->getKey(),
                'currency_id' => $currencyId,
                'order_status_id' => OrderStatus::bySlugOrFail('pending')->id,
                'amount' => $total,
                'is_line_backed' => true,
            ]);

            // One multi-row INSERT through the query builder: OrderItem's own
            // model guards refuse every instance write, by design.
            $now = now();

            DB::table('order_items')->insert(array_map(fn (array $s) => [
                'order_id' => $order->getKey(),
                'product_id' => $s['product_id'],
                'product_name' => $s['product_name'],
                'unit_price_amount' => $s['unit_price_amount'],
                'currency_id' => $s['currency_id'],
                'quantity' => $s['quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $snapshots));

            return $order->load('items');
        });
    }

    /**
     * Pure validation + duplicate merging; touches no database.
     *
     * @return array<int, int> product id => total quantity, ascending by id
     */
    private function normalizeRequest(array $lines): array
    {
        if ($lines === []) {
            throw InvalidOrderCreationException::noLines();
        }

        $requested = [];

        foreach ($lines as $index => $line) {
            // Exactly these two keys. A caller-supplied `unit_price`, `name`,
            // `amount`, ... is refused outright rather than silently ignored,
            // so nobody can believe they set a commercial value.
            if (! is_array($line) || array_keys($line) !== ['product', 'quantity'] && array_keys($line) !== ['quantity', 'product']) {
                throw InvalidOrderCreationException::malformedLine($index, "expected exactly ['product' => Product|int, 'quantity' => int].");
            }

            $product = $line['product'];
            $productId = $product instanceof Product ? $product->getKey() : $product;

            if (! is_int($productId) || $productId < 1) {
                throw InvalidOrderCreationException::malformedLine($index, 'product must be a persisted Product or a positive integer id.');
            }

            $quantity = $line['quantity'];

            // Strictly an int: a float (even 2.0), a numeric string or a bool is refused, never coerced.
            if (! is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw InvalidOrderCreationException::invalidQuantity($index, $quantity);
            }

            $merged = ($requested[$productId] ?? 0) + $quantity;

            if ($merged > self::MAX_QUANTITY) {
                throw InvalidOrderCreationException::quantityOverflow($productId);
            }

            $requested[$productId] = $merged;
        }

        ksort($requested);

        return $requested;
    }

    /**
     * @param  array<int, int>  $requested
     * @return array{snapshots: list<array<string, mixed>>, currencyId: int, total: string}
     */
    private function snapshot(Store $store, array $requested): array
    {
        // The ONLY read of `products` in this operation: every field of every
        // Product below comes from this one result set.
        $products = Product::query()->withTrashed()->whereKey(array_keys($requested))->get()->keyBy(fn (Product $p) => (int) $p->getKey());

        $snapshots = [];
        $currencyId = null;
        $total = '0.00';

        foreach ($requested as $productId => $quantity) {
            $product = $products->get($productId) ?? throw InvalidOrderCreationException::productNotFound($productId);

            if ($product->trashed()) {
                throw InvalidOrderCreationException::productDeleted($productId);
            }

            if (! $product->is_active) {
                throw InvalidOrderCreationException::productInactive($productId);
            }

            if ((int) $product->getRawOriginal('store_id') !== (int) $store->getKey()) {
                throw InvalidOrderCreationException::productWrongStore($productId, (int) $store->getKey());
            }

            $name = $product->getRawOriginal('name');

            if (! is_string($name) || trim($name) === '') {
                throw InvalidOrderCreationException::malformedProduct($productId, 'name is empty.');
            }

            $productCurrencyId = (int) $product->getRawOriginal('currency_id');

            if ($currencyId !== null && $currencyId !== $productCurrencyId) {
                throw InvalidOrderCreationException::mixedCurrencies();
            }

            $currencyId = $productCurrencyId;

            $unitPrice = $this->exactStoredPrice($productId, $product->getRawOriginal('price_amount'));

            $total = bcadd($total, bcmul($unitPrice, (string) $quantity, 2), 2);

            if (bccomp($total, self::MAX_AMOUNT, 2) > 0) {
                throw InvalidOrderCreationException::totalOverflow();
            }

            $snapshots[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'unit_price_amount' => $unitPrice,
                'currency_id' => $productCurrencyId,
                'quantity' => $quantity,
            ];
        }

        // The invariant is on the ORDER's total, not on each Product: a
        // zero-priced Product may sit beside a positive line. There is no
        // free-order settlement policy, so a canonical Order that the payment
        // pipeline could never settle is refused here, before anything is written.
        if (bccomp($total, '0.00', 2) <= 0) {
            throw InvalidOrderCreationException::nonPositiveTotal();
        }

        return ['snapshots' => $snapshots, 'currencyId' => $currencyId, 'total' => $total];
    }

    /**
     * Reads the Product's stored price from its RAW column value, never the
     * `decimal:2` cast — the cast would silently round a malformed value
     * (10.005 → 10.01) or throw a low-level error, and the snapshot must
     * never quietly disagree with what is stored.
     *
     * MySQL/PostgreSQL hand back a decimal string; SQLite (this repo's dev and
     * test engine) hands back a native int/float for a numeric-looking value.
     * A float is turned into its shortest round-trip text (var_export, not
     * `(string)`, which would round to 14 digits) and then held to the very
     * same strict two-decimal grammar ProductService::normalizePrice()
     * enforces on write — so an out-of-grammar stored value (negative, three
     * decimals, exponent form, non-numeric) is refused, never repaired.
     */
    private function exactStoredPrice(int $productId, mixed $raw): string
    {
        $text = match (true) {
            is_string($raw) => $raw,
            is_int($raw) => (string) $raw,
            is_float($raw) => var_export($raw, true),
            default => throw InvalidOrderCreationException::malformedProduct($productId, 'price is not a number.'),
        };

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw InvalidOrderCreationException::malformedProduct($productId, "stored price '{$text}' is not a non-negative decimal with at most 2 decimal places.");
        }

        return bcadd($text, '0', 2);
    }
}
