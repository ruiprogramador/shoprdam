<?php

/**
 * Mechanical enforcement of the inventory boundary
 * (docs/inventory/INVENTORY-RESERVATIONS.md, INVENTORY-XX). Plain source
 * scans, mirroring OrderItemBoundaryTest / CatalogDomainBoundaryTest — comments
 * stripped first, exact path allowlists, pure detector functions with
 * synthetic self-tests, non-vacuous file-count checks.
 *
 * ## What is scanned
 *
 * "Production code" = PHP under app/, routes/, bootstrap/ (except the
 * generated bootstrap/cache), config/ and database/seeders/. Migrations,
 * factories and tests are outside the boundary (fixtures place precondition
 * state, which is their job); the inventory migrations are scanned separately,
 * by name.
 *
 * ## What these scans PROVE
 *
 * In the tree as written today: only InventoryReservationService touches the
 * `inventories`/`inventory_reservations` tables; no production code writes
 * either model through Eloquent; NO production code outside app/Domain/Inventory
 * references the inventory domain at all (so no controller, job, listener,
 * provider adapter, Payment/Wallet/Order-lifecycle code can reserve, commit or
 * release — which is what makes "PaymentAttempt failure / Order failed does not
 * release" a structural fact and not merely a tested behavior); the inventory
 * domain depends on no payment/wallet/provider/HTTP machinery and reads no
 * Product price/currency; its migrations use RESTRICT only; it contains no
 * delete/decrement/raw-DDL code and none of the concepts this branch excluded.
 *
 * ## What they do NOT prove
 *
 * A table or class name assembled at runtime, or raw SQL built from parts,
 * evades a text scan. This is not a database permission system: nothing here
 * stops `psql`/tinker/a migration from rewriting `inventories`. The model
 * guards cover every Eloquent INSTANCE write; the database CHECKs cover
 * negative/over-reserved counters; the integrity checker DETECTS the rest. And
 * the empty caller allowlist below is a tripwire, not a proof of correctness:
 * wiring inventory to payment is a decision (design document §12) that must
 * change this test on purpose.
 */
function inventoryBoundaryStrip(string $source): string
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
function inventoryBoundaryProductionRoots(): array
{
    return ['app', 'routes', 'bootstrap', 'config', 'database/seeders'];
}

/** @return array<string, string> repo-relative path => comment-stripped code */
function inventoryBoundaryLoad(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if (str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $files[$relative] = inventoryBoundaryStrip(file_get_contents($file->getPathname()));
        }
    }

    return $files;
}

/** @return array<string, string> the two inventory migrations, by name */
function inventoryBoundaryMigrations(): array
{
    return array_filter(
        inventoryBoundaryLoad(['database/migrations']),
        fn ($code, $path) => str_contains($path, 'create_inventories_table') || str_contains($path, 'create_inventory_reservations_table'),
        ARRAY_FILTER_USE_BOTH,
    );
}

/** @return array<string, string> every file of the inventory domain */
function inventoryBoundaryDomain(): array
{
    return array_filter(
        inventoryBoundaryLoad(['app/Domain/Inventory']),
        fn ($code, $path) => str_starts_with($path, 'app/Domain/Inventory/'),
        ARRAY_FILTER_USE_BOTH,
    );
}

