<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use App\Payments\Stripe\StripePaymentService;
use Illuminate\Console\Command;

/**
 * Manual, test-mode-only tool: creates a real Order + Stripe PaymentIntent
 * so the Stripe integration can be exercised end-to-end against Stripe's
 * real API (`stripe listen` + `stripe payment_intents confirm`), without
 * needing a checkout UI. Not a business feature — a validation tool.
 */
class CreateTestStripeOrder extends Command
{
    protected $signature = 'app:stripe-test-order {store_id} {amount=10.00}';

    protected $description = 'Create a test Order and Stripe PaymentIntent for a store, to validate the Stripe integration end-to-end in test mode';

    public function handle(StripePaymentService $stripePaymentService): int
    {
        if (app()->environment('production')) {
            $this->error('This command is for local/test-mode validation only and must not run in production.');

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

        $amount = number_format((float) $this->argument('amount'), 2, '.', '');

        $order = Order::create([
            'store_id' => $store->id,
            'currency_id' => $wallet->currency_id,
            'order_status_id' => OrderStatus::bySlugOrFail('pending')->id,
            'amount' => $amount,
        ]);

        $paymentIntent = $stripePaymentService->createPaymentIntentForOrder($order);

        $this->info("Order #{$order->id} created for store '{$store->name}' ({$amount} {$wallet->currency->code}).");
        $this->info("PaymentIntent: {$paymentIntent->id}");
        $this->newLine();
        $this->comment('With `stripe listen --forward-to <your-app>/stripe/webhook` running, simulate a successful test payment:');
        $this->line("  stripe payment_intents confirm {$paymentIntent->id} --payment-method=pm_card_visa");
        $this->newLine();
        $this->comment('Or a declined one:');
        $this->line("  stripe payment_intents confirm {$paymentIntent->id} --payment-method=pm_card_chargeDeclined");

        return self::SUCCESS;
    }
}
