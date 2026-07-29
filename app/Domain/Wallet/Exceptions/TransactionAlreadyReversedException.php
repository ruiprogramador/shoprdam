<?php

namespace App\Domain\Wallet\Exceptions;

class TransactionAlreadyReversedException extends WalletTransactionException
{
    public static function create(): self
    {
        return new self('This transaction has already been reversed.');
    }
}
