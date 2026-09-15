<?php

/**
 * Mirrors tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest.php:
 * proves there is still exactly one canonical settlement path for payouts
 * (manual confirmation or a future provider outcome -> PayoutEventProcessor ->
 * Wallet + Payout/PayoutAttempt), never a second one from the admin
 * recovery/confirmation surface straight into a financial mutation or a
 * fabricated terminal state.
 *
 * PayoutEventProcessor itself is deliberately NOT scanned here — it IS the
 * canonical path and is expected to reference WalletTransactionService and
 * write PayoutStatus::Succeeded/PayoutAttemptStatus::Succeeded; scanning it
 * would make this test meaningless. What must never happen is the admin
 * controller/policy/recovery-service shortcutting around it.
 */
it('never lets the admin payout recovery path reference a Wallet-mutating class or fabricate a terminal state directly', function () {
    $files = [
        app_path('Http/Controllers/Admin/PayoutRecoveryController.php'),
        app_path('Domain/Payouts/Services/PayoutAttemptRecoveryService.php'),
        app_path('Policies/PayoutRecoveryPolicy.php'),
    ];

    $forbidden = [
        'WalletTransactionService',
        'WalletService',
        'StoreWalletTransaction',
        'PayoutStatus::Succeeded',
        'PayoutStatus::Cancelled',
        'PayoutStatus::Failed',
        'PayoutAttemptStatus::Succeeded',
        'PayoutAttemptStatus::Failed',
    ];

    $offenders = [];

    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");

        $contents = file_get_contents($file);

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = basename($file).' references '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The other half of the same guarantee: PayoutService::abandon() — the only
 * place a reservation is ever released — must be the only place in the
 * entire Payouts domain that calls WalletTransactionService::reverse().
 * A second call site would mean a second, undocumented reversal path.
 */
it('never lets anything but PayoutService::abandon() call WalletTransactionService::reverse() for a payout reversal', function () {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Domain/Payouts'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);

        if (! str_contains($contents, '->reverse(')) {
            continue;
        }

        if (basename($path) !== 'PayoutService.php') {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
