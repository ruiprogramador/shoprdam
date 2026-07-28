<?php

namespace App\Domain\Wallet\Exceptions;

class CannotReverseReversalException extends WalletTransactionException
{
    public static function create(): self
    {
        return new self('Cannot reverse a transaction that is itself a reversal.');
    }
}
