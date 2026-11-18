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
 * In the tree as written today: each inventory table is touched through the
 * query builder or raw SQL only by its exact canonical owner; no production code
 * writes any inventory model through Eloquent; only InventoryMovementWriter
 * changes `on_hand_quantity` or inserts a movement; NO production code outside
 * app/Domain/Inventory references the inventory domain at all (so no controller,
 * job, listener, provider adapter, Payment/Wallet/Order-lifecycle code can
 * reserve, commit, release or touch stock — which is what makes "PaymentAttempt
 * failure / Order failed does not release" a structural fact and not merely a
 * tested behavior); physical counting has no production caller at all (STOCK-22);
 * the inventory domain depends on no payment/wallet/provider/HTTP machinery and
 * reads no Product price/currency; its migrations use RESTRICT only and reference
 * no User; it contains no delete/decrement/raw-DDL code and none of the excluded
 * concepts.
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

/** @return array<string, string> the inventory migrations, by name */
function inventoryBoundaryMigrations(): array
{
    return array_filter(
        inventoryBoundaryLoad(['database/migrations']),
        fn ($code, $path) => (bool) preg_match('/(create_inventories_table|create_inventory_(reservations|baselines|counts|movements)_table|add_is_baselined_to_inventories_table)/', $path),
        ARRAY_FILTER_USE_BOTH,
    );
}

/**
 * Who may touch each inventory table through the query builder or raw SQL, and
 * nobody else. The domain's other classes read through the models.
 *
 * @return array<string, list<string>>
 */
