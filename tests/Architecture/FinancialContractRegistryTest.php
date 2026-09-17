<?php

/**
 * Structural guardrail for the Financial Architecture Contract
 * (docs/financial/*) — not a Markdown formatting/prose checker. Asserts
 * only that:
 *
 * 1. the five contract documents exist;
 * 2. every canonical invariant ID this branch formalized is still present
 *    in the registry (a future edit can reword an invariant's prose freely,
 *    but silently deleting an ID — the thing other docs/PRs/agents
 *    reference — is caught here);
 * 3. the critical "not supported / dormant" limitations that a future
 *    developer or agent could otherwise mistake for supported features
 *    (because the enum/column/category exists) are still stated somewhere
 *    in the registry.
 *
 * Deliberately a flat list of stable substrings, not a parser — see
 * docs/financial/INVARIANTS.md's own docblock-equivalent reasoning and this
 * branch's design record (Phase 10: "não construas um parser PHP
 * complexo"). This is a floor, not a substitute for actually reading the
 * documents.
 */
it('keeps every canonical financial architecture document present', function () {
    $files = [
        'ARCHITECTURE.md',
        'INVARIANTS.md',
        'STATE-MACHINES.md',
        'MONEY-FLOWS.md',
        'FAILURE-MODEL.md',
    ];

    foreach ($files as $file) {
        expect(file_exists(base_path("docs/financial/{$file}")))->toBeTrue("Expected docs/financial/{$file} to exist.");
    }
});

it('never lets the invariant registry silently lose a canonical ID', function () {
    $registry = file_get_contents(base_path('docs/financial/INVARIANTS.md'));

    $requiredIds = [
        ...array_map(fn ($n) => sprintf('LEDGER-%02d', $n), range(1, 8)),
        ...array_map(fn ($n) => sprintf('PAYOUT-%02d', $n), range(1, 18)),
        ...array_map(fn ($n) => sprintf('PAYMENT-%02d', $n), range(1, 10)),
        ...array_map(fn ($n) => sprintf('CROSS-%02d', $n), range(1, 14)),
    ];

    $missing = array_values(array_filter(
        $requiredIds,
        fn (string $id) => ! str_contains($registry, $id)
    ));

    expect($missing)->toBe([]);
});

it('never lets the registry silently drop a critical known-limitation entry', function () {
    // Whitespace-normalized (line wraps inside a Markdown paragraph are not
    // meaningful — a hard newline mid-sentence renders identically to a
    // space) so this test only ever fails when the actual *content* of a
    // marker phrase is gone, never on how the paragraph happens to wrap.
    $registry = preg_replace('/\s+/', ' ', file_get_contents(base_path('docs/financial/INVARIANTS.md')));

    $requiredLimitationMarkers = [
        'Partial refunds are not supported',
        'reserved_balance` does not exist',
        'cancelled`/`reversed` are DORMANT',
        '`PaymentStatus::Failed` is DORMANT',
        'balance_after` cannot be used to reconstruct historical',
        'No DB trigger enforces `StoreWalletTransaction` immutability',
        'No true parallel (PostgreSQL/MySQL multi-connection) concurrency tests',
        'No automatic/bank payout provider exists',
        'have no production producer',
        'Disputes do not exist as a feature',
        'EasyPay refunds are not supported',
        'precision` (for zero-decimal currencies',
        'No payout-attempt health/observability command exists',
        'no self-service path to close their account at all, ever',
    ];

    $missing = array_values(array_filter(
        $requiredLimitationMarkers,
        fn (string $marker) => ! str_contains($registry, $marker)
    ));

    expect($missing)->toBe([]);
});

it('never lets the critical cascade-deletion finding disappear from the failure model, or its resolution get silently unmarked', function () {
    $failureModel = file_get_contents(base_path('docs/financial/FAILURE-MODEL.md'));

    // harden/financial-history-cascade-protection closed this gap — see
    // INVARIANTS.md's CROSS-14 (now ENFORCED) and the two tests named
    // below. This test's job changed accordingly: the historical finding
    // itself must stay documented (deleting the paragraph instead of
    // actually keeping the fix in place is exactly what this guards
    // against), and now *also* its RESOLVED status must stay explicit — a
    // future edit reverting the schema to cascadeOnDelete() without
    // updating this doc back to describing an active gap would otherwise
    // leave the contract silently lying about being safe.
    expect($failureModel)->toContain('Critical finding — financial history is cascade-deletable')
        ->and($failureModel)->toContain('harden/financial-history-cascade-protection')
        ->and($failureModel)->toContain('Status: RESOLVED')
        ->and($failureModel)->toContain('## Resolution')
        ->and($failureModel)->toContain('FinancialHistoryCascadeMigrationSafetyTest')
        ->and($failureModel)->toContain('ProfileAccountDeletionFinancialHistoryTest');
});

it('keeps CROSS-14 documented as ENFORCED, never silently reverted to PARTIALLY ENFORCED', function () {
    $registry = file_get_contents(base_path('docs/financial/INVARIANTS.md'));

    expect(preg_match('/\| CROSS-14 \|[^\n]*\|/', $registry, $matches))->toBe(1, 'Expected to find the CROSS-14 row in INVARIANTS.md.');

    $row = $matches[0];

    expect($row)->toContain('**ENFORCED**')
        ->and($row)->not->toContain('PARTIALLY ENFORCED');
});
