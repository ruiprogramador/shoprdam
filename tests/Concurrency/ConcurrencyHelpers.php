<?php

use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Engine-agnostic half of the real-engine Inventory concurrency harness,
 * shared by InventoryMysqlConcurrencyTest.php and
 * InventoryPostgresConcurrencyTest.php. Each of those files keeps its own:
 *   - beforeEach() safety guard (which driver + host + database name)
 *   - mcAwaitLockWait() (MySQL: performance_schema.data_lock_waits;
 *     Postgres: pg_stat_activity.wait_event_type)
 *   - the "runs on real <engine>" and "enforces the CHECK constraints" tests,
 *     since constraint violation error shapes differ per engine.
 *
 * See tests/Concurrency/InventoryMysqlConcurrencyTest.php for the full
 * protocol description (worker.php is shared verbatim across engines).
 */
function mcDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inv-conc-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

/** @return array{proc: resource, dir: string, name: string} */
function mcSpawn(string $dir, string $name, string $action, Order $order, bool $hold = false): array
{
    $job = json_encode(['dir' => $dir, 'name' => $name, 'action' => $action, 'order' => $order->id, 'hold' => $hold]);
    $php = array_values(array_filter(explode(' ', (string) getenv('INVENTORY_CONCURRENCY_WORKER_PHP_ARGS'))));

    $proc = proc_open(
        [PHP_BINARY, ...$php, __DIR__.'/worker.php', $job],
        [0 => ['pipe', 'r'], 1 => ['file', "{$dir}/{$name}.out", 'w'], 2 => ['file', "{$dir}/{$name}.err", 'w']],
        $pipes,
    );

    if (! is_resource($proc)) {
        throw new RuntimeException("could not start worker {$name}");
    }

    fclose($pipes[0]);

    return ['proc' => $proc, 'dir' => $dir, 'name' => $name];
}

function mcWait(string $dir, string $file, float $seconds = 90.0): void
{
    $deadline = microtime(true) + $seconds;

    while (! file_exists("{$dir}/{$file}")) {
        if (microtime(true) > $deadline) {
            $diagnostics = collect(glob("{$dir}/*.err"))->map(fn ($f) => basename($f).': '.trim((string) file_get_contents($f)))->filter()->implode(' | ');

            throw new RuntimeException("timed out waiting for {$file}. {$diagnostics}");
        }

        usleep(500);
    }
}

/** @return array<string, int> worker name => its DB connection/backend id */
function mcReady(string $dir, array $names): array
{
    $ids = [];

    foreach ($names as $name) {
        mcWait($dir, "{$name}.ready");
        $ids[$name] = (int) file_get_contents("{$dir}/{$name}.ready");
    }

    return $ids;
}

function mcSignal(string $dir, string $file): void
{
    file_put_contents("{$dir}/{$file}", '1');
}

/** @return array<string, mixed> */
function mcResult(string $dir, string $name): array
{
    mcWait($dir, "{$name}.result");

    return json_decode(file_get_contents("{$dir}/{$name}.result"), true, flags: JSON_THROW_ON_ERROR);
}

function mcJoin(array ...$workers): void
{
    foreach ($workers as $worker) {
        proc_close($worker['proc']);
    }
}

/**
 * Starts every job, waits until each worker is booted and connected, releases
 * them all at once, and returns each outcome.
 *
 * @param  list<array{name: string, action: string, order: Order}>  $jobs
 * @return array<string, array<string, mixed>>
 */
function mcRace(array $jobs): array
{
    $dir = mcDir();
    $workers = array_map(fn ($j) => mcSpawn($dir, $j['name'], $j['action'], $j['order']), $jobs);

    mcReady($dir, array_column($jobs, 'name'));

    foreach ($jobs as $job) {
        mcSignal($dir, "{$job['name']}.go");
    }

    $results = [];

    foreach ($jobs as $job) {
        $results[$job['name']] = mcResult($dir, $job['name']);
    }

    mcJoin(...$workers);

    return $results;
}

function mcOutcomes(array $results): array
{
    return collect($results)->map(fn ($r) => $r['status'] === 'ok' ? 'ok' : $r['class'])->all();
}

function mcService(): InventoryReservationService
{
    return app(InventoryReservationService::class);
}

/** @return list<string> the reservation statuses held by the Order, straight from the database */
function mcStatuses(Order $order): array
{
    return DB::table('inventory_reservations')
        ->join('order_items', 'order_items.id', '=', 'inventory_reservations.order_item_id')
        ->where('order_items.order_id', $order->id)
        ->orderBy('inventory_reservations.id')
        ->pluck('inventory_reservations.status')
        ->all();
}

function mcReservedOrder(Store $store, int $stock, int $quantity): array
{
    $product = inventoryProduct($store);
    inventorySeed($product, $stock);
    $order = inventoryOrder($store, [[$product, $quantity]]);
    mcService()->reserve($order);

    return [$order, $product];
}
