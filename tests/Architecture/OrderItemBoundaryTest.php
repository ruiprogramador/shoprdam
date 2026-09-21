<?php

/**
 * Mechanical enforcement of the OrderItem boundary (docs/orders/ORDER-ITEMS.md,
 * ORDER-ITEM-XX). Plain source scans, mirroring CatalogDomainBoundaryTest and
 * OrderLifecycleBoundaryTest — comments stripped first, exact path allowlists,
 * pure detector functions with synthetic self-tests.
 *
 * ## What is scanned
 *
 * "Production code" = PHP under app/, routes/, bootstrap/ (except the
 * generated bootstrap/cache), config/ and database/seeders/. Migrations,
 * factories and tests are OUTSIDE the boundary: they place fixtures in a
 * precondition state, which is their job. (There is deliberately no
 * OrderItemFactory — the model refuses instance inserts.)
 *
 * ## What these scans PROVE
 *
 * In the tree as written today: only OrderCreationService writes
 * `order_items`; no production code writes OrderItem through a static
 * persistence call, `new OrderItem`, an `OrderItem::` query chain, or an
 * `->items()->` relation write; only the canonical service and the one legacy
 * dev tool create Orders; the Payments/Wallet/Payout side never reads Product
 * or OrderItem; the OrderItem files contain none of the fields/concepts this
 * branch excluded; the migration has no CASCADE/soft-delete/unique(order,
 * product).
 *
 * ## What they do NOT prove
 *
 * A table or class name assembled at runtime, or raw SQL built from parts,
 * evades a text scan. This is not a database permission system: nothing here
 * stops `psql`/tinker/a migration from rewriting `order_items`. The runtime
 * guards on OrderItem/Order (tested in OrderItemImmutabilityTest) cover every
 * Eloquent instance write; a write that never touches a model instance is
 * covered only by these scans and, for financial effect, by the payment-time
 * drift check (OrderLineIntegrityChecker).
 */
function orderItemBoundaryStrip(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/** @return list<string> */
function orderItemBoundaryProductionRoots(): array
{
    return ['app', 'routes', 'bootstrap', 'config', 'database/seeders'];
}

/** @return array<string, string> repo-relative path => comment-stripped code */
function orderItemBoundaryLoad(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if (str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $files[$relative] = orderItemBoundaryStrip(file_get_contents($file->getPathname()));
        }
    }

    return $files;
}

/**
 * Any query-builder / raw-SQL touch of the `order_items` table outside the
 * allowlist — DB::table('order_items') (any verb, including reads: the table
 * has exactly one legitimate raw writer), and raw INSERT/UPDATE/DELETE/
 * TRUNCATE/DROP SQL naming it.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function orderItemBoundaryRawTableOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/table\(\s*[\'"]order_items[\'"]\s*\)/', $code)
            || preg_match('/\b(insert\s+(or\s+\w+\s+)?into|update|delete\s+from|truncate(\s+table)?|drop\s+table(\s+if\s+exists)?)\s+[`"\[\\\\]{0,2}order_items\b/i', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Every eloquent-shaped way to write an OrderItem: static persistence calls,
 * `new OrderItem`, any mass update/delete/insert reached off an `OrderItem::`
 * chain, and any write through the Order's `items()` relation. No allowlist:
 * nothing legitimate does this (the model's guards would refuse it anyway).
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function orderItemBoundaryEloquentWriteOffenders(array $files): array
{
    $verbs = 'create|createMany|forceCreate|firstOrCreate|updateOrCreate|save|saveMany|insert|insertOrIgnore|insertGetId|upsert|update|updateOrInsert|delete|forceDelete|destroy|truncate|increment|decrement|touch';

    $patterns = [
        '/\bOrderItem::\s*(create|forceCreate|updateOrCreate|firstOrCreate|insert|insertOrIgnore|upsert|destroy|truncate|unguard|withoutEvents)\s*\(/',
        '/\bnew\s+OrderItem\b/',
        '/\bOrderItem::[^;{}]*?->\s*('.$verbs.')\s*\(/',
        '/->\s*items\s*\(\s*\)\s*->\s*('.$verbs.')\s*\(/',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code)) {
                $offenders[] = $path;

                continue 2;
            }
        }
    }

    return $offenders;
}

/**
 * Production code that creates an Order row directly (static creation entry
 * points or `new Order`), outside the exact allowlist: the canonical service
 * and the one legacy dev tool.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function orderItemBoundaryOrderCreatorOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/\bOrder::\s*(create|forceCreate|updateOrCreate|firstOrCreate|insert|insertOrIgnore|upsert|factory)\s*\(/', $code)
            || preg_match('/\bnew\s+Order\s*[\(;]/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * The settlement side (Payments, Wallet, Payouts) must never read current
 * Product state or line snapshots (ORDER-ITEM-14): the only permitted Orders
 * dependency is the read-only integrity gate.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path references label"
 */
