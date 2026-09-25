<?php

use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payments\Services\ReconciliationFindingRepository;
use App\Domain\Payouts\Services\PayoutEventProcessor;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `harden/database-portability` — regression protection for every
 * exception-classification precision gap this branch's audits found, across
 * two rounds: `PaymentService::findOrCreatePayment()` and
 * `PaymentEventProcessor::storeUnmatchedEvent()` previously caught ANY
 * QueryException and assumed "duplicate"; `WalletTransactionService::record()`
 * and `ReconciliationFindingRepository::openOrUpdateEpisode()` had no
 * classifier to harden at the time (they used `insertOrIgnore()`, later
 * replaced with a savepoint-protected `create()` — see their own docblocks
 * — which needed a classifier again); `PayoutEventProcessor::isUniqueViolation()`
 * already had one but without message-precision, hardened for defense in
 * depth. All six now require the portable unique-violation SQLSTATE class
 * (MySQL/MariaDB/SQLite's `23000`, PostgreSQL's `23505`) *and* the driver
 * message naming the specific column, mirroring
 * InventoryReservationService::isReservationIdentityViolation() — the most
 * precise classifier already in this codebase.
 *
 * Mirrors InventoryReservationServiceTest's own reflection-based technique
 * for driving a private classifier method directly with a real, driver-
 * thrown QueryException (never a hand-built mock of one).
 */
function classifierFixtureException(string $sql): QueryException
{
    try {
        DB::statement($sql);
    } catch (QueryException $e) {
        return $e;
    }

    throw new RuntimeException("Expected {$sql} to raise a QueryException on this test connection.");
}

// ---------------------------------------------------------------------
// PaymentService::isOrderIdUniqueViolation()
// ---------------------------------------------------------------------

function classifyOrderIdViolation(QueryException $e): bool
{
    return (new ReflectionMethod(PaymentService::class, 'isOrderIdUniqueViolation'))
        ->invoke(app(PaymentService::class), $e);
}

it('classifies a real unique(order_id) violation as such', function () {
    DB::statement('CREATE TABLE order_id_probe (order_id INTEGER UNIQUE)');
    DB::statement('INSERT INTO order_id_probe (order_id) VALUES (1)');

    $e = classifierFixtureException('INSERT INTO order_id_probe (order_id) VALUES (1)');

    expect(classifyOrderIdViolation($e))->toBeTrue();
});

it('never classifies an unrelated constraint violation as an order_id race', function () {
    DB::statement('CREATE TABLE unrelated_unique_probe (other_column INTEGER UNIQUE)');
    DB::statement('INSERT INTO unrelated_unique_probe (other_column) VALUES (1)');

    $e = classifierFixtureException('INSERT INTO unrelated_unique_probe (other_column) VALUES (1)');

    // Same SQLSTATE class (23000, a unique violation) but a completely
    // different column — this must NOT be mistaken for the order_id race.
    expect(classifyOrderIdViolation($e))->toBeFalse();
});

// ---------------------------------------------------------------------
// PaymentEventProcessor::isProviderEventUniqueViolation()
// ---------------------------------------------------------------------

function classifyProviderEventViolation(QueryException $e): bool
{
    return (new ReflectionMethod(PaymentEventProcessor::class, 'isProviderEventUniqueViolation'))
        ->invoke(app(PaymentEventProcessor::class), $e);
}

it('classifies a real unique(provider, provider_event_id) violation as such', function () {
    DB::statement('CREATE TABLE provider_event_id_probe (provider_event_id VARCHAR(255) UNIQUE)');
    DB::statement("INSERT INTO provider_event_id_probe (provider_event_id) VALUES ('evt_1')");

    $e = classifierFixtureException("INSERT INTO provider_event_id_probe (provider_event_id) VALUES ('evt_1')");

    expect(classifyProviderEventViolation($e))->toBeTrue();
});

it('never classifies an unrelated constraint violation as a provider_event_id race', function () {
    DB::statement('CREATE TABLE unrelated_unique_probe_2 (other_column INTEGER UNIQUE)');
    DB::statement('INSERT INTO unrelated_unique_probe_2 (other_column) VALUES (1)');

    $e = classifierFixtureException('INSERT INTO unrelated_unique_probe_2 (other_column) VALUES (1)');

    expect(classifyProviderEventViolation($e))->toBeFalse();
});

