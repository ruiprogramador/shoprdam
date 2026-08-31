<?php

use App\Payments\Stripe\StripeEventTranslator;
use App\Payments\Stripe\StripePaymentProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment provider registry
    |--------------------------------------------------------------------------
    |
    | Maps a driver name (stored on payment_attempts.provider) to the
    | provider's PaymentProviderContract and ProviderEventTranslator
    | implementations. App\Providers\PaymentServiceProvider registers each
    | one against App\Domain\Payments\PaymentProviderManager and
    | App\Domain\Payments\ProviderEventTranslatorManager — adding a new
    | provider is adding an entry here plus its two classes, never editing
    | either manager or App\Domain\Payments\Services\PaymentService.
    |
    | Credentials/config for each provider stay under their own key in
    | config/services.php (e.g. `services.stripe.*`) — this file is only
    | for genuinely provider-agnostic, shared payment-domain configuration.
    |
    */

    'providers' => [
        'stripe' => [
            'provider' => StripePaymentProvider::class,
            'translator' => StripeEventTranslator::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | How many stale/leased-but-expired payment_attempts rows
    | App\Console\Commands\ReconcileOrphanedPaymentAttempts loads per
    | chunkById() page, instead of loading every candidate at once.
    |
    */

    'reconciliation_chunk_size' => (int) env('PAYMENTS_RECONCILIATION_CHUNK_SIZE', 200),

];