function orderItemBoundarySettlementOffenders(array $files): array
{
    $roots = ['app/Domain/Payments/', 'app/Domain/Payouts/', 'app/Domain/Wallet/', 'app/Services/Wallet/'];

    $forbidden = [
        'App\Domain\Catalog' => '/App\\\\Domain\\\\Catalog/',
        'Product' => '/\bProduct\b/',
        'price_amount' => '/price_amount/',
        'OrderItem' => '/\bOrderItem\b/',
        'order_items' => '/order_items/',
        'OrderCreationService' => '/OrderCreationService/',
        '->items' => '/->\s*items\b/',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        if (! collect($roots)->contains(fn ($root) => str_starts_with($path, $root))) {
            continue;
        }

        foreach ($forbidden as $label => $pattern) {
            if (preg_match($pattern, $code)) {
                $offenders[] = "{$path} references {$label}";
            }
        }
    }

    return $offenders;
}

/**
 * Only the creation service may know the Catalog: the rest of the Orders
 * domain (lifecycle, integrity gate) must never read a Product.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function orderItemBoundaryOrdersDomainCatalogOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (! str_starts_with($path, 'app/Domain/Orders/') || in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/App\\\\Domain\\\\Catalog|\bProduct\b|price_amount/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/** @return list<string> the files that define the OrderItem domain itself */
function orderItemBoundaryDomainFiles(): array
{
    return [
        'app/Models/OrderItem.php',
        'app/Domain/Orders/Services/OrderCreationService.php',
        'app/Domain/Orders/Services/OrderLineIntegrityChecker.php',
    ];
}

/**
 * Concepts this branch explicitly excluded, scanned across every file that
 * defines the OrderItem domain.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path contains forbidden"
 */
function orderItemBoundaryForbiddenFieldOffenders(array $files): array
{
    $scanned = array_filter($files, fn ($path) => str_contains($path, 'create_order_items_table')
        || in_array($path, orderItemBoundaryDomainFiles(), true), ARRAY_FILTER_USE_KEY);

    $forbidden = [
        'sku', 'variant', 'inventory', 'stock', 'reserved', 'reservation', 'fulfil', 'fulfill', 'fulfillment',
        'discount', 'coupon', 'promotion', 'tax', 'vat', 'shipping', 'supplier', 'vendor', 'slug',
        'line_total', 'line_total_amount', 'metadata', 'wishlist', 'expires_at',
    ];

    $offenders = [];

    foreach ($scanned as $path => $code) {
        foreach ($forbidden as $needle) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $code)) {
                $offenders[] = "{$path} contains '{$needle}'";
            }
        }
    }

    return $offenders;
}

