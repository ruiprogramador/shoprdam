<?php

namespace App\Domain\Wallet\Exceptions;

class InsufficientWalletBalanceException extends WalletTransactionException
{
    public static function forNewTransaction(): self
    {
        return new self('Insufficient wallet balance for this transaction.');
    }

    public static function forConfirmation(): self
    {
        return new self('Insufficient wallet balance to confirm this transaction.');
    }
}
