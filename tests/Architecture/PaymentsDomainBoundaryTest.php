<?php

/**
 * Protects the one boundary this whole refactor exists to establish: the
 * generic payments domain (App\Domain\Payments) must never know a specific
 * provider's SDK exists. Provider-specific code belongs behind
 * App\Payments\{Provider}\* instead — see docs/wallet/integrations.md.
 *
 * Deliberately a plain file scan rather than pulling in a dedicated
 * architecture-testing package for one boundary.
 */
it('never lets App\Domain\Payments import a provider SDK namespace', function () {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/Domain/Payments'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/^use\s+Stripe\\\\/m', $contents) || str_contains($contents, '\\Stripe\\')) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
