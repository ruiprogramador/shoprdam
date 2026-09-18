<?php

/**
 * The acceptance criterion Phase 1 of feat/financial-reconciliation exists
 * to prove: reconciliation is detection + persistence + observability ONLY
 * (docs/financial/RECONCILIATION.md §4/§5/§14). A plain source scan,
 * mirroring tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest —
 * no dedicated architecture-testing package for one boundary.
 *
 * Deliberately does NOT forbid comparing against
 * PaymentAttemptStatus::Succeeded/Failed/Claimed as *values* —
 * App\Domain\Payments\Services\ReconciliationClassifier legitimately reads
 * and compares an attempt's own status to classify a mismatch (§8). What
 * this test forbids is anything that could ever construct a financial
 * effect: the canonical Wallet writer, the canonical settlement/recovery
 * services, and any direct mutation of the financial models this domain
 * already treats as append-only/canonically-owned elsewhere.
 */

/**
 * Strips comments so this class's own explanatory docblocks (which
 * legitimately name every forbidden class, to explain why it's forbidden)
 * are never mistaken for real code referencing them — mirrors
 * tests/Architecture/PaymentsDomainBoundaryTest's own token-stripping
 * approach exactly.
 */
function codeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all(file_get_contents($path)) as $token) {
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

it('never lets reconciliation production code reference a Wallet-mutating class or the canonical settlement/recovery services', function () {
    $files = [
        app_path('Domain/Payments/Services/ProviderReconciler.php'),
        app_path('Domain/Payments/Services/ReconciliationClassifier.php'),
        app_path('Domain/Payments/Services/ReconciliationFindingRepository.php'),
        app_path('Console/Commands/ReconcilePaymentsAgainstProvider.php'),
    ];

    $forbidden = [
        // The canonical Wallet writer — see tests/Architecture/WalletLedgerSingleWriterTest.
        'WalletTransactionService',
        'WalletService',
        'StoreWalletTransaction',
        // The canonical settlement/recovery paths — see
        // docs/financial/RECONCILIATION.md §3/§5/§14 for exactly why
        // reconciliation must never call any of these, proven by execution
        // trace, not merely asserted.
        'PaymentEventProcessor',
        'PaymentAttemptRecoveryService',
        'PayoutAttemptRecoveryService',
        'PayoutEventProcessor',
        '->finalizeAttempt(',
        // The provider webhook/replay inbox — reconciliation must never
        // construct a row here (§13 of the design doc).
        'PaymentProviderEvent::create',
        // Direct mutation of the financial aggregates this domain already
        // treats as canonically-owned elsewhere.
        'OrderStatus::',
        '$order->update(',
        '$payment->update(',
        '$attempt->update(',
    ];

    $offenders = [];

    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");

        $code = codeWithoutComments($file);

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = basename($file).' references '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The Eloquent model reconciliation is allowed to write to at all — see
 * docs/financial/RECONCILIATION.md §9/§18: ReconciliationFinding is purely
 * observational, never a financial ledger. This test proves the model's own
 * write surface never reaches into a financial table by construction: it
 * has no relation method returning anything but itself/PaymentAttempt/Admin
 * (both read-only `belongsTo` lookups), and defines no method that touches
 * `store_wallet_transactions`, `store_wallets`, `payments`, or `orders`.
 */
it('never lets ReconciliationFinding\'s own model reference a financial table it does not own', function () {
    $file = app_path('Domain/Payments/Models/ReconciliationFinding.php');

    expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");

    $code = codeWithoutComments($file);

    $forbidden = ['store_wallets', 'store_wallet_transactions', "'payments'", "'orders'"];

    $offenders = [];

    foreach ($forbidden as $needle) {
        if (str_contains($code, $needle)) {
            $offenders[] = $needle;
        }
    }

    expect($offenders)->toBe([]);
});
