<?php

namespace App\Domain\Wallet\Exceptions;

class TransactionNotCompletedException extends WalletTransactionException
{
    public static function cannotReverse(): self
    {
        return new self('Only completed transactions can be reversed.');
    }
}
