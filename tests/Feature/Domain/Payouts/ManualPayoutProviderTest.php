<?php

use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Payouts\Manual\ManualPayoutProvider;
use Nnjeim\World\Models\Currency;

it('deterministically mints the same reference for repeated calls with the same idempotency key', function () {
    $currencyId = Currency::query()->where('code', 'EUR')->value('id');

    $payout = new Payout([
        'amount' => '80.00',
        'currency_id' => $currencyId,
    ]);
    $payout->id = 42;
    $payout->setRelation('currency', Currency::find($currencyId));

    $attempt = new PayoutAttempt(['idempotency_key' => 'payout-42-attempt-7']);
    $attempt->payout_id = 42;
    $attempt->setRelation('payout', $payout);

    $provider = new ManualPayoutProvider;

    $first = $provider->createTransfer($attempt);
    $second = $provider->createTransfer($attempt);

    expect($first->providerReference)->toBe($second->providerReference)
        ->and($first->providerReference)->toBe('manual-payout-42-attempt-7')
        ->and($first->amount)->toBe('80.00')
        ->and($first->currency)->toBe('EUR')
        ->and($first->correlationId)->toBe('42');
});
