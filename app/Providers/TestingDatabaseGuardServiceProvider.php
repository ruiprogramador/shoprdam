<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Fail-closed guard for the `testing` environment: refuses to run
 * destructive Artisan commands if the resolved sqlite connection
 * points at the local development database (database/database.sqlite).
 *
 * This exists because `php artisan ... --env=testing` silently falls
 * back to `.env` (and its unset DB_DATABASE) when `.env.testing` is
 * missing or misconfigured, which previously let migrate:fresh wipe
 * the real development database.
 */
class TestingDatabaseGuardServiceProvider extends ServiceProvider
{
    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
        'db:seed',
    ];

    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
                return;
            }

            if (! $this->app->environment('testing')) {
                return;
            }

            $this->guardAgainstDevelopmentDatabase($event->command);
        });
    }

    private function guardAgainstDevelopmentDatabase(string $command): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $configured = (string) config('database.connections.sqlite.database');

        // Mirrors Illuminate\Database\Connectors\SQLiteConnector::parseDatabasePath's
        // own definition of "in-memory" exactly, so this stays in lockstep with
        // whatever PDO connection Laravel is actually about to open.
        if ($configured === ':memory:'
            || str_contains($configured, '?mode=memory')
            || str_contains($configured, '&mode=memory')
        ) {
            return;
        }

        // Same resolution order as SQLiteConnector::parseDatabasePath, so we are
        // checking the exact path Laravel would connect to, not an approximation.
        $resolved = realpath($configured) ?: realpath(base_path($configured));

        if ($resolved === false) {
            throw new RuntimeException(
                "Refusing to run [{$command}]: the testing environment's DB_DATABASE ".
                "(\"{$configured}\") does not resolve to an existing file. A file-backed ".
                'sqlite testing database must exist on disk (it may be empty) before a '.
                'destructive command can run, so this cannot be proven safe. Create the '.
                'file or fix DB_DATABASE in .env.testing.'
            );
        }

        $devDatabasePath = database_path('database.sqlite');
        $devDatabaseReal = realpath($devDatabasePath);

        // If the dev database can't be resolved either (e.g. it was deleted), fall
        // back to comparing against its deterministic, always-defined path string
        // rather than treating "can't compare" as "must be safe".
        $devComparisonTarget = $devDatabaseReal !== false ? $devDatabaseReal : $devDatabasePath;

        if ($resolved === $devComparisonTarget) {
            throw new RuntimeException(
                "Refusing to run [{$command}]: the testing environment's DB_DATABASE ".
                'resolves to the development database (database/database.sqlite). '.
                'Fix DB_DATABASE in .env.testing to point at a dedicated, disposable '.
                'testing database before running destructive commands.'
            );
        }
    }
}
