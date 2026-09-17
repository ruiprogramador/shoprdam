<?php

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Exercises TestingDatabaseGuardServiceProvider through the real
 * Illuminate\Console\Events\CommandStarting event it listens for in
 * boot(), rather than reflecting into its private method — this runs the
 * exact listener closure Artisan would invoke.
 *
 * We stop short of actually dispatching migrate:fresh/db:wipe/etc via
 * Artisan::call(). Laravel deliberately skips rerouting Symfony's console
 * COMMAND event to CommandStarting while running unit tests (see
 * Illuminate\Foundation\Console\Kernel::__construct -> runningUnitTests()
 * check), so a real Artisan::call() wouldn't even reach this listener the
 * way a standalone `php artisan ... --env=testing` invocation does. Firing
 * the event by hand is both the more faithful integration point available
 * inside Pest and the only one that carries zero risk of a real destructive
 * command executing if the guard were ever buggy.
 *
 * These tests run under APP_ENV=testing (set by phpunit.xml), matching the
 * guard's own environment gate — no manual app()['env'] override needed.
 */
function fireCommandStarting(string $command): void
{
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
}

it('blocks a destructive command when DB_DATABASE is the exact development database path', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => database_path('database.sqlite')]);

    expect(fn () => fireCommandStarting('migrate:fresh'))
        ->toThrow(RuntimeException::class, 'development database');
});

it('blocks a destructive command when DB_DATABASE is a relative path resolving to the development database', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => 'database/database.sqlite']);

    expect(fn () => fireCommandStarting('db:wipe'))
        ->toThrow(RuntimeException::class, 'development database');
});

it('allows a destructive command when DB_DATABASE is a dedicated existing testing database', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => database_path('testing.sqlite')]);

    fireCommandStarting('migrate:fresh');
})->throwsNoExceptions();

it('allows a destructive command when the connection is an in-memory database', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => ':memory:']);

    fireCommandStarting('migrate:fresh');
})->throwsNoExceptions();

it('blocks a destructive command when DB_DATABASE points at a missing/unresolvable file', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => database_path('does-not-exist-'.uniqid().'.sqlite')]);

    expect(fn () => fireCommandStarting('migrate:refresh'))
        ->toThrow(RuntimeException::class, 'does not resolve to an existing file');
});

it('does not gate a non-destructive command regardless of the resolved database', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite.database' => database_path('database.sqlite')]);

    fireCommandStarting('migrate');
})->throwsNoExceptions();
