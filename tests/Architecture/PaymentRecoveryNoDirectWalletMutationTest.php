<?php

/**
 * The acceptance criterion feat/payments-admin-recovery-tools exists to
 * prove: there is still exactly one canonical settlement path (provider
 * evidence/event -> PaymentEventProcessor -> Wallet + Payment domain), never
 * a second one from the admin UI straight into a financial mutation. A
 * plain source scan, mirroring PaymentsDomainBoundaryTest — no dedicated
 * architecture-testing package for one boundary.
 */
it('never lets the admin payment recovery path reference a Wallet-mutating class directly', function () {
    $files = [
        app_path('Http/Controllers/Admin/PaymentRecoveryController.php'),
        app_path('Domain/Payments/Services/PaymentAttemptRecoveryService.php'),
        app_path('Policies/PaymentRecoveryPolicy.php'),
    ];

    // Anything that could move a balance or fabricate a settled state —
    // not just the obvious WalletTransactionService, but the Eloquent model
    // and the enum values a shortcut could set directly on it.
    $forbidden = [
        'WalletTransactionService',
        'WalletService',
        'StoreWalletTransaction',
        'PaymentStatus::Paid',
        'PaymentAttemptStatus::Succeeded',
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
