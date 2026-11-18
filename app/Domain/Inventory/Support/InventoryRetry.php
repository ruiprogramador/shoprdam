<?php

namespace App\Domain\Inventory\Support;

use Closure;
use Illuminate\Database\QueryException;

/**
 * The bounded, narrowly classified retry of the inventory domain
 * (docs/inventory/INVENTORY-CONTROL.md §6.0, §9.3, §10.6).
 *
 * One attempt is one fresh DB::transaction(): a real savepoint when nested, a
 * BEGIN/ROLLBACK otherwise. By the time a QueryException reaches run(), that
 * transaction or savepoint has already been rolled back, so nothing ever
 * continues inside a PostgreSQL transaction left aborted by the error.
 *
 * Two errors are retried, nothing else: the operation's own unique claim being
 * lost to an identical concurrent request, where the retry's pre-check then
 * replays the winner; and MariaDB's snapshot conflict. A deadlock, a lock-wait
 * timeout, a serialization failure or any other constraint violation propagates.
 *
 * Nested in a caller's transaction, MariaDB's snapshot conflict is not retried
 * either. What the framework calls a concurrency error (deadlocks, the MySQL and
 * MariaDB lock-wait timeout, that conflict) comes out of a nested DB::transaction()
 * as a DeadlockException, which is not a QueryException, and with NO savepoint
 * rollback. It must leave the outer transaction too: catching it and committing
 * persists whatever was already written (docs/inventory/INVENTORY-CONTROL.md §24.7).
 */
final class InventoryRetry
{
    public const MAX_ATTEMPTS = 8;

    /**
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @param  Closure(QueryException): bool  $isOwnClaimLost
     * @return T
     */
    public static function run(Closure $attempt, Closure $isOwnClaimLost): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $attempt();
            } catch (QueryException $e) {
                if ($try >= self::MAX_ATTEMPTS || ! ($isOwnClaimLost($e) || self::isMariadbSnapshotConflict($e))) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Whether a database error is the violation of exactly this unique
     * constraint. SQLSTATE and driver code alone are not enough:
     * MySQL/MariaDB 23000 also covers foreign keys and NOT NULL, and another
     * unique index must never pass for the expected race. MySQL and PostgreSQL
     * name the constraint; SQLite names its columns. The match is anchored on
     * the engine's own phrasing, so a value echoed in the message cannot spoof it.
     */
    public static function isUniqueViolationOf(QueryException $e, string $constraint, string $sqliteColumn): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        return match (true) {
            $sqlState === '23505' => str_contains($message, 'unique constraint "'.$constraint.'"'),
            $sqlState === '23000' && $driverCode === 1062 => preg_match("/for key '(?:[^']*\.)?".preg_quote($constraint, '/')."'/", $message) === 1,
            $sqlState === '23000' => preg_match('/UNIQUE constraint failed: .*\b'.preg_quote($sqliteColumn, '/').'\b/', $message) === 1,
            default => false,
        };
    }

    /**
     * MariaDB ships with `innodb_snapshot_isolation=ON` (no such setting on
     * MySQL; PostgreSQL has no equivalent behavior either) — an optimistic
     * check on locking reads that makes `SELECT ... FOR UPDATE` fail with this
     * exact error instead of blocking on the row lock, when the row was written
     * by a transaction that committed after this one's snapshot was taken.
     * Confirmed on real MariaDB 11.8.9
     * (`tests/Concurrency/InventoryMariadbConcurrencyTest.php`) — real MySQL
     * 8.0.44 and PostgreSQL 16 do not exhibit it. MariaDB's own message names
     * the remedy — restart the transaction — which is exactly what a retry from
     * scratch does (never resuming the failed one).
     *
     * Matched narrowly on SQLSTATE + driver code + both fixed phrases of
     * MariaDB's own wording, not on driver/connection name alone: precise
     * enough that this can never swallow a real error (a genuine deadlock,
     * MySQL's own lock-wait-timeout, or anything else uses a different
     * SQLSTATE/code/message and is rethrown unchanged).
     */
    public static function isMariadbSnapshotConflict(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        return $sqlState === 'HY000'
            && $driverCode === 1020
            && str_contains($message, 'Record has changed since last read')
            && str_contains($message, 'try restarting transaction');
    }
}
