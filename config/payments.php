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

    /*
    |--------------------------------------------------------------------------
    | Provider event retention
    |--------------------------------------------------------------------------
    |
    | How long a terminally `applied` payment_provider_events row (see
    | App\Domain\Payments\Enums\ProviderEventStatus) is kept, anchored on
    | `processed_at`, before App\Console\Commands\PrunePaymentProviderEvents
    | is allowed to delete it. `pending` rows — and any `applied` row with a
    | null `processed_at` — are never pruned by age; see that command for
    | why. Kept generous by default since this table stays small in steady
    | state and nothing operationally depends on pruning happening promptly.
    |
    */

    'provider_event_retention_days' => (int) env('PAYMENTS_PROVIDER_EVENT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Provider event pruning
    |--------------------------------------------------------------------------
    |
    | How many rows PrunePaymentProviderEvents deletes per DELETE statement,
    | looping until a batch deletes fewer than this many rows — bounds
    | per-statement lock/log size instead of one unbounded DELETE across the
    | whole eligible set.
    |
    */

    'provider_event_prune_chunk_size' => (int) env('PAYMENTS_PROVIDER_EVENT_PRUNE_CHUNK_SIZE', 500),

];
