<?php

namespace App\Domain\Wallet\Exceptions;

class TransactionNotPendingException extends WalletTransactionException
{
    public static function cannotConfirm(): self
    {
        return new self('Only pending transactions can be confirmed.');
    }

    public static function cannotMarkFailed(): self
    {
        return new self('Only pending transactions can be marked as failed.');
    }
}
