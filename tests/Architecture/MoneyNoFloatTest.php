<?php

/**
 * "Never use float for money" (PAYOUT-14, DBPORT-06), made mechanical.
 * Until this test, the guarantee rested on a manual audit (see
 * docs/architecture/DATABASE-SUPPORT.md §10 and PAYOUT-14's own note): every
 * amount is an exact decimal string (`decimal:2` casts), all arithmetic and
 * comparison is bcmath, and conversion to provider minor units goes through
 * App\Domain\Payments\MinorUnits (bcmul). This test keeps it that way.
 *
 * ## What is scanned
 *
 * The money-bearing production code, comments stripped (tokenizer-based, so
 * prose explaining why floats are forbidden is never mistaken for code):
 *
 * - app/Domain/ — Payments, Payouts, Wallet, Orders, Catalog (prices) and
 *   Inventory (quantities, no money — scanned anyway, it has no floats);
 * - app/Services/Wallet/ — the ledger writer and auditor;
 * - app/Payments/, app/Payouts/ — provider adapters;
 * - the money-bearing models outside app/Domain: Order, OrderItem,
 *   StoreWallet, StoreWalletTransaction.
 *
 * Forbidden there: float/double casts, float literals, the `float`/`double`
 * type, floatval()/doubleval(), the float rounding/formatting helpers
 * (round, floor, ceil, fmod, number_format), and a 'float'/'double'/'real'
 * cast string (an Eloquent `$casts` entry or settype()).
 *
 * ## What it does NOT prove
 *
 * - A float produced implicitly is invisible to a token scan: `/` division,
 *   arithmetic on a numeric string, or json_decode() of a provider payload
 *   (e.g. EasyPay's `value`, which the adapter casts to string before
 *   MinorUnits — a boundary this test cannot see).
 * - Code outside the scanned roots (controllers, commands, frontend) is not
 *   covered; money arithmetic does not belong there.
 * - It says nothing about the decimal columns themselves; schema precision
 *   is the migrations' concern (and the 2-decimal currency limitation is a
 *   documented non-guarantee in docs/financial/INVARIANTS.md).
 */

/** @return list<string> repo-relative roots and files holding money code */
function moneyNoFloatScope(): array
{
    return [
        'app/Domain',
        'app/Services/Wallet',
        'app/Payments',
        'app/Payouts',
        'app/Models/Order.php',
        'app/Models/OrderItem.php',
        'app/Models/StoreWallet.php',
        'app/Models/StoreWalletTransaction.php',
    ];
}

/** @return array<string, string> repo-relative path => source */
function moneyNoFloatLoad(): array
{
    $files = [];

    foreach (moneyNoFloatScope() as $entry) {
        $path = base_path($entry);
        $paths = is_dir($path)
            ? array_map(
                fn (SplFileInfo $file) => $file->getPathname(),
                iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)), false),
            )
            : [$path];

        foreach ($paths as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
            $files[$relative] = file_get_contents($file);
        }
    }

    ksort($files);

    return $files;
}

/**
 * @param  array<string, string>  $files  path => PHP source
 * @return list<string> "path:line what"
 */
function moneyNoFloatOffenders(array $files): array
{
    $identifiers = ['float', 'double', 'floatval', 'doubleval', 'round', 'floor', 'ceil', 'fmod', 'number_format'];
    $castStrings = ['float', 'double', 'real'];

    $offenders = [];

    foreach ($files as $path => $source) {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            $what = match (true) {
                $id === T_DOUBLE_CAST => "float cast {$text}",
                $id === T_DNUMBER => "float literal {$text}",
                $id === T_STRING && in_array(strtolower($text), $identifiers, true) => "float identifier {$text}",
                $id === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(substr($text, 1, -1)), $castStrings, true) => "float cast string {$text}",
                default => null,
            };

            if ($what !== null) {
                $offenders[] = "{$path}:{$line} {$what}";
            }
        }
    }

    return $offenders;
}

// ---------------------------------------------------------------------
// Real scan of the real tree
// ---------------------------------------------------------------------

it('scans a non-vacuous set of money-bearing files, including the canonical money paths', function () {
    $files = moneyNoFloatLoad();

    expect(count($files))->toBeGreaterThan(100)
        ->and(array_keys($files))->toContain(
            'app/Services/Wallet/WalletTransactionService.php',
            'app/Domain/Payments/MinorUnits.php',
            'app/Domain/Payments/Services/PaymentEventProcessor.php',
            'app/Domain/Payouts/Services/PayoutService.php',
            'app/Domain/Orders/Services/OrderCreationService.php',
            'app/Payments/EasyPay/EasyPayPaymentProvider.php',
            'app/Models/StoreWalletTransaction.php',
        );
});

it('never lets money-bearing production code use a float (PAYOUT-14, DBPORT-06)', function () {
    expect(moneyNoFloatOffenders(moneyNoFloatLoad()))->toBe([]);
});

// ---------------------------------------------------------------------
// Self-test
// ---------------------------------------------------------------------

it('[self-test] the detector flags every float shape, and ignores comments, bcmath and exact decimal casts', function () {
    $offending = <<<'PHP'
<?php
$a = (float) $amount;
$b = (double) $amount;
$c = $amount * 1.5;
function d(float $x): float { return $x; }
$e = floatval($amount);
$f = round($amount * 100);
$g = number_format($amount, 2);
$casts = ['amount' => 'float'];
PHP;

    $clean = <<<'PHP'
<?php
// never (float) $amount, never round() or 1.5 — see the docblock
/** Every amount is a decimal string, never a float. */
$a = bcadd($amount, '0.10', 2);
$b = bccomp($a, $b, 2) === 0;
$c = is_float($x) ? throw new InvalidArgumentException() : (int) bcmul($a, '100', 0);
$casts = ['amount' => 'decimal:2', 'quantity' => 'integer'];
PHP;

    $found = moneyNoFloatOffenders(['offending.php' => $offending, 'clean.php' => $clean]);

    expect($found)->toBe([
        'offending.php:2 float cast (float)',
        'offending.php:3 float cast (double)',
        'offending.php:4 float literal 1.5',
        'offending.php:5 float identifier float',
        'offending.php:5 float identifier float',
        'offending.php:6 float identifier floatval',
        'offending.php:7 float identifier round',
        'offending.php:8 float identifier number_format',
        "offending.php:9 float cast string 'float'",
    ]);
});
