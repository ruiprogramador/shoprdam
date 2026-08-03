<?php

use App\Payments\Stripe\StripeAmount;

it('converts a 2-decimal amount into Stripe minor units', function (string $amount, int $expected) {
    expect(StripeAmount::toMinorUnits($amount))->toBe($expected);
})->with([
    ['10.00', 1000],
    ['10', 1000],
    ['42.50', 4250],
    ['0.01', 1],
    ['1.00', 100],
    ['999999.99', 99999999],
]);
