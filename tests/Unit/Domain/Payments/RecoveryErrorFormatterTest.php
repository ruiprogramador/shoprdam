<?php

use App\Domain\Payments\RecoveryErrorFormatter;

/**
 * RecoveryErrorFormatter never persists/renders a raw exception message or
 * stored diagnostic string, under any condition — a fail-closed guarantee
 * by construction (only structured metadata this domain itself controls
 * the shape of is ever built), not a denylist that could miss a future
 * provider secret format. Every test below proves that structurally: no
 * test needs to enumerate secret shapes, because none of them can ever
 * reach the output regardless of what they look like.
 */
it('summarizes an exception using only structured metadata, never the message', function () {
    $summary = RecoveryErrorFormatter::summarizeForAudit(
        new RuntimeException('Contains anything at all — even something totally ordinary'),
        'stripe',
    );

    expect($summary)->toBe('stripe / '.RuntimeException::class)
        ->and($summary)->not->toContain('Contains anything at all');
});

it('never lets a secret embedded in the exception message survive summarizeForAudit, for any message shape', function (string $message) {
    $summary = RecoveryErrorFormatter::summarizeForAudit(new RuntimeException($message), 'stripe');

    expect($summary)->not->toContain($message)
        ->and($summary)->toBe('stripe / '.RuntimeException::class);
})->with([
    'a Stripe live secret key' => ['leaked sk_live_51ABCDEFghijklmno'],
    'a webhook signing secret' => ['whsec_abcdef1234567890'],
    'an Authorization header' => ['Authorization: Bearer abc.def.ghi'],
    'a raw card number' => ['Card 4242 4242 4242 4242 was declined'],
    'a raw JSON payload fragment' => ['Response body: {"client_secret": "abc"}'],
    'a novel/future secret shape a denylist would never have anticipated' => ['x-future-provider-token-format-9f8e7d: zzz'],
]);

it('includes the provider and exception class for an otherwise plain exception', function () {
    expect(RecoveryErrorFormatter::summarizeForAudit(new RuntimeException('x'), 'easypay'))
        ->toBe('easypay / '.RuntimeException::class);
});

it('includes the HTTP status when the exception exposes getHttpStatus()', function () {
    $exception = new class('failure') extends RuntimeException
    {
        public function getHttpStatus(): int
        {
            return 503;
        }
    };

    expect(RecoveryErrorFormatter::summarizeForAudit($exception, 'stripe'))
        ->toBe('stripe / '.$exception::class.' / http 503');
});

it('includes the HTTP status when the exception exposes a public status property', function () {
    $exception = new class('failure') extends RuntimeException
    {
        public int $status = 429;
    };

    expect(RecoveryErrorFormatter::summarizeForAudit($exception, 'easypay'))
        ->toBe('easypay / '.$exception::class.' / http 429');
});

it('includes retryability only when the caller supplies it', function () {
    $withoutRetryable = RecoveryErrorFormatter::summarizeForAudit(new RuntimeException('x'), 'stripe');
    $retryable = RecoveryErrorFormatter::summarizeForAudit(new RuntimeException('x'), 'stripe', true);
    $nonRetryable = RecoveryErrorFormatter::summarizeForAudit(new RuntimeException('x'), 'stripe', false);

    expect($withoutRetryable)->not->toContain('retryable')
        ->and($retryable)->toEndWith('/ retryable')
        ->and($nonRetryable)->toEndWith('/ non-retryable');
});

it('reports whether a diagnostic string is on record without ever exposing it', function () {
    expect(RecoveryErrorFormatter::hasStoredMessage(null))->toBeFalse()
        ->and(RecoveryErrorFormatter::hasStoredMessage(''))->toBeFalse()
        ->and(RecoveryErrorFormatter::hasStoredMessage('   '))->toBeFalse()
        ->and(RecoveryErrorFormatter::hasStoredMessage('sk_live_51SOMETHINGSENSITIVE'))->toBeTrue();
});
