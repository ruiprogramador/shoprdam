<?php

namespace App\Console\Commands;

use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use Illuminate\Console\Command;

/**
 * Manual, test-mode-only tool: creates a real Order + Stripe payment so the
 * Stripe integration can be exercised end-to-end against Stripe's real API
 * (`stripe listen` + `stripe payment_intents confirm`), without needing a
 * checkout UI. Not a business feature — a validation tool. Deliberately
 * stays Stripe-specific (provider/method hardcoded to stripe/card) — it's
 * test tooling for one provider, not a generic checkout simulator.
 *
 * `--simulate-orphan` exercises the other half of that integration: it
 * stops right after Stripe creates the remote payment, before the local
 * claim/Wallet transaction is written, reproducing the crash window
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts recovers from —
 * see docs/wallet/integrations.md ("Recovering orphaned payment attempts").
 */
class CreateTestStripeOrder extends Command
{
    protected $signature = 'app:stripe-test-order {store_id} {amount=10.00}
        {--simulate-orphan : Stop right after Stripe creates the remote payment, before the local claim/Wallet transaction is written — reproducing the crash window that ReconcileOrphanedPaymentAttempts recovers from, so that recovery path can be exercised end-to-end manually}';

    protected $description = 'Create a test Order and Stripe payment for a store, to validate the Stripe integration end-to-end in test mode';

    public function handle(PaymentService $paymentService): int
    {
        if (app()->environment('production')) {
            $this->error('This command is for local/test-mode validation only and must not run in production.');

            return self::FAILURE;
        }

        if (! str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            $this->error('This command requires Stripe test-mode credentials (a secret key starting with sk_test_). Refusing to run with the currently configured key.');

            return self::FAILURE;
        }

        $store = Store::find($this->argument('store_id'));

        if (! $store) {
            $this->error("Store #{$this->argument('store_id')} not found.");

            return self::FAILURE;
        }

        $wallet = $store->wallets()->first();

        if (! $wallet) {
            $this->error("Store #{$store->id} has no wallet yet.");

            return self::FAILURE;
        }

        $amount = $this->argument('amount');

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            $this->error("Invalid amount '{$amount}': expected a positive number with at most 2 decimal places, e.g. 10.00.");

            return self::FAILURE;
        }

        $amount = bcadd($amount, '0', 2);

        $order = Order::create([
            'store_id' => $store->id,
            'currency_id' => $wallet->currency_id,
            'order_status_id' => OrderStatus::bySlugOrFail('pending')->id,
            'amount' => $amount,
        ]);

        $orderId = $order->id;
        $simulateOrphan = (bool) $this->option('simulate-orphan');

        try {
            $providerReference = $simulateOrphan
                ? $paymentService->createOrphanedAttemptForTesting($order, 'stripe', 'card')->providerReference
                : $paymentService->startAttempt($order, 'stripe', 'card')->provider_reference;
        } catch (\Throwable $e) {
            // Stripe's side (if it got that far) can't be rolled back from here,
            // but we can at least avoid leaving a dangling test Order behind.
            $order->delete();

            $this->error("Failed to create the Stripe payment for Order #{$orderId}, which was deleted: {$e->getMessage()}");
            $this->error('If Stripe reports a payment was created despite this error, it may need manual cleanup on the Stripe dashboard.');

            return self::FAILURE;
        }

        $this->info("Order #{$order->id} created for store '{$store->name}' ({$amount} {$wallet->currency->code}).");
        $this->info("Stripe PaymentIntent: {$providerReference}");

        if ($simulateOrphan) {
            $this->newLine();
            $this->comment('--simulate-orphan: stopped before the local claim/Wallet transaction was written.');
            $this->comment("Order #{$order->id}'s payment attempt has no claim/Wallet transaction yet — this is the exact state ReconcileOrphanedPaymentAttempts recovers from.");
            $this->newLine();
            $this->comment('E2E recovery test flow (webhook arrives after reconciliation):');
            $this->line('  1. Keep `stripe listen --forward-to <your-app>/stripe/webhook` running.');
            $this->line('  2. Recover the orphan now (bypassing the default 5-minute --stale-after):');
            $this->line('       php artisan app:reconcile-orphaned-payment-attempts --stale-after=0');
            $this->line("  3. Verify exactly one claimed payment attempt and one pending Wallet transaction now exist for Order #{$order->id}.");
            $this->line('  4. Confirm the recovered PaymentIntent to drive it to `paid` via the webhook:');
            $this->line("       stripe payment_intents confirm {$providerReference} --payment-method=pm_card_visa");
            $this->newLine();
            $this->comment('E2E test flow for the *other* ordering — webhook arrives BEFORE reconciliation');
            $this->comment('(this is the bug docs/wallet/integrations.md documents as closed: the webhook must');
            $this->comment('no longer leave the payment stuck pending once reconciliation catches up):');
            $this->line('  1. Keep `stripe listen --forward-to <your-app>/stripe/webhook` running.');
            $this->line('  2. Confirm the PaymentIntent now, before running reconciliation at all:');
            $this->line("       stripe payment_intents confirm {$providerReference} --payment-method=pm_card_visa");
            $this->line('  3. The webhook has nothing to match yet — it queues the event in payment_provider_events instead of dropping it.');
            $this->line('  4. Recover the orphan (bypassing the default 5-minute --stale-after):');
            $this->line('       php artisan app:reconcile-orphaned-payment-attempts --stale-after=0');
            $this->line("  5. The claim replays the queued event automatically — Order #{$order->id} should now be `paid`, with no further webhook needed.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment('With `stripe listen --forward-to <your-app>/stripe/webhook` running, simulate a successful test payment:');
        $this->line("  stripe payment_intents confirm {$providerReference} --payment-method=pm_card_visa");
        $this->newLine();
        $this->comment('Or a declined one:');
        $this->line("  stripe payment_intents confirm {$providerReference} --payment-method=pm_card_chargeDeclined");

        return self::SUCCESS;
    }
}