/**
 * OrderItem files must not depend on Wallet/Payment/Payout machinery.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function orderItemBoundaryWalletDependencyOffenders(array $files): array
{
    $scanned = array_filter($files, fn ($path) => in_array($path, orderItemBoundaryDomainFiles(), true), ARRAY_FILTER_USE_KEY);

    $forbidden = ['App\\Domain\\Payments', 'App\\Domain\\Payouts', 'WalletTransactionService', 'WalletService', 'StoreWallet', 'PaymentService', 'PaymentAttempt', '->record(', '->confirm(', '->reverse('];

    $offenders = [];

    foreach ($scanned as $path => $code) {
        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = "{$path} references {$needle}";
            }
        }
    }

    return $offenders;
}

/**
 * `is_line_backed` is persistent provenance (ORDER-ITEM-19): only the Order
 * model (cast + immutability guard), the creation service (the one writer of
 * `true`) and the integrity checker (the one reader that acts on it) may
 * mention it in production code. Anything else reading or writing it —
 * including the legacy dev tool, which must stay legacy — is an offender.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function orderItemBoundaryProvenanceOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (! in_array($path, $allowed, true) && str_contains($code, 'is_line_backed')) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

// ---------------------------------------------------------------------
// Real scans of the real tree
// ---------------------------------------------------------------------

it('lets only Order, OrderCreationService and OrderLineIntegrityChecker mention the persistent is_line_backed provenance (ORDER-ITEM-19)', function () {
    $allowed = [
        'app/Models/Order.php',
        'app/Domain/Orders/Services/OrderCreationService.php',
        'app/Domain/Orders/Services/OrderLineIntegrityChecker.php',
    ];

    expect(orderItemBoundaryProvenanceOffenders(orderItemBoundaryLoad(orderItemBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('keeps the integrity checker deciding on persistent provenance, never on line existence alone (ORDER-ITEM-19)', function () {
    $code = orderItemBoundaryLoad(['app/Domain/Orders/Services'])['app/Domain/Orders/Services/OrderLineIntegrityChecker.php'];

    expect($code)->toContain('is_line_backed')
        ->and($code)->toContain('lineBackedWithoutLines')
        ->and($code)->toContain('legacyWithLines')
        ->and($code)->not->toMatch('/items\(\)\s*->\s*exists\(/');
});

it('keeps the canonical creation service the only writer of is_line_backed = true, and rejects a non-positive total (ORDER-ITEM-19, ORDER-ITEM-20)', function () {
    $code = orderItemBoundaryLoad(['app/Domain/Orders/Services'])['app/Domain/Orders/Services/OrderCreationService.php'];

    expect(substr_count($code, "'is_line_backed' => true"))->toBe(1)
        ->and($code)->not->toContain("'is_line_backed' => false")
        ->and($code)->toContain('nonPositiveTotal');
});

it('keeps is_line_backed out of Order::$fillable and the migration NOT NULL DEFAULT false', function () {
    $order = orderItemBoundaryLoad(['app/Models'])['app/Models/Order.php'];
    preg_match('/protected \$fillable\s*=\s*\[(.*?)\];/s', $order, $m);

    expect($m[1] ?? '')->not->toContain('is_line_backed');

    $migration = collect(orderItemBoundaryLoad(['database/migrations']))->first(fn ($code, $path) => str_contains($path, 'add_is_line_backed_to_orders_table'));

    expect($migration)->not->toBeNull()
        ->and(preg_replace('/\s+/', '', $migration))->toContain("boolean('is_line_backed')->default(false)")
        ->and($migration)->not->toContain('nullable')
        ->and($migration)->not->toMatch('/DB::|->update\(|->insert\(/');
});

it('lets only OrderCreationService touch the order_items table through the query builder or raw SQL (ORDER-ITEM-18)', function () {
    $allowed = ['app/Domain/Orders/Services/OrderCreationService.php'];

    expect(orderItemBoundaryRawTableOffenders(orderItemBoundaryLoad(orderItemBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('lets no production code write an OrderItem through Eloquent, an OrderItem:: chain or the items() relation (ORDER-ITEM-03, ORDER-ITEM-18)', function () {
    expect(orderItemBoundaryEloquentWriteOffenders(orderItemBoundaryLoad(orderItemBoundaryProductionRoots())))->toBe([]);
});

it('lets only the canonical creation service and the legacy dev tool create Orders in production code (ORDER-ITEM-10)', function () {
    $allowed = [
        'app/Domain/Orders/Services/OrderCreationService.php', // canonical, line-backed
        'app/Console/Commands/CreateTestStripeOrder.php',      // legacy, line-less, dev/test-mode only
    ];

    expect(orderItemBoundaryOrderCreatorOffenders(orderItemBoundaryLoad(orderItemBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('keeps the legacy dev tool explicitly a legacy line-less producer: it does not use the canonical service or invent OrderItems', function () {
    $code = orderItemBoundaryLoad(['app/Console/Commands'])['app/Console/Commands/CreateTestStripeOrder.php'];

    expect($code)->not->toContain('OrderCreationService')
        ->and($code)->not->toContain('OrderItem')
        ->and($code)->not->toContain('order_items');
});

it('never lets the Payments/Wallet/Payout side read Product, price_amount, OrderItem or order_items (ORDER-ITEM-14)', function () {
    expect(orderItemBoundarySettlementOffenders(orderItemBoundaryLoad(['app'])))->toBe([]);
});

it('lets only the creation service (and its own exception, whose messages name Products) in the Orders domain know the Catalog', function () {
    $allowed = [
        'app/Domain/Orders/Services/OrderCreationService.php',
        'app/Domain/Orders/Exceptions/InvalidOrderCreationException.php',
    ];

    expect(orderItemBoundaryOrdersDomainCatalogOffenders(orderItemBoundaryLoad(['app']), $allowed))->toBe([]);
});

it('never introduces an excluded field/concept into the OrderItem domain (sku, variant, inventory, discount, tax, shipping, line_total, metadata, ...)', function () {
    expect(orderItemBoundaryForbiddenFieldOffenders(orderItemBoundaryLoad(['app', 'database/migrations'])))->toBe([]);
});

it('keeps the OrderItem domain free of Wallet/Payment/Payout dependencies (ORDER-ITEM-16)', function () {
    expect(orderItemBoundaryWalletDependencyOffenders(orderItemBoundaryLoad(['app'])))->toBe([]);
});

it('keeps the order_items migration free of cascadeOnDelete, softDeletes and unique(order_id, product_id)', function () {
    $migration = collect(orderItemBoundaryLoad(['database/migrations']))->first(fn ($code, $path) => str_contains($path, 'create_order_items_table'));

    expect($migration)->not->toBeNull()
        ->and($migration)->not->toContain('cascadeOnDelete')
        ->and($migration)->not->toContain('softDeletes')
        ->and($migration)->not->toMatch('/unique\(/');

    // Every foreign key declared there is restrictOnDelete().
    expect(substr_count($migration, 'foreignId('))->toBe(3)
        ->and(substr_count($migration, 'restrictOnDelete()'))->toBe(3);
});

it('never gives OrderItem SoftDeletes, fillable columns or a factory', function () {
    $code = orderItemBoundaryLoad(['app/Models'])['app/Models/OrderItem.php'];

    expect($code)->not->toContain('SoftDeletes')
        ->and($code)->not->toContain('HasFactory')
        ->and($code)->toContain('$fillable = []')
        ->and(file_exists(database_path('factories/OrderItemFactory.php')))->toBeFalse();
});

it('guards OrderItem at performInsert()/performUpdate()/performDeleteOnModel(), which quiet saves and withoutEvents cannot bypass', function () {
    $code = orderItemBoundaryLoad(['app/Models'])['app/Models/OrderItem.php'];

    expect($code)->toContain('function performInsert(')
        ->and($code)->toContain('function performUpdate(')
        ->and($code)->toContain('function performDeleteOnModel(')
        ->and($code)->not->toContain('static::updating(')
        ->and($code)->not->toContain('static::creating(')
        ->and($code)->not->toContain('static::deleting(');
});

it('guards the line-backed Order aggregate at performUpdate(), not a model event (ORDER-ITEM-08)', function () {
    $code = orderItemBoundaryLoad(['app/Models'])['app/Models/Order.php'];

    expect($code)->toContain("isDirty(['store_id', 'currency_id', 'amount'])")
        ->and($code)->toContain("isDirty('is_line_backed')")
        ->and($code)->toContain('persistedAsLineBacked()')
        // Provenance is the persistent flag; the guard must not fall back to "do lines exist right now".
        ->and($code)->not->toMatch('/items\(\)\s*->\s*exists\(/')
        ->and($code)->not->toContain('static::updating(');
});

it('actually finds files to scan in every production root — a vacuous scan proves nothing', function () {
    $files = orderItemBoundaryLoad(orderItemBoundaryProductionRoots());

    expect(count($files))->toBeGreaterThan(100)
        ->and($files)->toHaveKeys([
            'app/Models/OrderItem.php',
            'app/Models/Order.php',
            'app/Domain/Orders/Services/OrderCreationService.php',
            'app/Domain/Orders/Services/OrderLineIntegrityChecker.php',
            'app/Domain/Payments/Services/PaymentService.php',
            'app/Console/Commands/CreateTestStripeOrder.php',
            'routes/web.php',
            'bootstrap/app.php',
        ]);
});

// ---------------------------------------------------------------------
// Scanner self-tests: synthetic violations must be flagged, clean input must not be.
// ---------------------------------------------------------------------

it('[self-test] the raw-table scanner flags DB::table and raw SQL against order_items, but not order_statuses/orders', function () {
    foreach ([
        "<?php DB::table('order_items')->insert([]);",
        "<?php DB::table('order_items')->where('id', 1)->update([]);",
        '<?php DB::table("order_items")->delete();',
        '<?php DB::statement("INSERT INTO order_items (a) VALUES (1)");',
        '<?php DB::statement("insert or ignore into `order_items` (a) values (1)");',
        '<?php DB::update("UPDATE order_items SET quantity = 9");',
        '<?php DB::unprepared("DELETE FROM order_items");',
        '<?php DB::unprepared("TRUNCATE TABLE order_items");',
        '<?php DB::unprepared("DROP TABLE IF EXISTS order_items");',
    ] as $violation) {
        expect(orderItemBoundaryRawTableOffenders(['app/X.php' => $violation], []))->toBe(['app/X.php'], "not flagged: {$violation}");
    }

    expect(orderItemBoundaryRawTableOffenders(['app/Domain/Orders/Services/OrderCreationService.php' => "<?php DB::table('order_items')->insert([]);"], ['app/Domain/Orders/Services/OrderCreationService.php']))->toBe([])
        ->and(orderItemBoundaryRawTableOffenders(['app/X.php' => "<?php DB::table('order_statuses')->get(); DB::table('orders')->get();"], []))->toBe([])
        ->and(orderItemBoundaryRawTableOffenders(['app/X.php' => orderItemBoundaryStrip("<?php\n// DB::table('order_items')->delete();\n\$a = 1;")], []))->toBe([]);
});

it('[self-test] the Eloquent-write scanner flags every OrderItem/relation write shape, and ignores reads and comments', function () {
    foreach ([
        '<?php OrderItem::create([]);',
        '<?php OrderItem::forceCreate([]);',
        '<?php OrderItem::insert([]);',
        '<?php OrderItem::upsert([], []);',
        '<?php OrderItem::destroy(1);',
        '<?php OrderItem::withoutEvents(fn () => 1);',
        '<?php $i = new OrderItem;',
        '<?php $i = new OrderItem();',
        "<?php OrderItem::where('order_id', 1)->update(['quantity' => 5]);",
        "<?php OrderItem::query()->where('id', 1)->delete();",
        '<?php OrderItem::whereKey(1)->forceDelete();',
        '<?php $order->items()->create([]);',
        '<?php $order->items()->save($item);',
        '<?php $order->items()->createMany([]);',
        '<?php $order->items()->delete();',
        '<?php $order->items()->update([]);',
    ] as $violation) {
        expect(orderItemBoundaryEloquentWriteOffenders(['app/X.php' => $violation]))->toBe(['app/X.php'], "not flagged: {$violation}");
    }

    foreach ([
        "<?php OrderItem::where('order_id', 1)->get();",
        '<?php $order->items()->exists();',
        '<?php $order->items;',
        '<?php $order->load("items");',
        '<?php OrderItem::query()->where("order_id", 1)->get();',
    ] as $clean) {
        expect(orderItemBoundaryEloquentWriteOffenders(['app/X.php' => $clean]))->toBe([], "wrongly flagged: {$clean}");
    }

    expect(orderItemBoundaryEloquentWriteOffenders(['app/X.php' => orderItemBoundaryStrip("<?php\n// OrderItem::create([]);\n\$a = 1;")]))->toBe([]);
});

it('[self-test] the Order-creator scanner flags direct Order creation outside the allowlist, and allowlisted/commented code passes', function () {
    foreach ([
        '<?php Order::create([]);',
        '<?php Order::forceCreate([]);',
        '<?php Order::firstOrCreate([]);',
        '<?php Order::updateOrCreate([], []);',
        '<?php Order::insert([]);',
        '<?php Order::factory()->create();',
        '<?php $o = new Order;',
        '<?php $o = new Order();',
    ] as $violation) {
        expect(orderItemBoundaryOrderCreatorOffenders(['app/X.php' => $violation], []))->toBe(['app/X.php'], "not flagged: {$violation}");
    }

    expect(orderItemBoundaryOrderCreatorOffenders(['app/Domain/Orders/Services/OrderCreationService.php' => '<?php Order::create([]);'], ['app/Domain/Orders/Services/OrderCreationService.php']))->toBe([])
        ->and(orderItemBoundaryOrderCreatorOffenders(['app/X.php' => '<?php OrderStatus::create([]); $x = new OrderItemThing;'], []))->toBe([])
        ->and(orderItemBoundaryOrderCreatorOffenders(['app/X.php' => orderItemBoundaryStrip("<?php\n// Order::create([]);\n\$a = 1;")], []))->toBe([]);
});

it('[self-test] the settlement-side scanner flags Product/OrderItem/price reads in Payments, Wallet and Payouts only', function () {
    foreach ([
        'use App\\Domain\\Catalog\\Models\\Product;',
        '$p = Product::find(1);',
        '$x = $product->price_amount;',
        'use App\\Models\\OrderItem;',
        '$order->items->sum(1);',
        "DB::table('order_items')->get();",
        'app(OrderCreationService::class);',
    ] as $violation) {
        foreach (['app/Domain/Payments/Services/X.php', 'app/Domain/Payouts/Services/X.php', 'app/Domain/Wallet/X.php', 'app/Services/Wallet/X.php'] as $path) {
            expect(orderItemBoundarySettlementOffenders([$path => "<?php {$violation}"]))->not->toBe([], "not flagged in {$path}: {$violation}");
        }
    }

    expect(orderItemBoundarySettlementOffenders(['app/Domain/Payments/Services/X.php' => '<?php use App\\Domain\\Orders\\Services\\OrderLineIntegrityChecker; $c->assertConsistent($order);']))->toBe([])
        ->and(orderItemBoundarySettlementOffenders(['app/Domain/Catalog/X.php' => '<?php Product::find(1);']))->toBe([]);
});

it('[self-test] the Orders-domain Catalog scanner flags a Product read outside the creation service only', function () {
    $allowed = ['app/Domain/Orders/Services/OrderCreationService.php'];

    expect(orderItemBoundaryOrdersDomainCatalogOffenders(['app/Domain/Orders/Services/OrderLineIntegrityChecker.php' => '<?php Product::find(1);'], $allowed))->toBe(['app/Domain/Orders/Services/OrderLineIntegrityChecker.php'])
        ->and(orderItemBoundaryOrdersDomainCatalogOffenders(['app/Domain/Orders/Services/OrderLifecycleService.php' => '<?php $x = $y->price_amount;'], $allowed))->toBe(['app/Domain/Orders/Services/OrderLifecycleService.php'])
        ->and(orderItemBoundaryOrdersDomainCatalogOffenders(['app/Domain/Orders/Services/OrderCreationService.php' => '<?php Product::find(1);'], $allowed))->toBe([])
        ->and(orderItemBoundaryOrdersDomainCatalogOffenders(['app/Domain/Payments/X.php' => '<?php Product::find(1);'], $allowed))->toBe([]);
});

it('[self-test] the forbidden-field scanner flags each excluded concept in the OrderItem files and ignores unrelated ones', function () {
    foreach (['sku', 'variant', 'inventory', 'stock', 'reserved', 'reservation', 'fulfillment', 'discount', 'coupon', 'tax', 'vat', 'shipping', 'supplier', 'vendor', 'slug', 'line_total_amount', 'metadata', 'expires_at'] as $needle) {
        expect(orderItemBoundaryForbiddenFieldOffenders(['app/Models/OrderItem.php' => "<?php \$x = '{$needle}';"]))->not->toBe([], "not flagged: {$needle}")
            ->and(orderItemBoundaryForbiddenFieldOffenders(['database/migrations/2026_09_22_090000_create_order_items_table.php' => "<?php \$x = '{$needle}';"]))->not->toBe([], "not flagged in migration: {$needle}");
    }

    expect(orderItemBoundaryForbiddenFieldOffenders(['app/Models/OrderItem.php' => '<?php $x = "unit_price_amount"; $y = "product_name"; $z = "quantity";']))->toBe([])
        ->and(orderItemBoundaryForbiddenFieldOffenders(['app/Models/Unrelated.php' => '<?php $x = "sku";']))->toBe([]);
});

it('[self-test] the provenance scanner flags any non-allowlisted mention of is_line_backed, and ignores comments and allowlisted files', function () {
    $allowed = ['app/Models/Order.php'];

    foreach (['<?php $o->is_line_backed;', "<?php ['is_line_backed' => true];", "<?php Order::where('is_line_backed', 1);"] as $violation) {
        expect(orderItemBoundaryProvenanceOffenders(['app/Console/Commands/CreateTestStripeOrder.php' => $violation], $allowed))->toBe(['app/Console/Commands/CreateTestStripeOrder.php'], "not flagged: {$violation}");
    }

    expect(orderItemBoundaryProvenanceOffenders(['app/Models/Order.php' => '<?php $o->is_line_backed;'], $allowed))->toBe([])
        ->and(orderItemBoundaryProvenanceOffenders(['app/X.php' => orderItemBoundaryStrip("<?php\n// is_line_backed in a comment\n\$a = 1;")], $allowed))->toBe([]);
});

it('[self-test] the wallet-dependency scanner flags Wallet/Payment references in OrderItem files only', function () {
    foreach (['app(WalletTransactionService::class);', 'new StoreWallet;', 'use App\\Domain\\Payments\\Models\\Payment;', '$w->reverse($t, "c");', '$w->record(1);'] as $violation) {
        expect(orderItemBoundaryWalletDependencyOffenders(['app/Models/OrderItem.php' => "<?php {$violation}"]))->not->toBe([], "not flagged: {$violation}");
    }

    expect(orderItemBoundaryWalletDependencyOffenders(['app/Models/Other.php' => '<?php app(WalletTransactionService::class);']))->toBe([]);
});
