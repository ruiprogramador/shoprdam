<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Services\OrderCreationService;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Nnjeim\World\Models\Currency;

/**
 * Isolates a single operation this test deliberately expects the database
 * to refuse (a FK/unique/CHECK violation), inside its own savepoint when
 * already nested in a transaction (SAVEPOINT if $this->transactions >= 1,
 * a plain BEGIN/ROLLBACK otherwise — see
 * Illuminate\Database\Concerns\ManagesTransactions::createTransaction()).
 * On SQLite/MySQL/MariaDB a single failed statement never poisons the rest
 * of the surrounding transaction anyway, so this is a no-op there; on
 * PostgreSQL, without it, the expected QueryException would leave the
 * outer (e.g. RefreshDatabase's) transaction aborted — SQLSTATE 25P02 —
 * for every assertion the test makes afterward, even though the refusal
 * itself is correct and expected. The refused operation's own
 * QueryException still propagates unchanged; assertions made after calling
 * this run on a healthy connection either way.
 */
function expectDatabaseRefusal(Closure $operation): void
{
    expect(fn () => DB::transaction($operation))->toThrow(QueryException::class);
}

/**
 * Real, migrated-schema foreign-key info for one table, keyed by column
 * name — e.g. `['user_id' => ['table' => 'users', 'rule' => 'RESTRICT']]`.
 * Engine-aware: SQLite via `PRAGMA foreign_key_list` (its `on_delete` column
 * already reads `RESTRICT`/`SET NULL`/`CASCADE`/`NO ACTION`); MySQL/MariaDB
 * via `information_schema.key_column_usage` (whose `referenced_table_name`
 * is a first-party MySQL/MariaDB extension) joined to
 * `referential_constraints` (`delete_rule`); PostgreSQL via the same two
 * tables plus `constraint_column_usage` (its standard-SQL equivalent of the
 * referenced side, since PostgreSQL's own `key_column_usage` has no
 * `referenced_table_name` column) — verified against a real instance of all
 * three. Used by every `*SchemaDeletePolicyTest` so RESTRICT/SET NULL/CASCADE
 * policy is checked against the actual, real constraint on whichever engine
 * the suite is pointed at, not assumed portable from SQLite alone.
 */
function dbForeignKeyInfo(string $table): array
{
    if (DB::getDriverName() === 'sqlite') {
        return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
            ->mapWithKeys(fn ($row) => [$row->from => ['table' => $row->table, 'rule' => $row->on_delete]])
            ->all();
    }

    if (DB::getDriverName() === 'pgsql') {
        return collect(DB::select(
            'select kcu.column_name as col, rc.delete_rule as rule, ccu.table_name as ref_table '
            .'from information_schema.key_column_usage kcu '
            .'join information_schema.referential_constraints rc '
            .'  on rc.constraint_name = kcu.constraint_name and rc.constraint_schema = kcu.constraint_schema '
            .'join information_schema.constraint_column_usage ccu '
            .'  on ccu.constraint_name = rc.unique_constraint_name and ccu.constraint_schema = rc.unique_constraint_schema '
            .'where kcu.table_name = ?',
            [$table],
        ))->mapWithKeys(fn ($row) => [$row->col => ['table' => $row->ref_table, 'rule' => $row->rule]])->all();
    }

    // mysql / mariadb
    return collect(DB::select(
        'select kcu.column_name as col, rc.delete_rule as rule, kcu.referenced_table_name as ref_table '
        .'from information_schema.key_column_usage kcu '
        .'join information_schema.referential_constraints rc '
        .'  on rc.constraint_name = kcu.constraint_name and rc.constraint_schema = kcu.constraint_schema '
        .'where kcu.table_name = ? and kcu.table_schema = ?',
        [$table, DB::getDatabaseName()],
    ))->mapWithKeys(fn ($row) => [$row->col => ['table' => $row->ref_table, 'rule' => $row->rule]])->all();
}

/** Same as dbForeignKeyInfo(), but just the delete rule keyed by column. */
function dbForeignKeyDeleteRules(string $table): array
{
    return collect(dbForeignKeyInfo($table))->map(fn ($info) => $info['rule'])->all();
}

/**
 * Every UNIQUE (and PRIMARY KEY) column set actually enforced on the real,
 * migrated table — e.g. `[['id'], ['active_identity']]` for a table with a
 * single-column PK and a single-column unique index, or `[['a', 'b']]` for
 * a composite unique constraint. Engine-aware: SQLite via `PRAGMA
 * index_list`/`index_info`; MySQL/MariaDB via `information_schema.statistics`
 * (`non_unique = 0`, grouped by index, ordered by `seq_in_index`);
 * PostgreSQL via `information_schema.table_constraints` joined to
 * `key_column_usage` (`constraint_type` UNIQUE/PRIMARY KEY) — verified
 * against a real instance of all three.
 */
function dbUniqueColumnSets(string $table): array
{
    if (DB::getDriverName() === 'sqlite') {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->filter(fn ($index) => (bool) $index->unique)
            ->map(fn ($index) => collect(DB::select("PRAGMA index_info('{$index->name}')"))->pluck('name')->all())
            ->values()->all();
    }

    if (DB::getDriverName() === 'pgsql') {
        $rows = DB::select(
            'select tc.constraint_name as idx, kcu.column_name as col '
            .'from information_schema.table_constraints tc '
            .'join information_schema.key_column_usage kcu '
            .'  on kcu.constraint_name = tc.constraint_name and kcu.constraint_schema = tc.constraint_schema '
            ."where tc.table_name = ? and tc.constraint_type in ('UNIQUE', 'PRIMARY KEY') "
            .'order by tc.constraint_name, kcu.ordinal_position',
            [$table],
        );
    } else {
        // mysql / mariadb
        $rows = DB::select(
            'select index_name as idx, column_name as col '
            .'from information_schema.statistics '
            .'where table_schema = ? and table_name = ? and non_unique = 0 '
            .'order by index_name, seq_in_index',
            [DB::getDatabaseName(), $table],
        );
    }

    return collect($rows)->groupBy('idx')->map(fn ($group) => $group->pluck('col')->all())->values()->all();
}