/**
 * Any query-builder / raw-SQL touch of the inventory tables outside the
 * allowlist (reads included: the tables have exactly one legitimate raw accessor).
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function inventoryBoundaryRawTableOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/table\(\s*[\'"](inventories|inventory_reservations)[\'"]\s*\)/', $code)
            || preg_match('/\b(insert\s+(or\s+\w+\s+)?into|update|delete\s+from|truncate(\s+table)?|drop\s+table(\s+if\s+exists)?|alter\s+table)\s+[`"\[\\\\]{0,2}(inventories|inventory_reservations)\b/i', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Every eloquent-shaped way to write an Inventory or InventoryReservation:
 * static persistence calls, `new`, any mass write reached off a model chain,
 * and any write through the models' relations. No allowlist: the service
 * itself writes through the query builder, and the models refuse instances.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function inventoryBoundaryEloquentWriteOffenders(array $files): array
{
    $verbs = 'create|createMany|forceCreate|firstOrCreate|updateOrCreate|save|saveMany|insert|insertOrIgnore|insertGetId|upsert|update|updateOrInsert|delete|forceDelete|destroy|truncate|increment|decrement|incrementEach|decrementEach|touch';

    $patterns = [
        '/\bInventory(Reservation)?::\s*(create|forceCreate|updateOrCreate|firstOrCreate|insert|insertOrIgnore|upsert|destroy|truncate|unguard|withoutEvents)\s*\(/',
        '/\bnew\s+Inventory(Reservation)?\b/',
        '/\bInventory(Reservation)?::[^;{}]*?->\s*('.$verbs.')\s*\(/',
        '/->\s*(reservations|inventory)\s*\(\s*\)\s*->\s*('.$verbs.')\s*\(/',
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
 * The stock columns / tables must not appear anywhere in production code
 * outside the inventory domain — no hidden stock field, no second writer.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function inventoryBoundaryHiddenStockOffenders(array $files): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (str_starts_with($path, 'app/Domain/Inventory/')) {
            continue;
        }

        if (preg_match('/\b(on_hand_quantity|reserved_quantity|inventory_reservations|inventories)\b/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Callers of the inventory domain outside it, against an exact allowlist —
 * EMPTY today, on purpose: nothing in production reserves, commits or
 * releases. This is what structurally rules out controller writes, provider
 * adapter writes, PaymentAttempt-failed -> release and Order.failed -> release
 * (INVENTORY-13/14/19).
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function inventoryBoundaryCallerOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (str_starts_with($path, 'app/Domain/Inventory/') || in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/Domain\\\\Inventory\b|\bInventory(Reservation(Service|Status)?|IntegrityChecker)?\b/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * The inventory domain's forbidden dependencies: payment/wallet/provider/HTTP
 * machinery, the Order lifecycle, and any Product/Order money field.
 * INVENTORY-15/16.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path references needle"
 */
function inventoryBoundaryDependencyOffenders(array $files): array
{
    $forbidden = [
        'App\\Domain\\Payments', 'App\\Domain\\Payouts', 'App\\Domain\\Wallet', 'App\\Services\\Wallet', 'App\\Payments',
        'WalletTransactionService', 'WalletService', 'StoreWallet', 'PaymentService', 'PaymentAttempt', 'PaymentEventProcessor',
        'OrderLifecycleService', 'OrderTransitioned', 'OrderLifecycleState',
        'Http::', 'GuzzleHttp', 'curl_', 'Stripe', 'EasyPay',
        'price_amount', 'unit_price_amount', 'currency', "'amount'", '->amount',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = "{$path} references {$needle}";
            }
        }
    }

    return $offenders;
}

/**
 * Concepts this branch excluded, scanned across the inventory domain and its
 * migrations. INVENTORY scope.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path contains forbidden"
 */
function inventoryBoundaryForbiddenConceptOffenders(array $files): array
{
    $forbidden = [
        'sku', 'variant', 'warehouse', 'location', 'supplier', 'procurement', 'purchase_order', 'receipt', 'lot', 'batch', 'serial',
        'backorder', 'preorder', 'safety_stock', 'expires_at', 'expired', 'expiry', 'cancelled', 'cancellation', 'fulfil', 'fulfilment', 'fulfillment',
        'shipping', 'refund', 'setStock', 'set_stock', 'adjust',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        foreach ($forbidden as $needle) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/i', $code)) {
                $offenders[] = "{$path} contains '{$needle}'";
            }
        }
    }

    return $offenders;
}

/**
 * Code shapes that must not exist anywhere in the inventory domain: deleting,
 * bare counter arithmetic, raw DDL, or a shortcut around the canonical
 * statements (INVENTORY-06/07/18/19).
 *
 * @param  array<string, string>  $files
 * @return list<string> "path contains needle"
 */