function inventoryBoundaryTableOwners(): array
{
    $reservations = 'app/Domain/Inventory/Services/InventoryReservationService.php';
    $writer = 'app/Domain/Inventory/Services/InventoryMovementWriter.php';
    $control = 'app/Domain/Inventory/Services/InventoryControlService.php';
    $count = 'app/Domain/Inventory/Services/InventoryCountService.php';

    return [
        'inventories' => [$reservations, $writer, $control],
        'inventory_reservations' => [$reservations],
        'inventory_movements' => [$writer],
        'inventory_counts' => [$count],
        'inventory_baselines' => [$control],
    ];
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
function inventoryBoundaryRawTableOffenders(array $files, array $allowed, string $tables = 'inventories|inventory_reservations|inventory_movements|inventory_counts|inventory_baselines'): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/table\(\s*[\'"]('.$tables.')[\'"]\s*\)/', $code)
            || preg_match('/\b(insert\s+(or\s+\w+\s+)?into|update|delete\s+from|truncate(\s+table)?|drop\s+table(\s+if\s+exists)?|alter\s+table)\s+[`"\[\\\\]{0,2}('.$tables.')\b/i', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Anything that could change `on_hand_quantity` or insert a movement, wherever it
 * sits: only InventoryMovementWriter may, which is what makes STOCK-01 structural.
 * Only a literal zero is exempt: that is createInventory() giving birth at 0/0.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function inventoryBoundaryOnHandWriterOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/[\'"]on_hand_quantity[\'"]\s*=>\s*+(?!0\b|[\'"]integer[\'"])/', $code)
            || preg_match('/\bset\s+[^;\'"]*on_hand_quantity\s*=/i', $code)
            || preg_match('/table\(\s*[\'"]inventory_movements[\'"]\s*\)/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Callers of physical counting anywhere outside its own class: EMPTY, on purpose,
 * and stricter than the general caller allowlist because the inventory domain's
 * other classes are scanned too. STOCK-22: counting is implemented and proven, NOT
 * activated. Detecting a physical discrepancy is not an operational feature until
 * the selling policy for an open discrepancy (O-1) is decided and the commit-timing
 * question behind O-8 is resolved (docs/inventory/INVENTORY-CONTROL.md §7.5).
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string>
 */
function inventoryBoundaryCountCallerOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if ($path === 'app/Domain/Inventory/Services/InventoryCountService.php' || in_array($path, $allowed, true)) {
            continue;
        }

        if (preg_match('/\bInventoryCountService\b|->\s*submitCount\s*\(/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Every eloquent-shaped way to write an inventory model: static persistence
 * calls, `new`, any mass write reached off a model chain, and any write through
 * the models' relations. No allowlist: the services write through the query
 * builder, and the models refuse instances.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function inventoryBoundaryEloquentWriteOffenders(array $files): array
{
    $verbs = 'create|createMany|forceCreate|firstOrCreate|updateOrCreate|save|saveMany|insert|insertOrIgnore|insertGetId|upsert|update|updateOrInsert|delete|forceDelete|destroy|truncate|increment|decrement|incrementEach|decrementEach|touch';

    $model = 'Inventory(Reservation|Movement|Count|Baseline)?';

    $patterns = [
        '/\b'.$model.'::\s*(create|forceCreate|updateOrCreate|firstOrCreate|insert|insertOrIgnore|upsert|destroy|truncate|unguard|withoutEvents)\s*\(/',
        '/\bnew\s+'.$model.'\b(?!Writer)/',
        '/\b'.$model.'::[^;{}]*?->\s*('.$verbs.')\s*\(/',
        '/->\s*(reservations|inventory|movements|counts|baseline|corrects|resolvedBy|reservation)\s*\(\s*\)\s*->\s*('.$verbs.')\s*\(/',
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

        if (preg_match('/\b(on_hand_quantity|reserved_quantity|is_baselined|after_baseline|inventory_reservations|inventory_movements|inventory_counts|inventory_baselines|inventories)\b/', $code)) {
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

        if (preg_match('/Domain\\\\Inventory\b|\bInventory(Reservation(Service|Status)?|IntegrityChecker|Movement(Writer|Reason)?|Control(Service)?|Count(Service|Status)?|Baseline(Kind)?)?\b/', $code)) {
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
        'sku', 'variant', 'warehouse', 'location', 'supplier', 'procurement', 'purchase_order', 'lot', 'batch', 'serial',
        'backorder', 'preorder', 'safety_stock', 'expires_at', 'expired', 'expiry', 'cancelled', 'cancellation', 'fulfil', 'fulfilment', 'fulfillment',
        'shipping', 'refund', 'setStock', 'set_stock', 'adjust', 'restock', 'opening_balance', 'incrementStock', 'decrementStock',
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
 * no soft deletes (INVENTORY-18, STOCK-14); and no FK reaches `users`, so deleting
 * a User neither destroys nor mutates stock history.
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

        if (preg_match('/constrained\(\s*[\'"]users[\'"]|foreignId\(\s*[\'"](actor_user_id|user_id)[\'"]|references\(\s*[\'"]id[\'"]\s*\)\s*->\s*on\(\s*[\'"]users[\'"]/', $code)) {
            $offenders[] = "{$path} ties stock history to the existence of a User";
        }
    }

    return $offenders;
}

/** @return list<string> */
function inventoryBoundaryExpectedDomainFiles(): array
{
    return [
        'app/Domain/Inventory/DTOs/CountResult.php',
        'app/Domain/Inventory/DTOs/LockedInventory.php',
        'app/Domain/Inventory/DTOs/MovementDraft.php',
        'app/Domain/Inventory/DTOs/MovementResult.php',
        'app/Domain/Inventory/DTOs/StoreOwnerActor.php',
        'app/Domain/Inventory/Enums/InventoryActorType.php',
        'app/Domain/Inventory/Enums/InventoryBaselineKind.php',
        'app/Domain/Inventory/Enums/InventoryCountStatus.php',
        'app/Domain/Inventory/Enums/InventoryMovementReason.php',
        'app/Domain/Inventory/Enums/InventoryReservationStatus.php',
        'app/Domain/Inventory/Exceptions/InsufficientStockException.php',
        'app/Domain/Inventory/Exceptions/InvalidInventoryOperationException.php',
        'app/Domain/Inventory/Exceptions/InvalidReservationException.php',
        'app/Domain/Inventory/Exceptions/InventoryIdempotencyConflictException.php',
        'app/Domain/Inventory/Exceptions/InventoryIntegrityException.php',
        'app/Domain/Inventory/Exceptions/InventoryNotBaselinedException.php',
        'app/Domain/Inventory/Exceptions/InventoryQuantityCeilingException.php',
        'app/Domain/Inventory/Models/Inventory.php',
        'app/Domain/Inventory/Models/InventoryBaseline.php',
        'app/Domain/Inventory/Models/InventoryCount.php',
        'app/Domain/Inventory/Models/InventoryMovement.php',
        'app/Domain/Inventory/Models/InventoryReservation.php',
        'app/Domain/Inventory/Services/InventoryControlService.php',
        'app/Domain/Inventory/Services/InventoryCountService.php',
        'app/Domain/Inventory/Services/InventoryIntegrityChecker.php',
        'app/Domain/Inventory/Services/InventoryMovementWriter.php',
        'app/Domain/Inventory/Services/InventoryReservationService.php',
        'app/Domain/Inventory/Support/InventoryInput.php',
        'app/Domain/Inventory/Support/InventoryRetry.php',
        'app/Domain/Inventory/Support/InventoryTarget.php',
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
        ->and(array_keys(inventoryBoundaryMigrations()))->toHaveCount(6);
});

it('lets each inventory table be touched through the query builder or raw SQL only by its exact canonical owner (INVENTORY-19, STOCK-01)', function () {
    $production = inventoryBoundaryLoad(inventoryBoundaryProductionRoots());

    foreach (inventoryBoundaryTableOwners() as $table => $owners) {
        expect(inventoryBoundaryRawTableOffenders($production, $owners, $table))->toBe([], $table);
    }
});

it('lets only InventoryMovementWriter change on_hand_quantity or insert a movement, so neither can exist without the other (STOCK-01)', function () {
    $allowed = ['app/Domain/Inventory/Services/InventoryMovementWriter.php'];

    expect(inventoryBoundaryOnHandWriterOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('keeps every physical-count caller out of production: STOCK-22, implemented and proven, NOT activated', function () {
    $allowedCallers = []; // adding one is a deliberate change that must cite the O-1 decision and O-8 (docs/inventory/INVENTORY-CONTROL.md §7.5)

    $tree = [...inventoryBoundaryLoad(inventoryBoundaryProductionRoots()), ...inventoryBoundaryLoad(['resources'])];

    expect(inventoryBoundaryCountCallerOffenders($tree, $allowedCallers))->toBe([]);
});

it('keeps Inventory writes behind the three canonical services: the writer is called only by them (STOCK-01)', function () {
    $canonical = [
        'app/Domain/Inventory/Services/InventoryReservationService.php',
        'app/Domain/Inventory/Services/InventoryControlService.php',
        'app/Domain/Inventory/Services/InventoryCountService.php',
    ];

    $callers = array_keys(array_filter(
        inventoryBoundaryLoad(['app']),
        fn ($code, $path) => $path !== 'app/Domain/Inventory/Services/InventoryMovementWriter.php' && str_contains($code, 'InventoryMovementWriter'),
        ARRAY_FILTER_USE_BOTH,
    ));

    sort($callers);
    sort($canonical);

    expect($callers)->toBe($canonical);
});

it('lets no production code write Inventory or InventoryReservation through Eloquent (INVENTORY-19)', function () {
    expect(inventoryBoundaryEloquentWriteOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots())))->toBe([]);
});

it('keeps the stock columns and tables out of every file outside the inventory domain (INVENTORY-19)', function () {
    expect(inventoryBoundaryHiddenStockOffenders(inventoryBoundaryLoad(inventoryBoundaryProductionRoots())))->toBe([]);
});

it('has NO production caller of the inventory domain: nothing reserves, commits or releases from a controller, job, listener, provider adapter or the Payment/Wallet/Order-lifecycle code (INVENTORY-13/14/16)', function () {
    // Wiring inventory to any trigger is a decision (design §12), and it is blocked
    // until composing these operations in a caller's transaction has a verifiable
    // strategy (docs/inventory/INVENTORY-CONTROL.md §24.7) — change this on purpose, with tests.
    $allowedCallers = [];

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
        // commit's on_hand change goes through the one writer that pairs it with its movement
        ->and($compact)->toContain('$this->movements->consumeReservation($reservation,$now)')
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

it('keeps the load-bearing statements of the movement writer in place (STOCK-01/05/06/09/11)', function () {
    $code = inventoryBoundaryDomain()['app/Domain/Inventory/Services/InventoryMovementWriter.php'];
    $compact = preg_replace('/\s+/', '', $code);

    // every guard is the predicate of one conditional UPDATE
    expect($compact)->toContain("->where('on_hand_quantity','<=',self::MAX_ON_HAND-\$quantity)")
        ->and($compact)->toContain("->whereRaw('on_hand_quantity-reserved_quantity>=?',[\$quantity])")
        ->and($compact)->toContain("->where('on_hand_quantity',\$expected)->where('reserved_quantity','<=',\$observed)")
        ->and($compact)->toContain("->where('reserved_quantity','>=',\$quantity)->where('on_hand_quantity','>=',\$quantity)")
        ->and(substr_count($code, "DB::table('inventories')"))->toBe(4)
        ->and(substr_count($code, "DB::table('inventory_movements')"))->toBe(1)
        // the row lock, and the locking read that sources a sale's after_baseline stamp
        ->and(substr_count($code, 'lockForUpdate()'))->toBe(2)
        ->and($compact)->toContain("->lockForUpdate()->toBase()->value('is_baselined')")
        ->and($compact)->toContain("'after_baseline'=>\$isBaselined")
        // a transaction is asserted, never assumed
        ->and(substr_count($code, '$this->assertInTransaction();'))->toBe(4)
        // a lost sale identity is corruption: thrown, never replayed
        ->and($code)->toContain('InventoryIntegrityException::duplicateSaleEvidence')
        // no read-modify-write of a stock number
        ->and($code)->not->toMatch('/\$\w+->(onHand|reserved|on_hand_quantity|reserved_quantity)\s*(>=|>|<=|<)\s*\$/');
});

it('keeps the load-bearing statements of the control and count services in place (STOCK-03/06/07/11)', function () {
    $control = inventoryBoundaryDomain()['app/Domain/Inventory/Services/InventoryControlService.php'];
    $count = inventoryBoundaryDomain()['app/Domain/Inventory/Services/InventoryCountService.php'];
    $compact = preg_replace('/\s+/', '', $count);

    expect($control)->toContain("->where('is_baselined', false)")
        ->and($control)->toContain("'is_baselined' => true")
        ->and(substr_count($control, "'is_baselined' => true"))->toBe(2)
        ->and($control)->toContain('$this->movements->lock($inventoryId)')
        ->and($control)->toContain('throw InventoryIdempotencyConflictException::forKey')
        ->and($control)->not->toContain('movement_sum')
        ->and($count)->toContain('$this->movements->lock($inventoryId)')
        // the status comes from the locked row, stale first
        ->and($compact)->toContain('$locked->onHand!==$expected=>InventoryCountStatus::Stale')
        ->and($compact)->toContain('$observed<$locked->reserved=>InventoryCountStatus::DiscrepancyOpen')
        // the one permitted change of a count row is a compare-and-set
        ->and($compact)->toContain("->where('status',InventoryCountStatus::DiscrepancyOpen->value)")
        ->and(substr_count($count, "DB::table('inventory_counts')"))->toBe(2);

    foreach ([$control, $count] as $code) {
        expect($code)->not->toMatch('/->\s*(release|commit|reserve)\s*\(/');
    }
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

it('[self-test] the raw-table detector honors a per-table allowlist', function () {
    $files = [
        'app/Writer.php' => "<?php DB::table('inventory_movements')->insert([]);",
        'app/Other.php' => '<?php DB::statement("INSERT INTO inventory_counts VALUES (1)");',
    ];

    expect(inventoryBoundaryRawTableOffenders($files, ['app/Writer.php'], 'inventory_movements'))->toBe([])
        ->and(inventoryBoundaryRawTableOffenders($files, [], 'inventory_movements'))->toBe(['app/Writer.php'])
        ->and(inventoryBoundaryRawTableOffenders($files, ['app/Writer.php']))->toBe(['app/Other.php']);
});

it('[self-test] the on-hand writer detector flags a second writer of on_hand or of movements, and not the allowlisted one or the creation at zero', function () {
    $files = [
        'app/A.php' => "<?php DB::table('x')->update(['on_hand_quantity' => DB::raw('on_hand_quantity + 1')]);",
        'app/B.php' => '<?php DB::update("UPDATE x SET on_hand_quantity = 5");',
        'app/C.php' => "<?php DB::table('inventory_movements')->insert([]);",
        'app/Writer.php' => "<?php DB::table('inventory_movements')->insert([]);",
        'app/Create.php' => "<?php \$row = ['on_hand_quantity' => 0, 'reserved_quantity' => 0];",
        'app/Cast.php' => "<?php \$casts = ['on_hand_quantity' => 'integer'];",
        'app/Read.php' => "<?php \$q->first(['id', 'on_hand_quantity']);",
    ];

    expect(inventoryBoundaryOnHandWriterOffenders($files, ['app/Writer.php']))->toBe(['app/A.php', 'app/B.php', 'app/C.php']);
});

it('[self-test] the count-caller detector flags every way of reaching physical counting, and honors an exact allowlist', function () {
    $files = [
        'app/Http/Controllers/CountController.php' => '<?php use App\Domain\Inventory\Services\InventoryCountService;',
        'routes/web.php' => '<?php Route::post("/count", [InventoryCountService::class, "submitCount"]);',
        'app/Jobs/C.php' => '<?php $service->submitCount($p, 1, 1, "k", $a);',
        'app/Domain/Inventory/Services/Sneaky.php' => '<?php app(InventoryCountService::class);',
        'app/Domain/Inventory/Services/InventoryCountService.php' => '<?php class InventoryCountService { public function submitCount() {} }',
        'app/Fine.php' => '<?php $count = $inventoryCount + 1;',
    ];

    expect(inventoryBoundaryCountCallerOffenders($files, []))->toBe([
        'app/Http/Controllers/CountController.php', 'routes/web.php', 'app/Jobs/C.php', 'app/Domain/Inventory/Services/Sneaky.php',
    ])->and(inventoryBoundaryCountCallerOffenders($files, ['app/Jobs/C.php']))->not->toContain('app/Jobs/C.php');
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
        '<?php InventoryMovement::create([]);',
        '<?php $x = new InventoryCount;',
        '<?php InventoryBaseline::query()->where("id", 1)->update([]);',
        '<?php InventoryMovement::whereKey(1)->delete();',
        '<?php $inventory->movements()->create([]);',
        '<?php $inventory->baseline()->update([]);',
        '<?php $count->resolvedBy()->delete();',
    ];

    foreach ($bad as $i => $code) {
        expect(inventoryBoundaryEloquentWriteOffenders(["f{$i}" => $code]))->toBe(["f{$i}"], $code);
    }

    $good = [
        '<?php Inventory::query()->whereIn("product_id", [1])->get(["id"])->keyBy("product_id");',
        '<?php InventoryReservation::query()->whereKey(1)->lockForUpdate()->toBase()->value("status");',
        '<?php $s = InventoryReservationStatus::Reserved->value;',
        '<?php $item->inventory;',
        '<?php InventoryMovement::query()->where("inventory_id", 1)->first();',
        '<?php private readonly InventoryMovementWriter $movements = new InventoryMovementWriter;',
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
        'app/Http/M.php' => "<?php DB::table('inventory_movements')->get();",
        'app/Http/F.php' => "<?php \$q->where('is_baselined', true);",
    ];

    expect(inventoryBoundaryHiddenStockOffenders($files))->toBe(['app/Http/X.php', 'app/Models/Y.php', 'app/Http/M.php', 'app/Http/F.php']);
});

it('[self-test] the caller detector flags every way of reaching the domain from outside, and honors an exact allowlist', function () {
    $files = [
        'app/Http/A.php' => '<?php use App\Domain\Inventory\Services\InventoryReservationService;',
        'app/Listeners/B.php' => '<?php app(InventoryReservationService::class)->release($o);',
        'app/Jobs/C.php' => '<?php (new InventoryIntegrityChecker)->audit();',
        'app/Payments/D.php' => '<?php Inventory::query()->first();',
        'app/Http/E.php' => '<?php app(InventoryControlService::class)->receive($p, 1, "k", $a, "n");',
        'app/Domain/Inventory/Services/Inside.php' => '<?php InventoryReservation::query();',
        'app/Fine.php' => '<?php $inventoryCount = 1; // no class reference',
    ];

    expect(inventoryBoundaryCallerOffenders($files, []))->toBe(['app/Http/A.php', 'app/Listeners/B.php', 'app/Jobs/C.php', 'app/Payments/D.php', 'app/Http/E.php'])
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
    foreach (['sku', 'variant', 'warehouse', 'supplier', 'backorder', 'expires_at', 'cancelled', 'fulfilment', 'setStock', 'adjust', 'lot', 'batch', 'restock', 'opening_balance'] as $needle) {
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
        ->and(inventoryBoundaryMigrationOffenders(['d' => "<?php \$t->foreignId('a')->constrained();"]))->not->toBe([])
        ->and(inventoryBoundaryMigrationOffenders(['u' => "<?php \$t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();"]))->not->toBe([])
        ->and(inventoryBoundaryMigrationOffenders(['s' => "<?php \$t->unsignedBigInteger('actor_user_id');"]))->toBe([]);
});
