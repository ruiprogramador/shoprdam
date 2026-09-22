<?php

use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Domain\Inventory\Exceptions\InvalidReservationException;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeTestPaymentProvider;

/**
 * The Payment/Inventory boundary (docs/inventory/INVENTORY-RESERVATIONS.md
 * §12–§13), against the real PaymentService, PaymentEventProcessor, Wallet and
 * Order lifecycle — only the remote provider is a fake.
 *
 * Inventory is deliberately NOT wired to any payment event in this branch.
 * These tests pin what that means: payment outcomes neither release nor
 * commit a reservation, inventory has no say in financial settlement, and a
 * late provider success after a release settles financially while the commit
 * is refused (the unresolved policy case) — never inventing stock.
 */
function ipbOrder(int $onHand = 5, int $quantity = 2): array
{
    $store = Store::factory()->create();
    $product = inventoryProduct($store, '10.00');
    inventorySeed($product, $onHand);
    $order = inventoryOrder($store, [[$product, $quantity]]);

    return [$order, $product];
}

function ipbProvider(string $name, Order $order): void
{
    $provider = new FakeTestPaymentProvider($name, new ProviderPaymentResult(
        providerReference: "{$name}_ref_{$order->id}",
        amountMinorUnits: (int) round(((float) $order->amount) * 100),
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));
    app(PaymentProviderManager::class)->extend($name, fn () => $provider);
}

function ipbEvent(string $provider, Order $order, ProviderEventType $type, string $id): ProviderEventOutcome
{
    return new ProviderEventOutcome(
        provider: $provider,
        eventId: $id,
        eventType: $type->name,
        type: $type,
        providerReference: "{$provider}_ref_{$order->id}",
        failureReason: $type === ProviderEventType::Failed ? 'declined' : null,
    );
}

function ipbReservation(): InventoryReservation
{
    return InventoryReservation::query()->firstOrFail();
}

it('M: a failed PaymentAttempt (and the resulting failed Order) does NOT release the reservation — INVENTORY-13/14', function () {
    [$order, $product] = ipbOrder();
    app(InventoryReservationService::class)->reserve($order);
    ipbProvider('ipb_a', $order);

    $attempt = app(PaymentService::class)->startAttempt($order, 'ipb_a', 'wallet');
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_a', $order, ProviderEventType::Failed, 'evt_fail'));

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($order->fresh()->status->slug)->toBe('failed')
        ->and(ipbReservation()->status)->toBe(InventoryReservationStatus::Reserved)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3]);
});

it('N: after a failed attempt, a successful retry leaves the reservation intact and committable exactly once — provider redelivery included', function () {
    [$order, $product] = ipbOrder();
    $inventory = app(InventoryReservationService::class);
    $inventory->reserve($order);
    ipbProvider('ipb_a', $order);
    ipbProvider('ipb_b', $order);

    app(PaymentService::class)->startAttempt($order, 'ipb_a', 'wallet');
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_a', $order, ProviderEventType::Failed, 'evt_fail'));

    app(PaymentService::class)->startAttempt($order->fresh(), 'ipb_b', 'wallet');
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_b', $order, ProviderEventType::Succeeded, 'evt_ok'));

    // failed -> paid, and the reservation is the SAME one, untouched: no new
    // reservation is created per attempt, and payment does not commit it.
    expect($order->fresh()->status->slug)->toBe('paid')
        ->and(InventoryReservation::count())->toBe(1)
        ->and(ipbReservation()->status)->toBe(InventoryReservationStatus::Reserved)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3]);

    // The commit trigger is a future integration; called explicitly it consumes once.
    expect($inventory->commit($order))->toBe(1);

    // Provider success redelivered, and commit retried: no second stock effect.
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_b', $order, ProviderEventType::Succeeded, 'evt_ok'));
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_b', $order, ProviderEventType::Succeeded, 'evt_ok_2'));

    expect($inventory->commit($order))->toBe(0)
        ->and(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
        ->and($order->fresh()->status->slug)->toBe('paid');
});

it('a successful payment alone does not consume stock — nothing in the payment path commits inventory in this branch', function () {
    [$order, $product] = ipbOrder();
    app(InventoryReservationService::class)->reserve($order);
    ipbProvider('ipb_a', $order);

    app(PaymentService::class)->startAttempt($order, 'ipb_a', 'wallet');
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_a', $order, ProviderEventType::Succeeded, 'evt_ok'));

    expect($order->fresh()->status->slug)->toBe('paid')
        ->and(ipbReservation()->status)->toBe(InventoryReservationStatus::Reserved)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3]);
});

it('late provider success after a release: financial settlement stands, the commit is refused, and no stock is invented (the unresolved policy case, design §12)', function () {
    [$order, $product] = ipbOrder(onHand: 1, quantity: 1);
    $inventory = app(InventoryReservationService::class);
    $inventory->reserve($order);
    ipbProvider('ipb_a', $order);

    app(PaymentService::class)->startAttempt($order, 'ipb_a', 'wallet');

    // Some future policy releases the hold while the payment is still open,
    // and another buyer takes the freed unit.
    $inventory->release($order);
    $other = inventoryOrder(Store::query()->find($order->store_id), [[$product, 1]]);
    $inventory->reserve($other);
    $inventory->commit($other);

    // The provider then reports SUCCESS for the first Order.
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_a', $order, ProviderEventType::Succeeded, 'evt_late'));

    // Money settled — inventory had no veto over it…
    expect($order->fresh()->status->slug)->toBe('paid')
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and(DB::table('store_wallet_transactions')->where('external_reference', "ipb_a_ref_{$order->id}")->exists())->toBeTrue();

    // …but the stock is gone, and inventory refuses rather than steal or invent it.
    expect(fn () => $inventory->commit($order))->toThrow(InvalidReservationException::class)
        ->and(inventoryState($product))->toBe(['on_hand' => 0, 'reserved' => 0, 'available' => 0]);
});

it('a legacy line-less Order still pays exactly as before — payment never requires inventory', function () {
    $store = Store::factory()->create();
    $legacy = Order::factory()->forStore($store)->amount('42.50')->create();
    ipbProvider('ipb_a', $legacy);

    app(PaymentService::class)->startAttempt($legacy, 'ipb_a', 'wallet');
    app(PaymentEventProcessor::class)->apply(ipbEvent('ipb_a', $legacy, ProviderEventType::Succeeded, 'evt_ok'));

    expect($legacy->fresh()->status->slug)->toBe('paid')
        ->and(InventoryReservation::count())->toBe(0);
});
