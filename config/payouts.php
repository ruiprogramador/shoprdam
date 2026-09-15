<?php

use App\Payouts\Manual\ManualPayoutProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Payout provider registry
    |--------------------------------------------------------------------------
    |
    | Maps a driver name (stored on payout_attempts.provider) to the
    | provider's PayoutProviderContract implementation. App\Providers\PayoutServiceProvider
    | registers each one against App\Domain\Payouts\PayoutProviderManager —
    | adding a provider is adding an entry here plus its class, never editing
    | the manager or App\Domain\Payouts\Services\PayoutService.
    |
    */

    'providers' => [
        'manual' => [
            'provider' => ManualPayoutProvider::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | How many stale/leased-but-expired payout_attempts rows
    | App\Console\Commands\ReconcileOrphanedPayoutAttempts loads per
    | chunkById() page — mirrors payments.reconciliation_chunk_size.
    |
    */

    'reconciliation_chunk_size' => (int) env('PAYOUTS_RECONCILIATION_CHUNK_SIZE', 200),

];
