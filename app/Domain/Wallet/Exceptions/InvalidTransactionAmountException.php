<?php

namespace App\Domain\Wallet\Exceptions;

class InvalidTransactionAmountException extends WalletTransactionException
{
    public static function nonPositive(): self
    {
        return new self('Transaction amount must be greater than zero.');
    }
}