function inventoryBoundaryForbiddenShapeOffenders(array $files): array
{
    $patterns = [
        'a delete' => '/->\s*(delete|forceDelete|truncate)\s*\(|\bDB::(unprepared|statement)\s*\(/',
        'a bare increment/decrement' => '/->\s*(increment|decrement|incrementEach|decrementEach)\s*\(/',
        'SoftDeletes' => '/\bSoftDeletes\b/',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $code)) {
                $offenders[] = "{$path} contains {$label}";
            }
        }
    }

    return $offenders;
}

/**
 * Every FK in the inventory migrations is RESTRICT; no CASCADE, no nullOnDelete,
 * no soft deletes (INVENTORY-18).
 *
 * @param  array<string, string>  $migrations
 * @return list<string>
 */
function inventoryBoundaryMigrationOffenders(array $migrations): array
{
    $offenders = [];

    foreach ($migrations as $path => $code) {
        if (preg_match('/cascade|nullOnDelete|softDeletes|dropForeign/i', $code)) {
            $offenders[] = "{$path} uses cascade/nullOnDelete/softDeletes";
        }

        if (substr_count($code, '->constrained(') !== substr_count($code, '->restrictOnDelete()')) {
            $offenders[] = "{$path} has a foreign key that is not restrictOnDelete()";
        }
    }

    return $offenders;
}

/** @return list<string> */
function inventoryBoundaryExpectedDomainFiles(): array
{
    return [
        'app/Domain/Inventory/Enums/InventoryReservationStatus.php',
        'app/Domain/Inventory/Exceptions/InsufficientStockException.php',
        'app/Domain/Inventory/Exceptions/InvalidReservationException.php',
        'app/Domain/Inventory/Exceptions/InventoryIntegrityException.php',
        'app/Domain/Inventory/Models/Inventory.php',
        'app/Domain/Inventory/Models/InventoryReservation.php',
        'app/Domain/Inventory/Services/InventoryIntegrityChecker.php',
        'app/Domain/Inventory/Services/InventoryReservationService.php',
    ];
}

// ---------------------------------------------------------------------
// Real scans of the real tree
// ---------------------------------------------------------------------

it('scans a non-vacuous production tree and exactly the expected inventory domain files', function () {
    $production = inventoryBoundaryLoad(inventoryBoundaryProductionRoots());
    $domain = array_keys(inventoryBoundaryDomain());
    sort($domain);

    expect(count($production))->toBeGreaterThan(100)
        ->and($domain)->toBe(inventoryBoundaryExpectedDomainFiles())
        ->and(array_keys(inventoryBoundaryMigrations()))->toHaveCount(2);
});

it('lets only InventoryReservationService touch the inventory tables through the query builder or raw SQL (INVENTORY-19)', function () {
    $allowed = ['app/Domain/Inventory/Services/InventoryReservationService.php'];

    expect(inventoryBoundaryRawTableOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('lets no production code write Inventory or InventoryReservation through Eloquent (INVENTORY-19)', function () {
    expect(inventoryBoundaryEloquentWriteOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots())))->toBe([]);
});

it('keeps the stock columns and tables out of every file outside the inventory domain (INVENTORY-19)', function () {
    expect(inventoryBoundaryHiddenStockOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots())))->toBe([]);
});

it('has NO production caller of the inventory domain: nothing reserves, commits or releases from a controller, job, listener, provider adapter or the Payment/Wallet/Order-lifecycle code (INVENTORY-13/14/16)', function () {
    $allowedCallers = []; // wiring inventory to any trigger is a decision (design §12) — change this on purpose, with tests

    expect(inventoryBoundaryCallerOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots()), $allowedCallers))->toBe([]);
});

it('keeps the inventory domain free of payment/wallet/provider/HTTP dependencies and of any Product/Order money field (INVENTORY-15/16)', function () {
    expect(inventoryBoundaryDependencyOffenders(inventoryBoundaryDomain()))->toBe([]);
});