function recordTransaction(
    string $category,
    string $amount,
    ?WalletTransactionReference $reference = null,
    array $options = []
): StoreWalletTransaction {
    return test()->service->record(test()->wallet, $category, $amount, $reference, $options);
}

function recordPendingTransaction(string $category, string $amount, array $options = []): StoreWalletTransaction
{
    return recordTransaction($category, $amount, options: ['status' => 'pending', ...$options]);
}

function walletService(): WalletTransactionService
{
    return app(WalletTransactionService::class);
}

/**
 * @return array{0: Store, 1: StoreWallet}
 */
function createStoreWithWallet(): array
{
    $store = Store::factory()->create();

    return [$store, $store->wallets()->first()];
}

function expectWalletUnchanged(StoreWallet $before): void
{
    $after = $before->fresh();

    expect($after->balance)
        ->toBe($before->balance)
        ->and($after->last_transaction_at)
        ->toEqual($before->last_transaction_at);
}

/**
 * Post a raw, Stripe-signed webhook payload to the stripe.webhook route,
 * the same way Stripe's servers would (no session, no CSRF token, a
 * Stripe-Signature header computed from the shared webhook secret).
 */
function postStripeWebhook(array $event, ?string $secret = null): TestResponse
{
    $secret ??= config('services.stripe.webhook_secret');
    $payload = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return test()->call(
        method: 'POST',
        uri: route('stripe.webhook'),
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $payload,
    );
}

function stripePaymentIntentEvent(string $type, string $paymentIntentId, array $overrides = []): array
{
    return [
        'id' => 'evt_'.str()->random(16),
        'object' => 'event',
        'type' => $type,
        'data' => [
            'object' => array_merge([
                'id' => $paymentIntentId,
                'object' => 'payment_intent',
                'amount' => 10000,
                'currency' => 'eur',
                'status' => match ($type) {
                    'payment_intent.succeeded' => 'succeeded',
                    'payment_intent.canceled' => 'canceled',
                    default => 'requires_payment_method',
                },
                'metadata' => [],
                'last_payment_error' => null,
            ], $overrides),
        ],
    ];
}

function stripeChargeRefundedEvent(string $chargeId, string $paymentIntentId, array $overrides = []): array
{
    return [
        'id' => 'evt_'.str()->random(16),
        'object' => 'event',
        'type' => 'charge.refunded',
        'data' => [
            'object' => array_merge([
                'id' => $chargeId,
                'object' => 'charge',
                'payment_intent' => $paymentIntentId,
                'amount_refunded' => 10000,
                'refunded' => true,
            ], $overrides),
        ],
    ];
}

/**
 * Post a raw EasyPay notification body to the easypay.webhook route. Unlike
 * Stripe, EasyPay publishes no signature to compute — its documented
 * security model is the receiving controller calling back the API using the
 * notification's own `id` (see EasyPayWebhookController), not verifying the
 * delivery itself.
 */
function postEasyPayWebhook(array $notification): TestResponse
{
    return test()->postJson(route('easypay.webhook'), $notification);
}

/** The generic {id, key, type, status, ...} shape every EasyPay notification carries. */
function easyPayNotification(string $type, string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'key' => 'merchant-key-'.str()->random(8),
        'type' => $type,
        'status' => 'success',
        'messages' => ['ok'],
        'date' => now()->toDateTimeString(),
    ], $overrides);
}

/** The shape EasyPayClient::retrieveSinglePayment()/createSinglePayment() return for a single payment resource. */
function easyPayPaymentBody(string $id, string $orderId, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'key' => $orderId,
        'status' => 'success',
        'method' => 'mbway',
        'value' => '42.50',
        'currency' => 'EUR',
    ], $overrides);
}

// ---------------------------------------------------------------------
// feat/inventory-reservations fixtures (docs/inventory/INVENTORY-RESERVATIONS.md).
// Stock is seeded with a raw insert: production code has NO way to create
// or set stock (no setStock), so a fixture is the only legitimate source.
// ---------------------------------------------------------------------

function inventoryProduct(Store $store, string $price = '10.00', string $name = 'Widget'): Product
{
    return app(ProductService::class)->create(
        $store,
        $name,
        $price,
        Currency::query()->where('code', 'EUR')->value('id'),
    );
}

/** Seeds one Inventory row for the Product and returns its id. */
function inventorySeed(Product|int $product, int $onHand, int $reserved = 0): int
{
    return DB::table('inventories')->insertGetId([
        'product_id' => $product instanceof Product ? $product->id : $product,
        'on_hand_quantity' => $onHand,
        'reserved_quantity' => $reserved,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{on_hand: int, reserved: int, available: int} straight from the database */
function inventoryState(Product|int $product): array
{
    $row = DB::table('inventories')
        ->where('product_id', $product instanceof Product ? $product->id : $product)
        ->first();

    return [
        'on_hand' => (int) $row->on_hand_quantity,
        'reserved' => (int) $row->reserved_quantity,
        'available' => (int) $row->on_hand_quantity - (int) $row->reserved_quantity,
    ];
}

/** @param  array<int, array{0: Product, 1: int}>  $pairs */
function inventoryOrder(Store $store, array $pairs): Order
{
    return app(OrderCreationService::class)->create(
        $store,
        array_map(fn ($pair) => ['product' => $pair[0], 'quantity' => $pair[1]], $pairs),
    );
}
