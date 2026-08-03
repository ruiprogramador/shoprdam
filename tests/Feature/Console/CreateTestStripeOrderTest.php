    <?php

    use App\Enums\PaymentIntentAttemptStatus;
    use App\Models\Order;
    use App\Models\OrderPaymentIntent;
    use App\Models\OrderPaymentIntentAttempt;
    use App\Models\Store;
    use App\Models\StoreWalletTransaction;
    use Illuminate\Support\Facades\Artisan;
    use Stripe\ApiRequestor;
    use Tests\Fakes\FakeStripeHttpClient;

    afterEach(function () {
        ApiRequestor::setHttpClient(null);
    });

    it('creates an order and a stripe payment intent for a store', function () {
        $store = Store::factory()->create();

        $fakeClient = new FakeStripeHttpClient([
            'id' => 'pi_console_test_123',
            'object' => 'payment_intent',
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_console_test_123_secret',
            'metadata' => [],
        ]);

        ApiRequestor::setHttpClient($fakeClient);

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '25.00',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('pi_console_test_123');

        $order = Order::where('store_id', $store->id)->firstOrFail();

        expect($order->amount)
            ->toBe('25.00')
            ->and($order->status->slug)
            ->toBe('pending');

        $transaction = StoreWalletTransaction::where('external_reference', 'pi_console_test_123')->firstOrFail();

        expect($transaction->referenceable_id)
            ->toBe($order->id)
            ->and($transaction->status->slug)
            ->toBe('pending')
            ->and($fakeClient->requests)
            ->toHaveCount(1)
            ->and($fakeClient->requests[0]['params']['amount'])
            ->toBe(2500)
            ->and($fakeClient->requests[0]['params']['currency'])
            ->toBe(strtolower($order->currency->code))
            ->and($fakeClient->requests[0]['params']['metadata']['order_id'])
            ->toBe((string) $order->id);
    });

    it('deletes the test order when the Stripe API call fails', function () {
        $store = Store::factory()->create();

        $fakeClient = new FakeStripeHttpClient(
            ['error' => ['type' => 'api_error', 'message' => 'Something went wrong on Stripe.']],
            500,
        );

        ApiRequestor::setHttpClient($fakeClient);

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '25.00',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Failed to create the Stripe PaymentIntent');

        expect(Order::where('store_id', $store->id)->exists())
            ->toBeFalse()
            ->and(StoreWalletTransaction::where('referenceable_type', Order::class)->exists())
            ->toBeFalse();
    });

    it('normalizes the amount to 2 decimals and the matching minor-unit amount to Stripe', function (string $input) {
        $store = Store::factory()->create();

        $fakeClient = new FakeStripeHttpClient([
            'id' => 'pi_console_test_norm',
            'object' => 'payment_intent',
            'amount' => 1000,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_console_test_norm_secret',
            'metadata' => [],
        ]);

        ApiRequestor::setHttpClient($fakeClient);

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => $input,
        ])->assertExitCode(0);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        expect($order->amount)
            ->toBe('10.00')
            ->and($fakeClient->requests[0]['params']['amount'])
            ->toBe(1000);
    })->with(['10', '10.0', '10.00']);

    it('rejects an amount with more than 2 decimal places', function () {
        $store = Store::factory()->create();

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '10.999',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid amount');

        expect(Order::where('store_id', $store->id)->exists())->toBeFalse();
    });

    it('rejects a negative amount', function () {
        $store = Store::factory()->create();

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '-10.00',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid amount');

        expect(Order::where('store_id', $store->id)->exists())->toBeFalse();
    });

    it('rejects a zero amount', function () {
        $store = Store::factory()->create();

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '0.00',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid amount');

        expect(Order::where('store_id', $store->id)->exists())->toBeFalse();
    });

    it('fails gracefully when the store has no wallet', function () {
        $store = Store::factory()->create();
        $store->wallets()->delete();

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '25.00',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('has no wallet');

        expect(Order::where('store_id', $store->id)->exists())->toBeFalse();
    });

    it('fails gracefully when the store does not exist', function () {
        $this->artisan('app:stripe-test-order', ['store_id' => 999999])
            ->assertExitCode(1)
            ->expectsOutputToContain('not found');
    });

    it('refuses to run in production', function () {
        app()['env'] = 'production';

        $store = Store::factory()->create();

        $this->artisan('app:stripe-test-order', ['store_id' => $store->id])
            ->assertExitCode(1);

        app()['env'] = 'testing';
    });

    it('refuses to run when the configured Stripe secret is not a test-mode key, without calling Stripe', function () {
        config(['services.stripe.secret' => 'sk_live_dummy']);

        $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_created']);
        ApiRequestor::setHttpClient($fakeClient);

        $store = Store::factory()->create();

        $this->artisan('app:stripe-test-order', ['store_id' => $store->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('test-mode credentials');

        expect($fakeClient->requests)->toBeEmpty();
    });

    it('refuses to run when no Stripe secret is configured', function () {
        config(['services.stripe.secret' => null]);

        $store = Store::factory()->create();

        $this->artisan('app:stripe-test-order', ['store_id' => $store->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('test-mode credentials');
    });

    it('with --simulate-orphan, stops before the local claim/Wallet transaction and prints recovery instructions', function () {
        $store = Store::factory()->create();

        $fakeClient = new FakeStripeHttpClient([
            'id' => 'pi_console_orphan',
            'object' => 'payment_intent',
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_console_orphan_secret',
            'metadata' => [],
        ]);

        ApiRequestor::setHttpClient($fakeClient);

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '25.00',
            '--simulate-orphan' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('pi_console_orphan')
            ->expectsOutputToContain('reconcile-orphaned-payment-intents');

        $order = Order::where('store_id', $store->id)->firstOrFail();

        // Exactly the state ReconcileOrphanedPaymentIntents recovers from:
        // Stripe has the PaymentIntent, but no local claim or Wallet
        // transaction points at it yet.
        expect(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
            ->toBe(PaymentIntentAttemptStatus::Pending)
            ->and(OrderPaymentIntent::where('order_id', $order->id)->exists())
            ->toBeFalse()
            ->and(StoreWalletTransaction::where('external_reference', 'pi_console_orphan')->exists())
            ->toBeFalse();
    });

    it('with --simulate-orphan, the recovery flow it documents actually recovers the order end-to-end', function () {
        $store = Store::factory()->create();

        $fakeClient = new FakeStripeHttpClient([
            'id' => 'pi_console_orphan_e2e',
            'object' => 'payment_intent',
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_console_orphan_e2e_secret',
            'metadata' => [],
        ]);

        ApiRequestor::setHttpClient($fakeClient);

        $this->artisan('app:stripe-test-order', [
            'store_id' => $store->id,
            'amount' => '25.00',
            '--simulate-orphan' => true,
        ])->assertExitCode(0);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        // Exactly the command line the tool's own output tells the operator
        // to run — see the `--stale-after=0` guidance above.
        Artisan::call('app:reconcile-orphaned-payment-intents', ['--stale-after' => 0]);

        expect(OrderPaymentIntent::where('order_id', $order->id)->value('payment_intent_id'))
            ->toBe('pi_console_orphan_e2e')
            ->and(StoreWalletTransaction::where('external_reference', 'pi_console_orphan_e2e')->exists())
            ->toBeTrue()
            ->and(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
            ->toBe(PaymentIntentAttemptStatus::Claimed)
            // Recovery only rebuilds pending local state — the Order is
            // still `pending` until the Stripe webhook confirms it.
            ->and($order->fresh()->status->slug)
            ->toBe('pending');
    });