it('keeps the inventory domain and migrations free of every excluded concept (variant, sku, warehouse, supplier, expiry, cancellation, fulfilment, stock adjustment, ...)', function () {
    expect(inventoryBoundaryForbiddenConceptOffenders([...inventoryBoundaryDomain(), ...inventoryBoundaryMigrations()]))->toBe([]);
});

it('contains no delete, raw DDL, bare counter arithmetic or soft-delete code in the inventory domain (INVENTORY-06/07/18)', function () {
    expect(inventoryBoundaryForbiddenShapeOffenders(inventoryBoundaryDomain()))->toBe([]);
});

it('declares only RESTRICT foreign keys in the inventory migrations (INVENTORY-18)', function () {
    expect(inventoryBoundaryMigrationOffenders(inventoryBoundaryMigrations()))->toBe([]);
});

it('keeps the load-bearing statements of the reservation service in place (INVENTORY-01/02/07/10)', function () {
    $code = inventoryBoundaryDomain()['app/Domain/Inventory/Services/InventoryReservationService.php'];
    $compact = preg_replace('/\s+/', '', $code);

    // the oversell guard: one conditional UPDATE, predicate inside the statement
    expect($code)->toContain("whereRaw('on_hand_quantity - reserved_quantity >= ?'")
        ->and(substr_count($code, "DB::table('inventories')"))->toBe(2)
        // the terminal transition is a compare-and-set on the reservation row
        ->and($compact)->toContain("->where('status',InventoryReservationStatus::Reserved->value)")
        // three locking (current) reads, each proven necessary on real InnoDB by tests/Concurrency:
        // the Inventory row is write-locked BEFORE its reservation child is inserted (reserve) or
        // changed (transition) — the FK shared-lock deadlock — and the compare-and-set loser
        // re-reads the reservation row (REPEATABLE READ snapshot)
        ->and(substr_count($code, 'lockForUpdate()'))->toBe(3)
        ->and($compact)->toContain('Inventory::query()->whereKey($inventoryId)->lockForUpdate()')
        ->and($compact)->toContain('Inventory::query()->whereKey($reservation->inventory_id)->lockForUpdate()')
        ->and($compact)->toContain('->whereKey($reservationId)->lockForUpdate()')
        // one transaction per operation, deterministic lock order — one
        // `DB::transaction(` call site in reserve() and one in settle(),
        // each now inside a small bounded retry loop (MariaDB's
        // innodb_snapshot_isolation signal, see isMariadbSnapshotConflict())
        // rather than reserve()'s previous two separate literal call sites
        // (try + a single hardcoded retry) — every retry still opens a
        // genuinely new transaction via this same call, never resuming a
        // failed one
        ->and(substr_count($code, 'DB::transaction('))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($code, "->orderBy('inventory_id')"))->toBeGreaterThanOrEqual(2)
        // no read-modify-write of a stock number
        ->and($code)->not->toMatch('/->(on_hand_quantity|reserved_quantity)\s*[-+]?=/')
        ->and($code)->not->toMatch('/\$\w+->(on_hand_quantity|reserved_quantity)\s*(>=|>|<=|<)\s*\$/');
});

it('keeps every reservation status change behind the state machine, never an arbitrary assignment (INVENTORY-08/09)', function () {
    $service = inventoryBoundaryDomain()['app/Domain/Inventory/Services/InventoryReservationService.php'];

    // the only status write is the CAS inside transition(), which takes an enum target
    expect(substr_count($service, "'status' => \$target->value"))->toBe(1)
        ->and($service)->toContain('private function transition(InventoryReservation $reservation, InventoryReservationStatus $target)');
});

