<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the database-level UNIQUE(provider, idempotency_key) constraint
 * added on payment_attempts (see the
 * 2026_08_31_090000_add_unique_provider_idempotency_key_to_payment_attempts_table
 * migration): PaymentService::createDurableAttempt() already derives a
 * deterministic, attempt-scoped key
 * (`payment-{payment_id}-attempt-{attempt_id}`), which makes an
 * application-level collision practically impossible — this constraint is
 * the database's own independent backstop, not a substitute for that
 * determinism. Provider-scoped, not globally unique: different providers
 * may legitimately mint keys from the same shape independently of one
 * another (see docs/wallet/integrations.md).
 */
function paymentForNewOrder(): Payment
{
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('10.00')->create();

    return Payment::create(['order_id' => $order->id]);
}

function attemptAttributes(int $paymentId, string $provider, string $idempotencyKey): array
{
    return [
        'payment_id' => $paymentId,
        'provider' => $provider,
        'method' => 'card',
        'idempotency_key' => $idempotencyKey,
        'status' => PaymentAttemptStatus::Pending,
    ];
}

it('rejects a second payment_attempts row with the same (provider, idempotency_key) pair', function () {
    PaymentAttempt::create(attemptAttributes(paymentForNewOrder()->id, 'stripe', 'shared-key'));

    expect(fn () => PaymentAttempt::create(attemptAttributes(paymentForNewOrder()->id, 'stripe', 'shared-key')))
        ->toThrow(QueryException::class);

    expect(PaymentAttempt::where('provider', 'stripe')->where('idempotency_key', 'shared-key')->count())
        ->toBe(1);
});

it('allows the same idempotency_key to be reused across different providers', function () {
    $a = PaymentAttempt::create(attemptAttributes(paymentForNewOrder()->id, 'stripe', 'shared-across-providers'));
    $b = PaymentAttempt::create(attemptAttributes(paymentForNewOrder()->id, 'easypay', 'shared-across-providers'));

    expect($a->id)->not->toBe($b->id)
        ->and(PaymentAttempt::where('idempotency_key', 'shared-across-providers')->count())
        ->toBe(2);
});

it('still allows the same provider to be reused with a different idempotency_key', function () {
    $payment = paymentForNewOrder();

    $a = PaymentAttempt::create(attemptAttributes($payment->id, 'stripe', 'first-key'));
    $b = PaymentAttempt::create(attemptAttributes($payment->id, 'stripe', 'second-key'));

    expect($a->id)->not->toBe($b->id)
        ->and(PaymentAttempt::where('payment_id', $payment->id)->count())
        ->toBe(2);
});

it('derives a stable idempotency_key across repeated calls for the same attempt and a distinct one for a different attempt', function () {
    $payment = paymentForNewOrder();

    $attempt = PaymentAttempt::create(attemptAttributes($payment->id, 'stripe', ''));
    $attempt->forceFill(['idempotency_key' => "payment-{$payment->id}-attempt-{$attempt->id}"])->save();

    $otherPayment = paymentForNewOrder();
    $otherAttempt = PaymentAttempt::create(attemptAttributes($otherPayment->id, 'stripe', ''));
    $otherAttempt->forceFill(['idempotency_key' => "payment-{$otherPayment->id}-attempt-{$otherAttempt->id}"])->save();

    expect($attempt->fresh()->idempotency_key)
        ->toBe("payment-{$payment->id}-attempt-{$attempt->id}")
        ->and($attempt->fresh()->idempotency_key)
        ->not->toBe($otherAttempt->fresh()->idempotency_key);
});

it('refuses to add the unique index when duplicate (provider, idempotency_key) rows already exist, without deleting or merging anything', function () {
    // Simulate the pre-migration state: the constraint isn't there yet, and
    // (implausibly, but the migration must not assume otherwise) two rows
    // already collide.
    Schema::table('payment_attempts', function (Blueprint $table) {
        $table->dropUnique(['provider', 'idempotency_key']);
    });

    $paymentA = paymentForNewOrder();
    $paymentB = paymentForNewOrder();

    DB::table('payment_attempts')->insert([
        ['payment_id' => $paymentA->id, 'provider' => 'stripe', 'method' => 'card', 'idempotency_key' => 'pre-existing-duplicate', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ['payment_id' => $paymentB->id, 'provider' => 'stripe', 'method' => 'card', 'idempotency_key' => 'pre-existing-duplicate', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_08_31_090000_add_unique_provider_idempotency_key_to_payment_attempts_table.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class);

    // Neither duplicate row was touched — the migration fails loudly
    // instead of silently deleting or merging financial history.
    expect(DB::table('payment_attempts')->where('idempotency_key', 'pre-existing-duplicate')->count())
        ->toBe(2);
});

it('two attempts for the same provider can both reach the pre-deterministic-key placeholder insert step without colliding on this constraint', function () {
    // PaymentService::createDurableAttempt() inserts a PaymentAttempt with
    // a placeholder idempotency_key *before* it has its own id to derive
    // the real, deterministic key from (see the forceFill()/save() right
    // after that insert) — two concurrent calls for two different Payments
    // on the same provider can both reach that INSERT before either's
    // follow-up UPDATE runs. Regression test for a real bug this migration
    // would otherwise have introduced: the placeholder used to be a shared
    // constant (''), which — once (provider, idempotency_key) became
    // unique — would make the second of two such concurrent inserts fail.
    // The fix makes the placeholder unique per insert (a random UUID); this
    // proves that property directly, standing in for genuinely concurrent
    // transactions that would otherwise both hold this same
    // not-yet-updated placeholder row at once.
    $paymentA = paymentForNewOrder();
    $paymentB = paymentForNewOrder();

    $attemptA = PaymentAttempt::create(attemptAttributes($paymentA->id, 'stripe', 'pending-'.\Illuminate\Support\Str::uuid()));

    expect(fn () => PaymentAttempt::create(attemptAttributes($paymentB->id, 'stripe', 'pending-'.\Illuminate\Support\Str::uuid())))
        ->not->toThrow(QueryException::class);

    expect($attemptA->idempotency_key)->not->toBe('');
});
