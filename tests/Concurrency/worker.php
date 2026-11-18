<?php

/**
 * Child process for the Inventory{Mysql,Mariadb,Postgres}ConcurrencyTest.php
 * suites — NOT a test, and never loaded by Pest directly. One invocation = one
 * independent PHP process with its own database connection, performing exactly
 * one canonical inventory operation and writing its outcome to a file the parent
 * reads. A job carries an `order` (reservations) or `args` (control operations).
 *
 * Protocol (all files live in the per-scenario directory `dir`):
 *
 *   <name>.ready    written after boot + connect; contains this connection's id
 *   <name>.go       the parent creates it to release this worker (barrier)
 *   <name>.locked   (hold mode only) written after the operation ran INSIDE this
 *                   worker's still-open outer transaction, i.e. while it holds its
 *                   InnoDB row locks
 *   <name>.commit   (hold mode only) the parent creates it to let the worker commit
 *   <name>.result   JSON outcome
 *   <name>.retry    (`retry` jobs only) the parent creates it to make the worker
 *                   repeat the identical call on this same connection
 *   <name>.retry_result  JSON outcome of that repeat
 *
 * A `lock_timeout` job (seconds) makes the ENGINE give up on a blocked lock, on
 * this connection only. A `compose` job runs the call inside a caller's outer
 * transaction, which either lets the error escape (`rethrow`) or catches it and
 * commits anyway (`swallow`), the shape docs/inventory/INVENTORY-CONTROL.md §24.7
 * forbids to production callers. It exists to keep that danger under test.
 *
 * Hold mode wraps the real service call in an outer DB::transaction, exactly as
 * a checkout composing Order + reserve() would (the service's own transaction
 * becomes a savepoint). It changes no production code; it only lets the parent
 * place a second, genuinely concurrent connection *behind* a held lock
 * deterministically instead of hoping for an overlap.
 *
 * Refuses to run against anything but a disposable local `*_concurrency_test`
 * database.
 */

use App\Domain\Inventory\DTOs\StoreOwnerActor;
use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryCountService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Order;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * `file_put_contents($path, $data)` is open+write+close, not one atomic
 * operation: the parent's `file_exists($path)` poll can observe the fresh
 * (empty) directory entry before the write lands, then read 0 bytes. On this
 * project's real-MySQL-concurrency Docker host that raced often enough to
 * intermittently corrupt `.ready`/`.result` reads (confirmed reproducible
 * with a bare, MySQL-free file_put_contents/file_exists probe — not a
 * database-layer issue). `rename()` on the same filesystem is atomic, so
 * write to a sibling temp name first and rename into place.
 */
function atomicWrite(string $path, string $contents): void
{
    $tmp = $path.'.tmp-'.getmypid();
    file_put_contents($tmp, $contents);
    rename($tmp, $path);
}

$job = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
$settings = config("database.connections.{$connection}");

if (! in_array($connection, ['mysql', 'mariadb', 'pgsql'], true) || ! in_array($settings['host'], ['127.0.0.1', 'localhost'], true) || ! str_ends_with((string) $settings['database'], '_concurrency_test')) {
    fwrite(STDERR, "worker refuses: not a disposable local *_concurrency_test mysql/mariadb/pgsql database.\n");
    exit(2);
}

$dir = $job['dir'];
$name = $job['name'];

$waitFor = function (string $file, float $seconds = 90.0) {
    $deadline = microtime(true) + $seconds;

    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("timed out waiting for {$file}");
        }

        usleep(300);
    }
};

$order = isset($job['order']) ? Order::query()->findOrFail($job['order']) : null;
$args = $job['args'] ?? [];
$service = app(InventoryReservationService::class);
$control = app(InventoryControlService::class);
$counts = app(InventoryCountService::class);

$movement = fn ($result) => ['id' => $result->movement->id, 'replayed' => $result->replayed];

$operation = fn () => match ($job['action']) {
    'reserve' => $service->reserve($order)->pluck('id')->all(),
    'commit' => $service->commit($order),
    'release' => $service->release($order),
    'create' => $control->createInventory($args['product'], new StoreOwnerActor($args['actor']))->id,
    'adopt' => $control->adopt($args['product'], new StoreOwnerActor($args['actor']), $args['note'])->id,
    'receive' => $movement($control->receive($args['product'], $args['quantity'], $args['key'], new StoreOwnerActor($args['actor']), $args['note'])),
    'writeOff' => $movement($control->writeOff($args['product'], $args['quantity'], $args['key'], new StoreOwnerActor($args['actor']), $args['note'])),
    'correct' => $movement($control->correct($args['product'], $args['movement'], new StoreOwnerActor($args['actor']), $args['note'])),
    'count' => (function () use ($counts, $args) {
        $result = $counts->submitCount($args['product'], $args['expected'], $args['observed'], $args['key'], new StoreOwnerActor($args['actor']));

        return ['id' => $result->count->id, 'status' => $result->count->status->value, 'replayed' => $result->replayed, 'resolved' => $result->resolvedCountIds];
    })(),
};

if (isset($job['lock_timeout'])) {
    DB::statement($connection === 'pgsql'
        ? "SET lock_timeout = '".(int) $job['lock_timeout']."s'"
        : 'SET SESSION innodb_lock_wait_timeout = '.(int) $job['lock_timeout']);
}

$connectionId = (int) DB::selectOne(
    $connection === 'pgsql' ? 'select pg_backend_pid() as id' : 'select connection_id() as id'
)->id;
atomicWrite("{$dir}/{$name}.ready", (string) $connectionId);

$waitFor("{$dir}/{$name}.go");

$run = function () use ($job, $operation, $waitFor, $dir, $name, $connectionId) {
    $startedAt = microtime(true);
    $swallowed = null;

    try {
        $value = match (true) {
            (bool) ($job['hold'] ?? false) => DB::transaction(function () use ($operation, $waitFor, $dir, $name) {
                $value = $operation();
                file_put_contents("{$dir}/{$name}.locked", '1');
                $waitFor("{$dir}/{$name}.commit");

                return $value;
            }),
            ($job['compose'] ?? null) === 'rethrow' => DB::transaction(fn () => $operation()),
            ($job['compose'] ?? null) === 'swallow' => DB::transaction(function () use ($operation, &$swallowed) {
                try {
                    return $operation();
                } catch (Throwable $e) {
                    $swallowed = $e::class;

                    return null;
                }
            }),
            default => $operation(),
        };

        $outcome = ['status' => 'ok', 'value' => $value, 'swallowed' => $swallowed];
    } catch (Throwable $e) {
        $outcome = [
            'status' => 'error',
            'class' => $e::class,
            'message' => $e->getMessage(),
            'sqlstate' => $e instanceof PDOException ? $e->getCode() : ($e->errorInfo[0] ?? null),
            'driver_code' => $e->errorInfo[1] ?? null,
        ];
    }

    return $outcome + [
        'connection_id' => $connectionId,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
        'transaction_level' => DB::transactionLevel(),
    ];
};

atomicWrite("{$dir}/{$name}.result", json_encode($run()));

if ($job['retry'] ?? false) {
    $waitFor("{$dir}/{$name}.retry");
    atomicWrite("{$dir}/{$name}.retry_result", json_encode($run()));
}
