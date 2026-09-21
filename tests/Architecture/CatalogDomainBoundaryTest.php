<?php

/**
 * Mechanical enforcement of the Catalog domain boundary
 * (docs/catalog/CATALOG-DOMAIN.md). Plain source scans, mirroring
 * tests/Architecture/OrderLifecycleBoundaryTest exactly — comments stripped
 * first, so prose naming a forbidden thing is never mistaken for code doing
 * it.
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
 * No production file other than App\Domain\Catalog\Services\ProductService
 * constructs, mass-assigns, or persists a Product; the Catalog domain
 * contains no Wallet/Payment dependency; the products migration never adds a
 * column this branch explicitly excluded (sku, stock, variant, slug, ...);
 * no products foreign key uses CASCADE; and — the CATALOG-09 hardening —
 * **no known production code path currently issues a query-builder-level
 * mass `forceDelete()`/`delete()` on Product, or a raw `DB::table('products')`
 * / SQL `DELETE FROM products` / `TRUNCATE products`, in the tree as it
 * exists today.**
 *
 * ## What they do NOT prove (stated so nothing claims more than it checks)
 *
 * The claim above is deliberately narrow: **"no known hard-delete path
 * exists in the current production tree"**, never the stronger, mechanically
 * false claim that "arbitrary SQL can never delete Product". A class name or
 * table name assembled at runtime (string concatenation, a variable holding
 * `'products'`, reflection), a raw SQL string built from parts, or a
 * `DB::statement()`/`DB::unprepared()` call spelled with unusual whitespace
 * or backtick-quoting the text scan does not anticipate, all evade a text
 * scan — this is regex-based static analysis, not a database permission
 * system. No database constraint blocks a hard delete of EVERY Product
 * (§8/§12, CATALOG-09): since `feat/order-items`, the
 * `restrictOnDelete()` FK `order_items.product_id` makes the database refuse
 * it for any Product an OrderItem references — and only those. The runtime guard in
 * App\Domain\Catalog\Models\Product (performUpdate()) covers every Eloquent
 * save of a model instance; a write that never touches a model instance is
 * only caught here, statically. Ad-hoc code run outside the repository
 * (tinker/psql against production) is outside any test's reach.
 *
 * The detectors are pure functions over a `path => code` map so that
 * "scanner self-tests" below can feed them synthetic violations and prove
 * they would actually fire — a scan that cannot fail proves nothing.
 */
function catalogBoundaryStrip(string $source): string
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
function catalogBoundaryProductionRoots(): array
{
    return ['app', 'routes', 'bootstrap', 'config', 'database/seeders'];
}

/** @return array<string, string> repo-relative path => comment-stripped code */
function catalogBoundaryLoad(array $roots): array
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

            $files[$relative] = catalogBoundaryStrip(file_get_contents($file->getPathname()));
        }
    }

    return $files;
}

/**
 * Every way production code could construct or persist a Product directly:
 * static creation entry points, `new Product`, and a query-builder-level
 * mass `forceDelete()`/`delete()` reached off *any* `Product::` chain
 * (`Product::query()->...`, `Product::withTrashed()->...`,
 * `Product::where(...)->...`), regardless of what arguments or further
 * chaining sit in between — the `[^;{}]*?` gap deliberately tolerates
 * quotes, commas, digits and nested calls, which is exactly what the
 * previous, narrower character-class version of this pattern could not
 * do (it could not see past a single `'id', 1` argument). Legitimate uses
 * (factories, migrations, the service itself) are outside the scanned roots
 * or explicitly allowlisted.
 *
 * This proves only that no *currently written* production statement takes
 * this shape — see the file's own top-level "what these scans do NOT prove".
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $allowed
 * @return list<string> "path: matched call"
 */
