<?php

namespace App\Domain\Wallet\DTOs;

/**
 * The full result of `php artisan wallet:audit` — mirrors
 * App\Domain\Payments\DTOs\PaymentsHealthReport's shape (isHealthy()/toArray()).
 * `$mismatches` holds only the WalletAuditResult entries that failed
 * `isConsistent()`; `$totalAudited` counts every wallet actually checked,
 * consistent or not.
 */
final readonly class WalletAuditReport
{
    /** @param  list<WalletAuditResult>  $mismatches */
    public function __construct(
        public int $totalAudited,
        public array $mismatches,
    ) {}

    public function isHealthy(): bool
    {
        return $this->mismatches === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'total_audited' => $this->totalAudited,
            'mismatch_count' => count($this->mismatches),
            'mismatches' => array_map(fn (WalletAuditResult $result) => $result->toArray(), $this->mismatches),
        ];
    }
}
