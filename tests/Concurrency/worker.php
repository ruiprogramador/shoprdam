<?php

/**
 * Child process for tests/Concurrency/InventoryMysqlConcurrencyTest.php — NOT a
 * test, and never loaded by Pest. One invocation = one independent PHP process
 * with its own MySQL connection, performing exactly one canonical inventory
 * operation and writing its outcome to a file the parent reads.
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

use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Order;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$job = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
$settings = config("database.connections.{$connection}");

if ($connection !== 'mysql' || ! in_array($settings['host'], ['127.0.0.1', 'localhost'], true) || ! str_ends_with((string) $settings['database'], '_concurrency_test')) {
    fwrite(STDERR, "worker refuses: not a disposable local *_concurrency_test MySQL database.\n");
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

$order = Order::query()->findOrFail($job['order']);
$service = app(InventoryReservationService::class);

$operation = fn () => match ($job['action']) {
    'reserve' => $service->reserve($order)->pluck('id')->all(),
    'commit' => $service->commit($order),
    'release' => $service->release($order),
};

$connectionId = (int) DB::selectOne('select connection_id() as id')->id;
file_put_contents("{$dir}/{$name}.ready", (string) $connectionId);

$waitFor("{$dir}/{$name}.go");

$startedAt = microtime(true);

try {
    $value = ($job['hold'] ?? false)
        ? DB::transaction(function () use ($operation, $waitFor, $dir, $name) {
            $value = $operation();
            file_put_contents("{$dir}/{$name}.locked", '1');
            $waitFor("{$dir}/{$name}.commit");

            return $value;
        })
        : $operation();

    $outcome = ['status' => 'ok', 'value' => $value];
} catch (Throwable $e) {
    $outcome = [
        'status' => 'error',
        'class' => $e::class,
        'message' => $e->getMessage(),
        'sqlstate' => $e instanceof PDOException ? $e->getCode() : ($e->errorInfo[0] ?? null),
        'driver_code' => $e->errorInfo[1] ?? null,
    ];
}

$outcome += ['connection_id' => $connectionId, 'started_at' => $startedAt, 'finished_at' => microtime(true)];

file_put_contents("{$dir}/{$name}.result", json_encode($outcome));
