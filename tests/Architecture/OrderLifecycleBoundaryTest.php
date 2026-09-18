<?php

/**
 * Mechanical enforcement of the Order lifecycle boundary
 * (docs/financial/ORDER-LIFECYCLE.md, ORDER-01/04/05/12). Plain source scans,
 * mirroring tests/Architecture/WalletLedgerSingleWriterTest and
 * ReconciliationNoFinancialMutationTest — comments stripped first, so prose
 * naming a forbidden thing is never mistaken for code doing it.
 *
 * ## What is scanned
 *
 * "Production code" = PHP under app/, routes/, bootstrap/ (except the
 * generated bootstrap/cache), config/ and database/seeders/. Migrations,
 * factories and tests are deliberately OUTSIDE the boundary: they create rows
 * and place fixtures in a precondition state, which is their job.
 *
 * ## What these scans PROVE
 *
 * No production file outside an explicit allowlist mentions the
 * `order_status_id` column or `OrderStatus::`, writes the `orders` table
 * through the query builder or raw SQL, or calls a lifecycle transition; and
 * the Orders domain contains exactly one write-shaped call — the canonical
 * compare-and-set UPDATE on Order — and nothing that writes the ledger.
 *
 * ## What they do NOT prove (stated so nothing claims more than it checks)
 *
 * A status column or table name assembled at runtime (string concatenation),
 * or raw SQL built from parts, evades a text scan; nothing mechanical covers
 * that. The runtime guard in App\Models\Order (performUpdate(), tested in
 * OrderLifecycleServiceTest) covers every Eloquent save of a model instance —
 * including saveQuietly()/updateQuietly()/withoutEvents() — but a write that
 * never touches a model instance is only caught here, statically. Order
 * *creation* is unconstrained (no production creator exists; the one tool is
 * pinned to `pending` below). Ad-hoc code run outside the repository
 * (tinker/psql against production) is outside any test's reach.
 *
 * The detectors are pure functions over a `path => code` map so that
 * "scanner self-tests" below can feed them synthetic violations and prove they
 * would actually fire — a scan that cannot fail proves nothing.
 */
function orderBoundaryStrip(string $source): string
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

/** @return list<string> repo-relative roots that count as production code */
function orderBoundaryProductionRoots(): array
{
    return ['app', 'routes', 'bootstrap', 'config', 'database/seeders'];
}

/** @return array<string, string> repo-relative path => comment-stripped code */
function orderBoundaryLoad(array $roots): array
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

            // Generated framework caches are not authored production code.
            if (str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $files[$relative] = orderBoundaryStrip(file_get_contents($file->getPathname()));
        }
    }

    return $files;
}

