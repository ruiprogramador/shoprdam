<?php

/**
 * Mirrors tests/Architecture/PaymentsDomainBoundaryTest.php: the generic
 * payouts domain (App\Domain\Payouts) must never know a specific provider
 * adapter exists — it may only ever talk to PayoutProviderContract /
 * PayoutProviderManager. Provider-specific code belongs behind
 * App\Payouts\{Provider}\* instead (see App\Payouts\Manual\ManualPayoutProvider).
 * This is what lets a future automatic provider (Stripe Connect, a SEPA
 * API) be added without ever touching PayoutService/PayoutEventProcessor.
 */
it('never lets App\Domain\Payouts import a concrete provider adapter namespace', function () {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/Domain/Payouts'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

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

        if (preg_match('/^use\s+App\\\\Payouts\\\\/m', $code) || str_contains($code, '\\App\\Payouts\\')) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
