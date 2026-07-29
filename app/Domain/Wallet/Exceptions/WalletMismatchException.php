<?php

namespace App\Domain\Wallet\Exceptions;

class WalletMismatchException extends WalletTransactionException
{
    public static function forReversal(): self
    {
        return new self('Cannot reverse a transaction using a different wallet.');
    }
}
