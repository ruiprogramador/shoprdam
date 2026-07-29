<?php

namespace App\Domain\Wallet;

use RuntimeException;

final readonly class WalletTransactionReference
{
    public function __construct(
        public string $provider,
        public string $reference,
    ) {
        if (trim($provider) === '' || trim($reference) === '') {
            throw new RuntimeException(
                'Transaction reference requires provider and reference.'
            );
        }
    }
}
