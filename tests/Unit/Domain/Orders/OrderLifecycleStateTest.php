<?php

use App\Domain\Orders\Enums\OrderLifecycleState;
use App\Domain\Orders\Exceptions\InvalidOrderTransitionException;

/**
 * The transition matrix from docs/financial/ORDER-LIFECYCLE.md §4, checked
 * exhaustively: every one of the 16 (from, to) pairs is asserted, so adding
 * a state or an edge without updating this test fails loudly. Pure — no
 * database, no container.
 */
$allowed = [
    'pending' => ['paid', 'failed'],
    'failed' => ['paid'],
    'paid' => ['refunded'],
    'refunded' => [],
];

it('allows exactly the documented transitions and forbids every other pair', function () use ($allowed) {
    foreach (OrderLifecycleState::cases() as $from) {
        foreach (OrderLifecycleState::cases() as $to) {
            $expected = in_array($to->value, $allowed[$from->value], true);

            expect($from->canTransitionTo($to))
                ->toBe($expected, "{$from->value} -> {$to->value} should be ".($expected ? 'allowed' : 'forbidden'));
        }
    }
});

it('never lists a same-state pair as a transition — repeats are the service\'s explicit idempotent no-op, not a matrix edge', function () {
    foreach (OrderLifecycleState::cases() as $state) {
        expect($state->canTransitionTo($state))->toBeFalse();
    }
});

it('has exactly one terminal state: refunded', function () {
    $terminal = array_values(array_filter(
        OrderLifecycleState::cases(),
        fn (OrderLifecycleState $s) => $s->isTerminal(),
    ));

    expect($terminal)->toBe([OrderLifecycleState::Refunded]);
});

it('keeps failed non-terminal — a payment-attempt failure must not end an otherwise payable Order (ORDER-06)', function () {
    expect(OrderLifecycleState::Failed->isTerminal())->toBeFalse()
        ->and(OrderLifecycleState::Failed->canTransitionTo(OrderLifecycleState::Paid))->toBeTrue();
});

it('never allows leaving paid except through a refund, and never returns to pending from anywhere', function () {
    expect(OrderLifecycleState::Paid->allowedTransitions())->toBe([OrderLifecycleState::Refunded]);

    foreach (OrderLifecycleState::cases() as $from) {
        expect($from->canTransitionTo(OrderLifecycleState::Pending))->toBeFalse();
    }
});

it('backs every case with the exact order_statuses slug the seeder defines', function () {
    expect(array_map(fn (OrderLifecycleState $s) => $s->value, OrderLifecycleState::cases()))
        ->toBe(['pending', 'paid', 'failed', 'refunded']);
});

it('fails closed on a slug it does not know instead of coercing it into a known state', function (?string $slug) {
    expect(fn () => OrderLifecycleState::fromSlug($slug))
        ->toThrow(InvalidOrderTransitionException::class, 'unrecognized state');
})->with([null, '', 'accepted', 'cancelled', 'PAID']);