// ---------------------------------------------------------------------
// WalletTransactionService::isReferenceUniqueViolation()
// ---------------------------------------------------------------------

function classifyReferenceViolation(QueryException $e): bool
{
    return (new ReflectionMethod(WalletTransactionService::class, 'isReferenceUniqueViolation'))
        ->invoke(app(WalletTransactionService::class), $e);
}

it('classifies a real unique(external_provider, external_reference)-shaped violation as such', function () {
    DB::statement('CREATE TABLE external_reference_probe (external_reference VARCHAR(255) UNIQUE)');
    DB::statement("INSERT INTO external_reference_probe (external_reference) VALUES ('pi_1')");

    $e = classifierFixtureException("INSERT INTO external_reference_probe (external_reference) VALUES ('pi_1')");

    expect(classifyReferenceViolation($e))->toBeTrue();
});

it('never classifies an unrelated constraint violation as an external_reference race', function () {
    DB::statement('CREATE TABLE unrelated_unique_probe_3 (other_column INTEGER UNIQUE)');
    DB::statement('INSERT INTO unrelated_unique_probe_3 (other_column) VALUES (1)');

    $e = classifierFixtureException('INSERT INTO unrelated_unique_probe_3 (other_column) VALUES (1)');

    expect(classifyReferenceViolation($e))->toBeFalse();
});

// ---------------------------------------------------------------------
// ReconciliationFindingRepository::isActiveIdentityUniqueViolation()
// ---------------------------------------------------------------------

function classifyActiveIdentityViolation(QueryException $e): bool
{
    return (new ReflectionMethod(ReconciliationFindingRepository::class, 'isActiveIdentityUniqueViolation'))
        ->invoke(app(ReconciliationFindingRepository::class), $e);
}

it('classifies a real unique(active_identity) violation as such', function () {
    DB::statement('CREATE TABLE active_identity_probe (active_identity VARCHAR(255) UNIQUE)');
    DB::statement("INSERT INTO active_identity_probe (active_identity) VALUES ('stripe:ref_1')");

    $e = classifierFixtureException("INSERT INTO active_identity_probe (active_identity) VALUES ('stripe:ref_1')");

    expect(classifyActiveIdentityViolation($e))->toBeTrue();
});

it('never classifies an unrelated constraint violation as an active_identity race', function () {
    DB::statement('CREATE TABLE unrelated_unique_probe_4 (other_column INTEGER UNIQUE)');
    DB::statement('INSERT INTO unrelated_unique_probe_4 (other_column) VALUES (1)');

    $e = classifierFixtureException('INSERT INTO unrelated_unique_probe_4 (other_column) VALUES (1)');

    expect(classifyActiveIdentityViolation($e))->toBeFalse();
});

// ---------------------------------------------------------------------
// PayoutEventProcessor::isUniqueViolation()
// ---------------------------------------------------------------------

function classifyExternalTransferReferenceViolation(QueryException $e): bool
{
    return (new ReflectionMethod(PayoutEventProcessor::class, 'isUniqueViolation'))
        ->invoke(app(PayoutEventProcessor::class), $e);
}

it('classifies a real unique(provider, external_transfer_reference) violation as such', function () {
    DB::statement('CREATE TABLE external_transfer_reference_probe (external_transfer_reference VARCHAR(255) UNIQUE)');
    DB::statement("INSERT INTO external_transfer_reference_probe (external_transfer_reference) VALUES ('tr_1')");

    $e = classifierFixtureException("INSERT INTO external_transfer_reference_probe (external_transfer_reference) VALUES ('tr_1')");

    expect(classifyExternalTransferReferenceViolation($e))->toBeTrue();
});

it('never classifies an unrelated constraint violation as an external_transfer_reference race', function () {
    DB::statement('CREATE TABLE unrelated_unique_probe_5 (other_column INTEGER UNIQUE)');
    DB::statement('INSERT INTO unrelated_unique_probe_5 (other_column) VALUES (1)');

    $e = classifierFixtureException('INSERT INTO unrelated_unique_probe_5 (other_column) VALUES (1)');

    expect(classifyExternalTransferReferenceViolation($e))->toBeFalse();
});