function catalogBoundaryDirectProductWriteOffenders(array $files, array $allowed): array
{
    $patterns = [
        '/\bProduct::\s*(create|forceCreate|updateOrCreate|firstOrCreate|insert|insertOrIgnore|upsert|destroy)\s*\(/',
        '/\bnew\s+Product\s*[\(;]/',
        '/\bProduct::[^;{}]*?->\s*forceDelete\s*\(/',
        '/\bProduct::[^;{}]*?->\s*delete\s*\(/',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

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
 * Raw, non-Eloquent access to the `products` table that could hard-delete a
 * row with no soft-delete scope in play at all: `DB::table('products')->
 * delete()`/`->truncate()` (with anything, including a `->where(...)`,
 * tolerated in between via `[^;]*?`), and raw SQL `DELETE FROM products` /
 * `TRUNCATE products` (quoted with backticks, double quotes, or brackets, or
 * unquoted). Deliberately narrow to the delete/truncate shape — an ordinary
 * `DB::table('products')->get()` is not a hard-delete path and is not
 * flagged here.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path"
 */
function catalogBoundaryRawProductsTableDeleteOffenders(array $files): array
{
    $offenders = [];

    foreach ($files as $path => $code) {
        if (preg_match('/table\(\s*[\'"]products[\'"]\s*\)[^;]*?->\s*(delete|truncate)\s*\(/', $code)
            // The [`"\[\\]{0,2} prefix tolerates up to two quote/backslash
            // characters (backtick, double quote, bracket, or an escaped
            // \" as found inside a double-quoted PHP string literal) between
            // the verb and the table name.
            || preg_match('/\b(delete\s+from|truncate(\s+table)?)\s+[`"\[\\\\]{0,2}products\b/i', $code)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

/**
 * Every write-shaped call inside the Catalog domain itself — mirrors
 * orderBoundaryDomainWriteCalls exactly, scoped to app/Domain/Catalog/.
 *
 * @param  array<string, string>  $files
 * @return list<string> "path: matched call"
 */
function catalogBoundaryDomainWriteCalls(array $files): array
{
    $patterns = [
        '/->\s*(update|updateQuietly|save|saveQuietly|push|delete|forceDelete|forceDeleteQuietly|restore|forceFill|fill|touch|increment|decrement|insert|insertOrIgnore|insertGetId|upsert|create|forceCreate|firstOrCreate|updateOrCreate|associate|dissociate|attach|detach|sync|truncate)\s*\(/',
        '/::\s*(create|forceCreate|insert|insertOrIgnore|upsert|updateOrCreate|firstOrCreate|destroy|truncate|unguard|withoutEvents)\s*\(/',
        '/\bDB::\s*(?!transaction\b|afterCommit\b)\w+\s*\(/',
    ];

    $calls = [];

    foreach ($files as $path => $code) {
        if (! str_starts_with($path, 'app/Domain/Catalog/')) {
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

/**
 * @param  array<string, string>  $files
 * @return list<string> "path references needle"
 */
function catalogBoundaryDomainDependencyOffenders(array $files): array
{
    $forbidden = [
        'App\\Domain\\Payments',
        'App\\Domain\\Payouts',
        'App\\Domain\\Orders',
        'PaymentStatus',
        'PaymentAttempt',
        'WalletTransactionService',
        'WalletService',
        'StoreWallet\b',
        'OrderLifecycleService',
        '->confirm(',
        '->markFailed(',
        '->reverse(',
        '->record(',
    ];

    $offenders = [];

    foreach ($files as $path => $code) {
        if (! str_starts_with($path, 'app/Domain/Catalog/')) {
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
 * Forbidden columns/concepts this branch explicitly excluded from Product —
 * scanned across the products migration, the Product model, ProductService
 * and ProductFactory, so a later, unreviewed addition is caught immediately.
 *
 * @return list<string> "path contains forbidden"
 */
function catalogBoundaryForbiddenFieldOffenders(array $files): array
{
    $scanned = array_filter($files, fn ($path) => str_contains($path, 'create_products_table')
        || $path === 'app/Domain/Catalog/Models/Product.php'
        || $path === 'app/Domain/Catalog/Services/ProductService.php', ARRAY_FILTER_USE_KEY);

    $forbidden = [
        'sku', 'stock', 'quantity', 'reserved', 'variant', 'slug',
        'category_id', 'vendor_id', 'supplier_id', 'old_price', 'discount',
        'rating', 'review', 'wishlist', 'compare_at',
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

// ---------------------------------------------------------------------
// Real scans of the real tree
// ---------------------------------------------------------------------

it('lets only ProductService construct, mass-assign, or persist a Product directly', function () {
    $allowed = ['app/Domain/Catalog/Services/ProductService.php'];

    expect(catalogBoundaryDirectProductWriteOffenders(catalogBoundaryLoad(catalogBoundaryProductionRoots()), $allowed))->toBe([]);
});

it('finds no known query-builder mass-delete or raw-SQL delete/truncate path against products (CATALOG-09) — a narrower, honest claim than "no SQL could ever delete it"', function () {
    $files = catalogBoundaryLoad(catalogBoundaryProductionRoots());

    expect(catalogBoundaryRawProductsTableDeleteOffenders($files))->toBe([]);
});

it('contains write-shaped calls in the Catalog domain only inside ProductService.php', function () {
    $files = catalogBoundaryLoad(['app']);
    $calls = catalogBoundaryDomainWriteCalls($files);

    foreach ($calls as $call) {
        expect($call)->toStartWith('app/Domain/Catalog/Services/ProductService.php:');
    }

    expect($calls)->not->toBe([]);
});

it('keeps the Catalog domain free of any Payments/Payouts/Orders/Wallet dependency', function () {
    expect(catalogBoundaryDomainDependencyOffenders(catalogBoundaryLoad(['app'])))->toBe([]);
});

it('never introduces a forbidden field (sku, stock, variant, slug, category, vendor/supplier, discount, rating, wishlist, ...)', function () {
    expect(catalogBoundaryForbiddenFieldOffenders(catalogBoundaryLoad(['app', 'database/migrations'])))->toBe([]);
});

it('never lets the products migration use cascadeOnDelete on any foreign key', function () {
    $files = catalogBoundaryLoad(['database/migrations']);
    $migration = collect($files)->first(fn ($code, $path) => str_contains($path, 'create_products_table'));

    expect($migration)->not->toBeNull()
        ->and($migration)->not->toContain('cascadeOnDelete');
});

it('guards ownership immutability at performUpdate(), which quiet saves and withoutEvents cannot bypass', function () {
    $code = catalogBoundaryLoad(['app/Domain/Catalog/Models'])['app/Domain/Catalog/Models/Product.php'];

    expect($code)->toContain('function performUpdate(')
        ->and($code)->toContain("isDirty('store_id')")
        ->and($code)->not->toContain('static::updating(');
});

it('never lets Product be hard-deleted through the instance API', function () {
    $code = catalogBoundaryLoad(['app/Domain/Catalog/Models'])['app/Domain/Catalog/Models/Product.php'];

    expect($code)->toContain('function forceDelete(')
        ->and($code)->toContain('function forceDeleteQuietly(');
});

it('actually finds files to scan in every production root — a vacuous scan proves nothing', function () {
    $files = catalogBoundaryLoad(catalogBoundaryProductionRoots());

    expect(count($files))->toBeGreaterThan(100)
        ->and($files)->toHaveKeys([
            'app/Domain/Catalog/Services/ProductService.php',
            'app/Domain/Catalog/Models/Product.php',
            'app/Models/Store.php',
            'routes/web.php',
            'bootstrap/app.php',
        ]);
});

// ---------------------------------------------------------------------
// Scanner self-tests: synthetic violations must be flagged, clean input must
// not be.
// ---------------------------------------------------------------------

it('[self-test] the direct-write scanner flags every construction/persistence route, and ignores comments and allowlisted files', function () {
    foreach ([
        '<?php Product::create([]);',
        '<?php Product::forceCreate([]);',
        '<?php Product::updateOrCreate([], []);',
        '<?php Product::firstOrCreate([]);',
        '<?php Product::insert([]);',
        '<?php Product::upsert([], []);',
        '<?php $p = new Product;',
        '<?php $p = new Product();',
    ] as $violation) {
        expect(catalogBoundaryDirectProductWriteOffenders(['app/X.php' => $violation], []))->toBe(['app/X.php']);
    }

    expect(catalogBoundaryDirectProductWriteOffenders(['app/Domain/Catalog/Services/ProductService.php' => '<?php Product::create([]);'], ['app/Domain/Catalog/Services/ProductService.php']))->toBe([])
        ->and(catalogBoundaryDirectProductWriteOffenders([
            'app/X.php' => catalogBoundaryStrip("<?php\n// Product::create([]); in a comment\n\$a = 1;"),
        ], []))->toBe([]);
});

it('[self-test] the direct-write scanner flags a query-builder mass forceDelete()/delete() reached off ANY Product:: chain, even with arguments and further chaining in between (CATALOG-09 hardening)', function () {
    foreach ([
        "<?php Product::query()->where('id', 1)->forceDelete();",
        "<?php Product::withTrashed()->where('id', 1)->forceDelete();",
        '<?php Product::withTrashed()->forceDelete();',
        "<?php Product::where('id', 1)->forceDelete();",
        "<?php Product::where('id', 1)->delete();",
        "<?php Product::query()->where('store_id', 1)->where('is_active', false)->delete();",
    ] as $violation) {
        expect(catalogBoundaryDirectProductWriteOffenders(['app/X.php' => $violation], []))->toBe(['app/X.php'], "not flagged: {$violation}");
    }

    // A plain read chain off Product:: is not a delete and must not be flagged.
    expect(catalogBoundaryDirectProductWriteOffenders(['app/X.php' => "<?php Product::where('id', 1)->first();"], []))->toBe([]);
});

it('[self-test] the raw-products-table-delete scanner flags DB::table(\'products\') deletes/truncates and raw SQL, but not an ordinary read', function () {
    foreach ([
        "<?php DB::table('products')->delete();",
        "<?php DB::table('products')->where('id', 1)->delete();",
        '<?php DB::table("products")->truncate();',
        '<?php DB::statement("DELETE FROM products");',
        '<?php DB::statement("delete from `products` where id = 1");',
        '<?php DB::unprepared("TRUNCATE products");',
        '<?php DB::update("delete from \"products\"");',
    ] as $violation) {
        expect(catalogBoundaryRawProductsTableDeleteOffenders(['app/X.php' => $violation]))->toBe(['app/X.php'], "not flagged: {$violation}");
    }

    expect(catalogBoundaryRawProductsTableDeleteOffenders(['app/X.php' => "<?php DB::table('products')->where('id', 1)->get();"]))->toBe([])
        ->and(catalogBoundaryRawProductsTableDeleteOffenders(['app/X.php' => "<?php DB::statement('delete from order_products');"]))->toBe([]);
});

it('[self-test] the domain write-call scanner flags every write-shaped call inside app/Domain/Catalog/, and ignores other domains', function () {
    $catalog = 'app/Domain/Catalog/Services/Sneaky.php';

    foreach ([
        '$p->update([]);', '$p->updateQuietly([]);', '$p->save();', '$p->saveQuietly();',
        '$p->delete();', '$p->forceDelete();', 'Product::create([]);', 'Product::upsert([], []);',
        'DB::table("products")->update([]);',
    ] as $violation) {
        expect(catalogBoundaryDomainWriteCalls([$catalog => "<?php {$violation}"]))->not->toBe([], "not flagged: {$violation}");
    }

    expect(catalogBoundaryDomainWriteCalls([$catalog => '<?php $q->where("a", 1)->first(); DB::transaction(fn () => 1); DB::afterCommit(fn () => 1);']))->toBe([])
        ->and(catalogBoundaryDomainWriteCalls(['app/Services/Other.php' => '<?php $p->update([]);']))->toBe([]);
});

it('[self-test] the dependency scanner flags Payments/Payouts/Orders/Wallet references inside the Catalog domain only', function () {
    foreach (['use App\\Domain\\Payments\\Models\\Payment;', 'app(WalletTransactionService::class);', '$w->reverse($t, "c");', 'new StoreWallet;', 'OrderLifecycleService::class'] as $violation) {
        expect(catalogBoundaryDomainDependencyOffenders(['app/Domain/Catalog/X.php' => "<?php {$violation}"]))->not->toBe([], "not flagged: {$violation}");
    }

    expect(catalogBoundaryDomainDependencyOffenders(['app/Domain/Orders/X.php' => '<?php app(WalletTransactionService::class);']))->toBe([]);
});

it('[self-test] the forbidden-field scanner flags each excluded concept and ignores unrelated files', function () {
    foreach (['sku', 'stock', 'quantity', 'reserved', 'variant', 'slug', 'category_id', 'vendor_id', 'supplier_id', 'old_price', 'discount', 'rating', 'review', 'wishlist', 'compare_at'] as $needle) {
        expect(catalogBoundaryForbiddenFieldOffenders(['app/Domain/Catalog/Models/Product.php' => "<?php // {$needle}\n\$x = '{$needle}';"]))->not->toBe([]);
    }

    expect(catalogBoundaryForbiddenFieldOffenders(['app/Domain/Catalog/Models/Product.php' => '<?php $x = "price_amount";']))->toBe([])
        ->and(catalogBoundaryForbiddenFieldOffenders(['app/Domain/Catalog/Models/Unrelated.php' => '<?php $x = "sku";']))->toBe([]);
});
