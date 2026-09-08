<?php

use App\Payments\EasyPay\EasyPayEventTranslator;
use App\Payments\EasyPay\EasyPayPaymentProvider;
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
        'easypay' => [
            'provider' => EasyPayPaymentProvider::class,
            'translator' => EasyPayEventTranslator::class,
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
    | Deliberately not `(int) env(...)` here: an `(int)` cast silently turns
    | a malformed env value (e.g. a typo'd 'PAYMENTS_PROVIDER_EVENT_RETENTION_DAYS=abc')
    | into `0`, which would make the pruning command aggressively delete
    | every currently-eligible Applied row instead of refusing to run.
    | PrunePaymentProviderEvents validates this raw value itself (strict
    | integer parsing, fails closed) rather than trusting an early cast.
    |
    */

    'provider_event_retention_days' => env('PAYMENTS_PROVIDER_EVENT_RETENTION_DAYS', 90),

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

    /*
    |--------------------------------------------------------------------------
    | Health thresholds
    |--------------------------------------------------------------------------
    |
    | Read-only thresholds `php artisan payments:health`
    | (App\Domain\Payments\Services\PaymentsHealthCheck) uses to decide which
    | otherwise-normal, transient states ("an attempt is pending", "an event
    | hasn't replayed yet") have gone on long enough to actually be
    | actionable — never how the payments domain itself behaves. Changing
    | these only changes what payments:health reports; it never changes
    | PaymentService/PaymentEventProcessor/ReconcileOrphanedPaymentAttempts
    | behavior.
    |
    | Each is set meaningfully above the related recurring schedule (see
    | routes/console.php: reconciliation runs every 5 minutes with a
    | 15-minute default --lease-timeout) so a single normal cycle — or one
    | ordinary retry — is never itself reported as unhealthy; see each
    | threshold's own key for why its multiple was chosen.
    |
    | Deliberately not `(int) env(...)` here, for the same reason as
    | `provider_event_retention_days` above: that cast silently turns a
    | malformed env value into `0`, which for these thresholds means "flag
    | every pending attempt/event as stale immediately" — a misleading
    | health report, not a safe default.
    | App\Domain\Payments\Services\PaymentsHealthCheck validates each of
    | these raw values itself (App\Domain\Payments\ConfigInteger, the same
    | strict parser PrunePaymentProviderEvents uses) and fails closed
    | (throws rather than reports) on anything that doesn't parse.
    |
    */

    'health' => [
        // An attempt is expected to be picked up and either resolved or
        // failed within a few reconciliation cycles (every 5 minutes) —
        // three full cycles of headroom before it's worth a human's
        // attention.
        'stale_pending_minutes' => env('PAYMENTS_HEALTH_STALE_PENDING_MINUTES', 15),

        // Double the default --lease-timeout (15 minutes): a lease still
        // outstanding this long means reconciliation isn't actually
        // resolving this attempt, not just waiting for its next tick.
        'stale_lease_minutes' => env('PAYMENTS_HEALTH_STALE_LEASE_MINUTES', 30),

        // Same three-cycle headroom as stale_pending_minutes — an unmatched
        // provider event is replayed as a side effect of the same
        // reconciliation run, so the two share a rationale.
        'stale_event_minutes' => env('PAYMENTS_HEALTH_STALE_EVENT_MINUTES', 15),

        // More than a couple of failed replay attempts on the same event —
        // one or two is an ordinary transient retry, not yet a signal. Must
        // be >= 1 (not just >= 0): a threshold of 0 would flag every
        // pending event as "repeatedly failing" on its very first replay.
        'replay_attempts_warning' => env('PAYMENTS_HEALTH_REPLAY_ATTEMPTS_WARNING', 3),
    ],

];