it('never introduces ProductVariant, SKU, warehouse or supplier tables or classes anywhere (INVENTORY scope)', function () {
    $offenders = [];

    foreach (inventoryBoundaryLoad([...inventoryBoundaryProductionRoots(), 'database/migrations']) as $path => $code) {
        if (preg_match('/\bclass\s+\w*(Variant|Sku|Warehouse|Supplier|Backorder)\w*/i', $code)
            || preg_match('/Schema::(create|table)\(\s*[\'"](product_variants|skus|warehouses|warehouse_\w+|suppliers|stock_\w+|backorders)[\'"]/', $code)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------
// Self-tests: every detector must be able to fail, and must not cry wolf
// ---------------------------------------------------------------------

it('[self-test] the comment stripper removes prose but keeps code', function () {
    $code = inventoryBoundaryStrip("<?php\n// DB::table('inventories')\n/** Inventory::create() */\n\$x = 1;");

    expect($code)->not->toContain('inventories')->not->toContain('Inventory::')->toContain('$x = 1');
});

it('[self-test] the raw-table detector flags the builder and raw SQL outside the allowlist, and not the allowlisted file or an unrelated table', function () {
    $files = [
        'app/A.php' => "<?php DB::table('inventories')->where('id', 1)->get();",
        'app/B.php' => '<?php DB::table("inventory_reservations")->insert([]);',
        'app/C.php' => '<?php DB::statement("UPDATE inventories SET on_hand_quantity = 0");',
        'app/D.php' => '<?php DB::statement("DELETE FROM inventory_reservations");',
        'app/OK.php' => "<?php DB::table('inventories')->update([]);",
        'app/Other.php' => "<?php DB::table('order_items')->get();",
    ];

    expect(inventoryBoundaryRawTableOffenders($files, ['app/OK.php']))->toBe(['app/A.php', 'app/B.php', 'app/C.php', 'app/D.php']);
});

it('[self-test] the Eloquent-write detector flags every write shape and ignores reads', function () {
    $bad = [
        '<?php Inventory::create([]);',
        '<?php InventoryReservation::forceCreate([]);',
        '<?php $x = new Inventory;',
        '<?php $x = new InventoryReservation();',
        '<?php Inventory::query()->where("id", 1)->update([]);',
        '<?php InventoryReservation::whereKey(1)->delete();',
        '<?php Inventory::query()->increment("on_hand_quantity");',
        '<?php $product->inventory()->update([]);',
        '<?php $inventory->reservations()->create([]);',
        '<?php Inventory::withoutEvents(fn () => 1);',
    ];

    foreach ($bad as $i => $code) {
        expect(inventoryBoundaryEloquentWriteOffenders(["f{$i}" => $code]))->toBe(["f{$i}"], $code);
    }

    $good = [
        '<?php Inventory::query()->whereIn("product_id", [1])->get(["id"])->keyBy("product_id");',
        '<?php InventoryReservation::query()->whereKey(1)->lockForUpdate()->toBase()->value("status");',
        '<?php $s = InventoryReservationStatus::Reserved->value;',
        '<?php $item->inventory;',
    ];

    foreach ($good as $i => $code) {
        expect(inventoryBoundaryEloquentWriteOffenders(["g{$i}" => $code]))->toBe([], $code);
    }
});

it('[self-test] the hidden-stock detector flags a stock column or table outside the domain and not inside it', function () {
    $files = [
        'app/Http/X.php' => "<?php \$q->where('reserved_quantity', 1);",
        'app/Models/Y.php' => "<?php \$t = 'inventories';",
        'app/Domain/Inventory/Services/Z.php' => "<?php \$t = 'inventories';",
        'app/Other.php' => '<?php $x = 1;',
    ];

    expect(inventoryBoundaryHiddenStockOffenders($files))->toBe(['app/Http/X.php', 'app/Models/Y.php']);
});

it('[self-test] the caller detector flags every way of reaching the domain from outside, and honors an exact allowlist', function () {
    $files = [
        'app/Http/A.php' => '<?php use App\Domain\Inventory\Services\InventoryReservationService;',
        'app/Listeners/B.php' => '<?php app(InventoryReservationService::class)->release($o);',
        'app/Jobs/C.php' => '<?php (new InventoryIntegrityChecker)->audit();',
        'app/Payments/D.php' => '<?php Inventory::query()->first();',
        'app/Domain/Inventory/Services/Inside.php' => '<?php InventoryReservation::query();',
        'app/Fine.php' => '<?php $inventoryCount = 1; // no class reference',
    ];

    expect(inventoryBoundaryCallerOffenders($files, []))->toBe(['app/Http/A.php', 'app/Listeners/B.php', 'app/Jobs/C.php', 'app/Payments/D.php'])
        ->and(inventoryBoundaryCallerOffenders($files, ['app/Http/A.php']))->not->toContain('app/Http/A.php');
});

it('[self-test] the dependency detector flags payment, wallet, HTTP and money-field references', function () {
    $needles = ['App\\Domain\\Payments', 'PaymentAttempt', 'WalletTransactionService', 'OrderLifecycleState', 'Http::', 'Stripe', 'price_amount', 'unit_price_amount', 'currency_id'];

    foreach ($needles as $needle) {
        expect(inventoryBoundaryDependencyOffenders(['f.php' => "<?php \$x = '{$needle}';"]))->not->toBe([], $needle);
    }

    expect(inventoryBoundaryDependencyOffenders(['f.php' => '<?php $x = $order->amount;']))->not->toBe([])
        ->and(inventoryBoundaryDependencyOffenders(['f.php' => "<?php \$x = ['amount' => 1];"]))->not->toBe([])
        // a parameter merely NAMED $amount (Eloquent's own signature) is not a money field
        ->and(inventoryBoundaryDependencyOffenders(['f.php' => '<?php function incrementOrDecrement($column, $amount) {}']))->toBe([])
        ->and(inventoryBoundaryDependencyOffenders(['f.php' => '<?php $quantity = $item->quantity;']))->toBe([]);
});

it('[self-test] the forbidden-concept detector flags each excluded concept and ignores unrelated words', function () {
    foreach (['sku', 'variant', 'warehouse', 'supplier', 'backorder', 'expires_at', 'cancelled', 'fulfilment', 'setStock', 'adjust', 'lot', 'batch'] as $needle) {
        expect(inventoryBoundaryForbiddenConceptOffenders(['f.php' => "<?php \$x = '{$needle}';"]))->not->toBe([], $needle);
    }

    expect(inventoryBoundaryForbiddenConceptOffenders(['f.php' => '<?php $reserved = $released + $committed; $plot = 1;']))->toBe([]);
});

it('[self-test] the forbidden-shape detector flags deletes, bare counter arithmetic, raw DDL and SoftDeletes', function () {
    foreach ([
        '<?php $q->delete();', '<?php $q->forceDelete();', '<?php $q->truncate();',
        '<?php $q->increment("x");', '<?php $q->decrement("x", 2);',
        '<?php DB::statement("x");', '<?php DB::unprepared("x");',
        '<?php class A { use SoftDeletes; }',
    ] as $i => $code) {
        expect(inventoryBoundaryForbiddenShapeOffenders(["f{$i}" => $code]))->not->toBe([], $code);
    }

    expect(inventoryBoundaryForbiddenShapeOffenders(['f.php' => '<?php DB::table("t")->update(["x" => 1]);']))->toBe([]);
});

it('[self-test] the migration detector flags CASCADE, nullOnDelete, softDeletes and a FK that is not RESTRICT', function () {
    $ok = "<?php \$t->foreignId('a')->constrained()->restrictOnDelete();";

    expect(inventoryBoundaryMigrationOffenders(['ok' => $ok]))->toBe([])
        ->and(inventoryBoundaryMigrationOffenders(['c' => "<?php \$t->foreignId('a')->constrained()->cascadeOnDelete();"]))->not->toBe([])
        ->and(inventoryBoundaryMigrationOffenders(['n' => "<?php \$t->foreignId('a')->constrained()->nullOnDelete();"]))->not->toBe([])
        ->and(inventoryBoundaryMigrationOffenders(['s' => '<?php $t->softDeletes();']))->not->toBe([])
        ->and(inventoryBoundaryMigrationOffenders(['d' => "<?php \$t->foreignId('a')->constrained();"]))->not->toBe([]);
});
