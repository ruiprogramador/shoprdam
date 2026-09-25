<?php

/**
 * Structural guardrail for the engineering contract (CLAUDE.md) — not a prose
 * checker, and deliberately the same kind of floor as
 * tests/Architecture/FinancialContractRegistryTest. It asserts only that:
 *
 * 1. every repository path and every App\ class the contract points to still
 *    exists — a contract that sends future sessions to renamed or deleted
 *    documents, tests or classes is stale without anyone noticing;
 * 2. the two rules whose exact wording is the rule itself, and which no other
 *    test enforces, are still stated: the correctness hierarchy and the Git
 *    responsibility boundary. Everything else in the contract stays free to
 *    be reworded.
 *
 * It does not, and cannot, verify that the contract's rules are true of the
 * code; the Architecture tests the contract lists (its §12) do that for the
 * rules that are mechanical, and review does it for the rest.
 */
function engineeringContractSource(): string
{
    return file_get_contents(base_path('CLAUDE.md'));
}

/**
 * Backticked repository paths (`docs/...`, `tests/...`, a trailing `/` for a
 * directory, `*` for a glob) and backticked App\ class names.
 *
 * @return array{paths: list<string>, classes: list<string>}
 */
function engineeringContractReferences(string $markdown): array
{
    preg_match_all('/`((?:\.github|app|bootstrap|config|database|docs|routes|tests)\/[^`\s]*)`/', $markdown, $paths);
    preg_match_all('/`(App(?:\\\\[A-Za-z0-9_]+)+)`/', $markdown, $classes);

    return [
        'paths' => array_values(array_unique($paths[1])),
        'classes' => array_values(array_unique($classes[1])),
    ];
}

/**
 * @param  array{paths: list<string>, classes: list<string>}  $references
 * @return list<string> references that do not resolve to anything in the repository
 */
function engineeringContractDeadReferences(array $references): array
{
    $dead = [];

    foreach ($references['paths'] as $path) {
        $exists = str_contains($path, '*')
            ? glob(base_path($path)) !== []
            : file_exists(base_path(rtrim($path, '/')));

        if (! $exists) {
            $dead[] = $path;
        }
    }

    foreach ($references['classes'] as $class) {
        $file = base_path('app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php');

        if (! is_file($file)) {
            $dead[] = $class;
        }
    }

    return $dead;
}

it('keeps the engineering contract present, with every referenced path and class resolving in the repository', function () {
    expect(is_file(base_path('CLAUDE.md')))->toBeTrue('Expected CLAUDE.md at the repository root.');

    $references = engineeringContractReferences(engineeringContractSource());

    expect(count($references['paths']))->toBeGreaterThan(20)
        ->and(count($references['classes']))->toBeGreaterThan(3)
        ->and(engineeringContractDeadReferences($references))->toBe([]);
});

it('never lets the contract silently drop one of its non-negotiable rules', function () {
    // Whitespace-normalized, like FinancialContractRegistryTest: a line wrap
    // inside a Markdown paragraph is not a content change.
    $contract = preg_replace('/\s+/', ' ', engineeringContractSource());

    // Only rules whose wording IS the rule and that nothing else enforces:
    // the canonical correctness order (any change to it is a change of the
    // rule itself) and the Git responsibility boundary. Rules already
    // enforced by their own Architecture test, and prose that should stay
    // free to be reworded, are deliberately not pinned here.
    $markers = [
        'financial correctness > crash safety > idempotency > concurrency safety > clean domain boundaries > extensibility > naming/aesthetics',
        'Staging, committing and pushing are exclusively the user\'s responsibility.',
    ];

    $missing = array_values(array_filter($markers, fn (string $marker) => ! str_contains($contract, $marker)));

    expect($missing)->toBe([]);
});

it('[self-test] the reference checker extracts paths, globs, directories and App\\ classes, and flags only what does not exist', function () {
    $markdown = implode("\n", [
        'See `docs/financial/INVARIANTS.md`, `docs/financial/*`, `docs/wallet/` and `.github/workflows/`.',
        'Missing: `docs/nowhere/GONE.md`, `tests/Architecture/NoSuchTest.php`, `docs/nowhere/*`.',
        'Classes: `App\Services\Wallet\WalletTransactionService`, `App\Domain\Payments\NoSuchClass`.',
        'Ignored: `vendor/bin/pint`, `php artisan test`, `PaymentService`, `*_concurrency_test`.',
    ]);

    $references = engineeringContractReferences($markdown);

    expect($references['paths'])->toBe([
        'docs/financial/INVARIANTS.md',
        'docs/financial/*',
        'docs/wallet/',
        '.github/workflows/',
        'docs/nowhere/GONE.md',
        'tests/Architecture/NoSuchTest.php',
        'docs/nowhere/*',
    ])
        ->and($references['classes'])->toBe([
            'App\Services\Wallet\WalletTransactionService',
            'App\Domain\Payments\NoSuchClass',
        ])
        ->and(engineeringContractDeadReferences($references))->toBe([
            'docs/nowhere/GONE.md',
            'tests/Architecture/NoSuchTest.php',
            'docs/nowhere/*',
            'App\Domain\Payments\NoSuchClass',
        ]);
});
