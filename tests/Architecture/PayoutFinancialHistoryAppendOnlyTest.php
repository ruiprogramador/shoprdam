<?php

/**
 * Payout, PayoutAttempt, the withdrawal/withdrawal_reversal ledger entries,
 * external transfer evidence, and the recovery audit trail are all
 * append-only financial history — see this branch's own design record.
 * These tests guard the three ways that could quietly regress:
 *
 * 1. a migration adding cascadeOnDelete() on a payout-related FK, which
 *    would let deleting a Store/wallet/currency/Payout silently vaporize
 *    financial history instead of being blocked;
 * 2. a SoftDeletes trait being added to a payout model as a shortcut
 *    instead of genuinely never deleting these rows;
 * 3. application code (outside migrations/factories/tests) calling
 *    ->delete()/->forceDelete()/::destroy()/::truncate()/DB::delete()/
 *    DB::statement() against a payout table.
 *
 * These are text scans, not a SQL/AST parser — they catch the idiomatic
 * ways a developer would normally write any of the above, not a
 * deliberately obfuscated bypass (e.g. building a method name from string
 * concatenation to dodge the literal substring). What actually guarantees
 * the append-only invariant regardless of which PHP API is used to attempt
 * a delete is the real, restrictOnDelete() database constraint — verified
 * directly (not by source-text inspection) in
 * tests/Feature/Domain/Payouts/PayoutSchemaDeletePolicyTest and by the two
 * live delete-rejection tests in PayoutAttemptLifecycleTest. These scans
 * are a fast, complementary guardrail on top of that real guarantee, not a
 * substitute for it.
 */
it('never lets a payout-related migration cascade-delete financial history', function () {
    $files = glob(database_path('migrations/*payout*.php'));

    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        if (str_contains($contents, 'cascadeOnDelete')) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * A regression test for exactly the blocker this branch was reviewed
 * against: `payouts.current_payout_attempt_id` used to be `nullOnDelete()`,
 * which let a PayoutAttempt still referenced as a Payout's *current* attempt
 * be deleted outright — silently nulling the pointer instead of the DB
 * refusing the delete. This walks every foreign key defined in the payout
 * migrations and requires each one to declare an explicit, recognized
 * delete policy — fail-closed: a `->constrained(...)` FK with NO delete
 * method at all (relying on whatever the database driver defaults to) is
 * treated as an offense, exactly the same as an explicit `cascadeOnDelete()`
 * or an un-allow-listed `nullOnDelete()`. An earlier version of this test
 * only recognized the three explicit policy calls and silently skipped
 * anything else — which meant a FK added with `->constrained()` and no
 * delete rule at all passed silently instead of failing. `restrictOnDelete()`
 * is the only accepted policy for every column except the two deliberately
 * allow-listed non-financial identity columns (`requested_by`, `admin_id`
 * — see each migration's own comment for why those two specifically are
 * safe to null out).
 *
 * Known, documented limitation: this only recognizes Laravel's
 * `foreignId()->constrained()->xOnDelete()` fluent style — a FK declared via
 * the older `$table->foreign('col')->references()->on()->onDelete()` API
 * would not be parsed by this regex. Nothing in this codebase (or Laravel's
 * own current conventions) uses that older style, so it isn't treated as a
 * realistic bypass worth a full SQL/AST parser for. What actually closes
 * that gap is tests/Feature/Domain/Payouts/PayoutSchemaDeletePolicyTest,
 * which reads the real constraint back from the migrated database via
 * `PRAGMA foreign_key_list` — immune to which PHP API declared it. This
 * text-based test stays as a fast, migration-source-only guardrail.
 */
it('requires every payout-related foreign key to declare an explicit delete policy, restrict unless allow-listed', function () {
    $allowedNullOnDelete = ['requested_by', 'admin_id'];

    $files = glob(database_path('migrations/*payout*.php'));
    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        // Split on each foreignId() call so every FK definition (which may
        // span several chained lines) is inspected as its own chunk.
        $chunks = preg_split('/(?=\$table->foreignId\()/', $contents);

        foreach ($chunks as $chunk) {
            if (! preg_match("/\\\$table->foreignId\\('([a-z_]+)'\\)/", $chunk, $matches)) {
                continue;
            }

            $column = $matches[1];

            // Only chunks that actually declare a constraint on THIS
            // column matter — stop at the next statement/semicolon so a
            // later, unrelated foreignId() in the same chunk (rare, but
            // possible after the split's lookahead) is never misattributed,
            // and so one statement's policy text can never bleed into
            // another's.
            $statement = strtok($chunk, ';');

            if (! str_contains($statement, '->constrained(')) {
                // A plain foreignId() column with no ->constrained() call
                // is not a foreign key at all — nothing to declare.
                continue;
            }

            $isRestrict = str_contains($statement, 'restrictOnDelete');
            $isNull = str_contains($statement, 'nullOnDelete');
            $isCascade = str_contains($statement, 'cascadeOnDelete');

            if ($isCascade) {
                $offenders[] = "{$column} in ".basename($file).' uses cascadeOnDelete(), which is never allowed for a payout-related FK';

                continue;
            }

            if ($isNull) {
                if (! in_array($column, $allowedNullOnDelete, true)) {
                    $offenders[] = "{$column} in ".basename($file).' uses nullOnDelete() but is not an allow-listed identity column';
                }

                continue;
            }

            if (! $isRestrict) {
                // constrained() with no explicit delete policy at all —
                // fail closed rather than silently trusting whatever the
                // database driver defaults to.
                $offenders[] = "{$column} in ".basename($file).' is a foreign key with no explicit delete policy declared';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never lets a Payout domain model use soft deletes as a substitute for genuinely never deleting financial history', function () {
    $files = [
        app_path('Domain/Payouts/Models/Payout.php'),
        app_path('Domain/Payouts/Models/PayoutAttempt.php'),
        app_path('Domain/Payouts/Models/PayoutRecoveryAction.php'),
    ];

    $offenders = [];

    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");

        if (str_contains(file_get_contents($file), 'SoftDeletes')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('never lets application code delete or truncate payout financial history', function () {
    $offenders = [];

    $roots = [
        app_path('Domain/Payouts'),
        app_path('Http/Controllers/Admin/PayoutRecoveryController.php'),
        app_path('Console/Commands/ReconcileOrphanedPayoutAttempts.php'),
        app_path('Payouts'),
    ];

    // ::destroy() and the raw-query-builder DB::delete()/DB::statement()
    // forms are idiomatic, non-obfuscated alternatives to ->delete() a
    // developer could reach for without ever writing the string "->delete(" —
    // included so this guardrail isn't trivially sidestepped by using a
    // different, equally normal Eloquent/DB API for the same operation.
    $forbidden = ['->delete(', '->forceDelete(', '::destroy(', '::truncate(', '->truncate(', 'DB::delete(', 'DB::statement('];

    foreach ($roots as $root) {
        $files = is_dir($root)
            ? iterator_to_array(new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            ))
            : [new SplFileInfo($root)];

        foreach ($files as $file) {
            if (! is_file($file->getPathname()) || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' contains '.$needle;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
