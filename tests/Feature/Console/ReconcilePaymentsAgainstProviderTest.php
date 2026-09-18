<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\ReconciliationFinding;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function commandClaimedAttempt(Order $order, string $providerReference, int $ageMinutes = 20): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'provider_reference' => $providerReference,
        'idempotency_key' => "payment-{$payment->id}-attempt-cli",
        'status' => PaymentAttemptStatus::Claimed,
    ]);
    $attempt->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

    return $attempt->fresh();
}

it('rejects a non-numeric --min-age without touching any attempt or calling the provider', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    commandClaimedAttempt($order, 'pi_cli_should_not_be_requested');

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_cli_should_not_be_requested']);
    ApiRequestor::setHttpClient($fakeClient);

    $exitCode = Artisan::call('app:reconcile-payments-against-provider', ['--min-age' => 'abc']);

    expect($exitCode)->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain("--min-age must be an integer. Got: 'abc'.")
        ->and($fakeClient->requests)->toHaveCount(0)
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('skips an attempt younger than --min-age, without calling the provider', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    commandClaimedAttempt($order, 'pi_cli_too_fresh', ageMinutes: 1);

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_cli_too_fresh']);
    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-payments-against-provider', ['--min-age' => 10]);

    expect($fakeClient->requests)->toHaveCount(0)
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('exits SUCCESS when no open high-severity findings exist', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    commandClaimedAttempt($order, 'pi_cli_healthy');

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_cli_healthy' => [
            'id' => 'pi_cli_healthy',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $exitCode = Artisan::call('app:reconcile-payments-against-provider', ['--min-age' => 10]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('exits FAILURE when an open high-severity finding exists, and reports it in the summary', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    commandClaimedAttempt($order, 'pi_cli_amount_mismatch');

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_cli_amount_mismatch' => [
            'id' => 'pi_cli_amount_mismatch',
            'object' => 'payment_intent',
            'amount' => 999,
            'currency' => 'eur',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $exitCode = Artisan::call('app:reconcile-payments-against-provider', ['--min-age' => 10, '--json' => true]);

    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output['open_high_severity_findings'])->toBe(1)
        ->and($output['by_category'])->toHaveKey('amount_mismatch')
        ->and(ReconciliationFinding::count())->toBe(1);
});

it('never calls the recovery/settlement path, regardless of what the provider reports', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = commandClaimedAttempt($order, 'pi_cli_never_settles');

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_cli_never_settles' => [
            'id' => 'pi_cli_never_settles',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    Artisan::call('app:reconcile-payments-against-provider', ['--min-age' => 10]);

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and($wallet->fresh()->balance)->toBe('0.00')
        ->and($order->fresh()->status->slug)->toBe('pending');
});
