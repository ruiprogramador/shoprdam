<?php

use App\Console\Commands\AuditWalletLedger;

/**
 * WalletLedgerAuditor and `wallet:audit` must be strictly read-only: never
 * mutate wallet.balance, never create/update a StoreWalletTransaction,
 * never reverse anything, and never offer a --fix. A mismatch is reported,
 * never corrected — see WalletLedgerAuditor's own docblock for why.
 *
 * A plain text scan over the *code* only (doc-comments stripped first, the
 * same technique as tests/Architecture/PaymentsDomainBoundaryTest — this
 * class's own docblocks legitimately explain, in prose, exactly which
 * classes/flags it must never use, which would otherwise trip a naive
 * substring scan on itself) — sufficient here because a genuine write
 * would need one of these easily-grep-able primitives; there is no
 * legitimate reason for either file's actual code to ever contain any of
 * them.
 */
it('never lets WalletLedgerAuditor or the wallet:audit command write anything', function () {
    $files = [
        app_path('Services/Wallet/WalletLedgerAuditor.php'),
        app_path('Console/Commands/AuditWalletLedger.php'),
    ];

    $forbidden = [
        '->update(',
        '->save(',
        '->create(',
        '->delete(',
        '->forceDelete(',
        '::destroy(',
        '->insert(',
        '->increment(',
        '->decrement(',
        'DB::insert(',
        'DB::update(',
        'DB::delete(',
        'DB::statement(',
        'WalletTransactionService',
        '->reverse(',
        '->confirm(',
        '->markFailed(',
        '--fix',
    ];

    $offenders = [];

    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");

        $code = stripPhpComments(file_get_contents($file));

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = basename($file).' contains '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Strips PHP comments (// # /* and doc-comments) from source, keeping only
 * the actual code — mirrors the exact technique used in
 * tests/Architecture/PaymentsDomainBoundaryTest and
 * tests/Architecture/PayoutsDomainBoundaryTest, extracted here as a
 * reusable helper since this file needs it for two independent reasons
 * (explaining forbidden method names in prose, and — separately — needing
 * to reference the command's own class name in this file's test code).
 */
function stripPhpComments(string $contents): string
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
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

it('never lets the wallet:audit command declare a --fix option', function () {
    $signature = (new ReflectionClass(AuditWalletLedger::class))
        ->getDefaultProperties()['signature'];

    expect($signature)->not->toContain('--fix')
        ->and($signature)->not->toContain('fix');
});