/** @param  array<string, string>  $files */
function orderBoundaryStatusColumnOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        if (str_contains($code, 'order_status_id') || preg_match('/\bOrderStatus::/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/** @param  array<string, string>  $files */
function orderBoundaryRawTableWriteOffenders(array $files): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        // DB::table('orders')... / ->table("orders") / raw UPDATE|INSERT|DELETE on orders
        if (preg_match('/table\(\s*[\'"]orders[\'"]\s*\)/', $code)
            || preg_match('/\b(update|insert\s+into|delete\s+from)\s+[`"\[]?orders\b/i', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/** @param  array<string, string>  $files */
function orderBoundaryTransitionCallerOffenders(array $files, array $allowed): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        // A call to a transition method, or a real dependency on the service
        // (an import / ::class reference). A bare mention inside a string —
        // e.g. the Order model's guard error message — is not a dependency.
        if (preg_match('/->\s*(markPaid|markPaymentFailed|markRefunded)\s*\(/', $code)
            || preg_match('/use\s+App\\\\Domain\\\\Orders\\\\Services\\\\OrderLifecycleService\b|OrderLifecycleService::class/', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/** @param  array<string, string>  $files */
function orderBoundaryDomainDependencyOffenders(array $files): array
{
    $forbidden = [
        // Order lifecycle must not know Payment state exists — the two are separate machines.
        'App\\Domain\\Payments',
        'PaymentStatus',
        'PaymentAttempt',
        'PaymentProviderEvent',
        'ProviderEventOutcome',
        // Nor be a route into the Wallet or a second settlement path.
        'WalletTransactionService',
        'WalletService',
        'StoreWallet\b',
        'PaymentEventProcessor',
        'PaymentAttemptRecoveryService',
        '->finalizeAttempt(',
        // Ledger-service verbs; reads of a StoreWalletTransaction (evidence) are fine.
        '->confirm(',
        '->markFailed(',
        '->reverse(',
        '->record(',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        if (! str_starts_with($path, 'app/Domain/Orders/')) {
            continue;
        }

        foreach ($forbidden as $needle) {
            $pattern = str_ends_with($needle, '\b') ? '/'.preg_quote(substr($needle, 0, -2), '/').'\b/' : '/'.preg_quote($needle, '/').'/';

            if (preg_match($pattern, $code)) {
                $offenders[] = "{$path} references {$needle}";
            }
        }
    }

    return $offenders;
}

/**
 * Every write-shaped call in the Orders domain: instance verbs that persist or
 * mutate (update/save/delete/fill/touch/...), static persistence entry points,
 * and any DB:: facade call other than transaction()/afterCommit(). The Orders
 * domain reads Wallet rows as evidence; this is what keeps "read" from quietly
 * becoming "write" (`$sale->update(...)`, `$sale->delete()`), which the
 * ledger's own single-writer scan cannot attribute to a model by text.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path: matched call"
 */
function orderBoundaryDomainWriteCalls(array $files): array
{
    $patterns = [
        '/->\s*(update|updateQuietly|save|saveQuietly|push|delete|forceDelete|restore|forceFill|fill|touch|increment|decrement|insert|insertOrIgnore|insertGetId|upsert|create|forceCreate|firstOrCreate|updateOrCreate|associate|dissociate|attach|detach|sync|truncate)\s*\(/',
        '/::\s*(create|forceCreate|insert|insertOrIgnore|upsert|updateOrCreate|firstOrCreate|destroy|truncate|unguard|withoutEvents)\s*\(/',
        '/\bDB::\s*(?!transaction\b|afterCommit\b)\w+\s*\(/',
    ];

    $calls = [];

    foreach ($files as $path => $code) {
        if (! str_starts_with($path, 'app/Domain/Orders/')) {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $matches)) {
                foreach ($matches[0] as $match) {
                    $calls[] = "{$path}: ".preg_replace('/\s+/', '', $match);
                }
            }
        }
    }

    return $calls;
}

// ---------------------------------------------------------------------
// Real scans of the real tree
// ---------------------------------------------------------------------

it('lets only the canonical lifecycle service, the Order model, and the test-only creation tool mention the order status column', function () {
    $allowed = [
        'app/Domain/Orders/Services/OrderLifecycleService.php', // the one writer
        'app/Models/Order.php',                                  // fillable, relation, runtime guard
        'app/Console/Commands/CreateTestStripeOrder.php',        // creation at `pending` only — see next test
    ];

    expect(orderBoundaryStatusColumnOffenders(orderBoundaryLoad(orderBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('keeps the test-only creation tool creating Orders at pending, never at another status', function () {
    $code = orderBoundaryLoad(['app/Console/Commands'])['app/Console/Commands/CreateTestStripeOrder.php'];

    preg_match_all("/'order_status_id'\s*=>\s*([^,\n]+)/", $code, $matches);

    expect($matches[1])->toHaveCount(1)
        ->and(trim($matches[1][0]))->toBe("OrderStatus::bySlugOrFail('pending')->id");
});

it('never lets production code write the orders table through the query builder or raw SQL', function () {
    expect(orderBoundaryRawTableWriteOffenders(orderBoundaryLoad(orderBoundaryProductionRoots())))->toBe([]);
});

it('lets only PaymentEventProcessor call a lifecycle transition — the canonical successful-payment path (ORDER-05)', function () {
    $allowed = [
        'app/Domain/Orders/Services/OrderLifecycleService.php',
        'app/Domain/Payments/Services/PaymentEventProcessor.php',
    ];

    expect(orderBoundaryTransitionCallerOffenders(orderBoundaryLoad(orderBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('keeps the Orders domain free of any Payments-domain dependency and of every Wallet/settlement write path (ORDER-04, ORDER-12)', function () {
    expect(orderBoundaryDomainDependencyOffenders(orderBoundaryLoad(['app'])))->toBe([]);
});

it('contains exactly one write-shaped call in the Orders domain: the canonical compare-and-set UPDATE on Order (ORDER-01, ORDER-12)', function () {
    $files = orderBoundaryLoad(['app']);
    $calls = orderBoundaryDomainWriteCalls($files);

    expect($calls)->toBe(['app/Domain/Orders/Services/OrderLifecycleService.php: ->update('])
        ->and($files['app/Domain/Orders/Services/OrderLifecycleService.php'])
        ->toMatch('/Order::query\(\)\s*->whereKey\([^;]*?->where\(\s*\'order_status_id\'[^;]*?->update\(/s');
});

it('guards the runtime half of the boundary at performUpdate(), which quiet saves and withoutEvents cannot bypass', function () {
    $code = orderBoundaryLoad(['app/Models'])['app/Models/Order.php'];

    expect($code)->toContain('function performUpdate(')
        ->and($code)->toContain("isDirty('order_status_id')")
        ->and($code)->not->toContain('static::updating(');
});

it('actually finds files to scan in every production root — a vacuous scan proves nothing', function () {
    $files = orderBoundaryLoad(orderBoundaryProductionRoots());

    expect(count($files))->toBeGreaterThan(100)
        ->and($files)->toHaveKeys([
            'app/Domain/Orders/Services/OrderLifecycleService.php',
            'app/Domain/Payments/Services/PaymentEventProcessor.php',
            'app/Models/Order.php',
            'routes/web.php',
            'routes/console.php',
            'bootstrap/app.php',
            'config/payments.php',
            'database/seeders/OrderStatusSeeder.php',
        ]);

    foreach (array_keys($files) as $path) {
        expect($path)->not->toStartWith('bootstrap/cache/');
    }
});

// ---------------------------------------------------------------------
// Scanner self-tests: synthetic violations must be flagged, clean input must
// not be. (The same detectors run above against the real tree.)
// ---------------------------------------------------------------------

it('[self-test] the status-column scanner flags a violation in ANY production root, and ignores comments and allowlisted files', function () {
    $violation = "<?php \$o->update(['order_status_id' => 2]);";

    foreach (['app/X.php', 'routes/web.php', 'bootstrap/app.php', 'config/x.php', 'database/seeders/S.php'] as $path) {
        expect(orderBoundaryStatusColumnOffenders([$path => $violation], []))->toBe([$path]);
    }

    expect(orderBoundaryStatusColumnOffenders(['app/X.php' => '<?php OrderStatus::bySlugOrFail("paid");'], []))->toBe(['app/X.php'])
        ->and(orderBoundaryStatusColumnOffenders(['app/Models/Order.php' => $violation], ['app/Models/Order.php']))->toBe([])
        ->and(orderBoundaryStatusColumnOffenders(['app/X.php' => '<?php class OrderStatusSeeder {}'], []))->toBe([])
        ->and(orderBoundaryStatusColumnOffenders(['app/X.php' => orderBoundaryStrip("<?php\n// order_status_id in a comment\n/** OrderStatus::x */\n\$a = 1;")], []))->toBe([]);
});

it('[self-test] the raw-table scanner flags query-builder and raw-SQL writes to orders, but not order_statuses', function () {
    foreach ([
        "<?php DB::table('orders')->update(['x' => 1]);",
        '<?php DB::table("orders")->delete();',
        '<?php DB::statement("UPDATE orders SET amount = 1");',
        '<?php DB::update("update `orders` set amount = 1");',
        '<?php DB::unprepared("DELETE FROM orders");',
    ] as $violation) {
        expect(orderBoundaryRawTableWriteOffenders(['routes/web.php' => $violation]))->toBe(['routes/web.php']);
    }

    expect(orderBoundaryRawTableWriteOffenders(['app/X.php' => "<?php DB::table('order_statuses')->updateOrInsert([]);"]))->toBe([]);
});

it('[self-test] the transition-caller scanner flags any non-allowlisted call or dependency', function () {
    foreach (['->markPaid($o, $s)', '->markPaymentFailed($o, $s)', '->markRefunded($o, $s)', 'app(OrderLifecycleService::class)'] as $call) {
        expect(orderBoundaryTransitionCallerOffenders(['app/Console/X.php' => "<?php \$svc{$call};"], []))->toBe(['app/Console/X.php']);
    }

    expect(orderBoundaryTransitionCallerOffenders(['app/X.php' => '<?php use App\\Domain\\Orders\\Services\\OrderLifecycleService;'], []))->toBe(['app/X.php'])
        ->and(orderBoundaryTransitionCallerOffenders(['app/Domain/Payments/Services/PaymentEventProcessor.php' => '<?php $o->markPaid($a, $b);'], ['app/Domain/Payments/Services/PaymentEventProcessor.php']))->toBe([])
        ->and(orderBoundaryTransitionCallerOffenders(['app/Models/Order.php' => "<?php throw new E('through App\\Domain\\Orders\\Services\\OrderLifecycleService.');"], []))->toBe([]);
});

it('[self-test] the Orders-domain write scanner flags every write-shaped call, including instance writes on ledger evidence', function () {
    $orders = 'app/Domain/Orders/Services/Sneaky.php';

    foreach ([
        '$sale->update([]);',
        '$sale->updateQuietly([]);',
        '$sale->save();',
        '$sale->saveQuietly();',
        '$sale->delete();',
        '$sale->forceFill([])->save();',
        '$order->touch();',
        '$order->status()->associate($s);',
        'StoreWalletTransaction::create([]);',
        'Order::upsert([], []);',
        'Order::withoutEvents(fn () => 1);',
        'DB::table("orders")->get();',
        'DB::statement("select 1");',
    ] as $violation) {
        expect(orderBoundaryDomainWriteCalls([$orders => "<?php {$violation}"]))->not->toBe([], "not flagged: {$violation}");
    }

    // Reads, the sanctioned transaction/afterCommit calls, `lockForUpdate`, and code outside the Orders domain are not flagged.
    expect(orderBoundaryDomainWriteCalls([$orders => '<?php $q->where("a", 1)->lockForUpdate()->first(); DB::transaction(fn () => 1); DB::afterCommit(fn () => 1); $x->load("status"); $q->exists();']))->toBe([])
        ->and(orderBoundaryDomainWriteCalls(['app/Services/Other.php' => '<?php $sale->update([]);']))->toBe([]);
});

it('[self-test] the Orders-domain dependency scanner flags Payments and Wallet-write dependencies', function () {
    foreach (['use App\\Domain\\Payments\\Models\\Payment;', '$x = PaymentStatus::Paid;', 'app(WalletTransactionService::class);', '$w->reverse($t, "c");', '$w->confirm($t);', 'new StoreWallet;'] as $violation) {
        expect(orderBoundaryDomainDependencyOffenders(['app/Domain/Orders/X.php' => "<?php {$violation}"]))->not->toBe([], "not flagged: {$violation}");
    }

    expect(orderBoundaryDomainDependencyOffenders(['app/Domain/Orders/X.php' => '<?php StoreWalletTransaction::query()->find(1);']))->toBe([]);
});
