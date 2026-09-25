<?php

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;

/**
 * DBPORT-01/03 — cross-engine migration-compilation regression guard.
 * Replaces the narrower, now-deleted `MySqlMigrationIdentifierLengthTest`
 * from `fix/mysql-migration-compatibility` with this single file, covering
 * the same MySQL protection plus a second, independently-discovered
 * PostgreSQL one — see `harden/database-portability`'s own report §M for why
 * a single file with one `it()` block per engine concern was chosen over
 * separate per-engine files: the compilation mechanics are identical across
 * engines, only the quoting character and the identifier-length threshold
 * differ, so factoring that shared mechanic once is more maintainable than
 * duplicating it per engine.
 *
 * ## What this file DOES prove
 *
 * It compiles every migration's up() through Laravel's REAL schema grammar
 * for MySQL and for PostgreSQL — the exact classes that produce the SQL a
 * live server of each engine would receive — using Connection::pretend(),
 * which never opens a live PDO connection (Connection::statement()/select()
 * both return before calling getPdo() when pretending() is true; see
 * vendor/laravel/framework/.../Connection.php). It then scans the compiled
 * SQL text for every quoted identifier and checks it against that engine's
 * real identifier-length limit. This is Laravel's own compiler output, not a
 * hand-rolled SQL parser or a guess at what Laravel would name something.
 *
 * MySQL/MariaDB: identifiers over **64** bytes are hard-rejected by the
 * server (`ER_TOO_LONG_IDENT`) — install fails outright.
 * PostgreSQL: identifiers over **63** bytes (`NAMEDATALEN - 1`) are
 * SILENTLY TRUNCATED by the server, not rejected — a strictly more
 * dangerous failure mode (two different long names can truncate to the same
 * 63-byte prefix and collide, or an operator simply never notices the
 * rename). Postgres's limit is one byte stricter than MySQL's, which is why
 * this file tracks two independent lists, not one shared threshold.
 *
 * `database.default` is switched to the target engine for the duration of
 * one closure and restored in `finally`, because migrations call the plain
 * `Schema::`/`DB::` facades, which resolve the container's *default*
 * connection (this suite's default is `sqlite`) — nothing here changes what
 * connection any other test observes.
 *
 * ## What this file does NOT prove
 *
 * It never opens a socket and never talks to a real server. It does not
 * prove a live instance of either engine accepts the schema: row-size/
 * prefix-key limits, actual foreign-key target existence at creation time,
 * charset/collation acceptance, real CHECK-constraint parsing, and all other
 * server-side validation are not exercised here. It also cannot demonstrate
 * PostgreSQL's *truncation* behavior itself (that genuinely requires a live
 * server) — it only proves which identifiers WOULD be subject to it. The
 * authoritative proof for either engine remains a real fresh migration
 * against a disposable instance; see docs/architecture/DATABASE-SUPPORT.md
 * for exactly what has and hasn't been run.
 *
 * ## Known, tracked, currently-unresolved findings
 *
 * `payment_reconciliation_findings_provider_provider_reference_index` (65
 * chars) and `payment_provider_events_provider_provider_reference_status_index`
 * (64 chars, legal on MySQL, still 1 byte over Postgres's limit) are BOTH
 * pre-existing, already-established migrations (see
 * tests/Architecture/EstablishedMigrationImmutabilityTest.php — DBPORT-03
 * forbids editing them in place). Fixing them for real requires a
 * fresh-install baseline decision this branch could not make without a live
 * database to generate one honestly (see the branch report §E/§F) — so they
 * are asserted here as an EXACT, closed set, not swallowed with a blanket
 * "expected failures" allowance. Any change to this set — whether a NEW
 * regression or the deliberate resolution of one of these two — must touch
 * this test explicitly; neither can happen silently.
 */
function migrationPortabilityFiles(): array
{
    $files = glob(base_path('database/migrations/*.php'));
    sort($files);

    return $files;
}

/** @return list<array{query: string, bindings: array, time: float|null}> */
function migrationPortabilityCompile(string $driver, Closure $connectionResolver): array
{
    Connection::resolverFor($driver, $connectionResolver);

    $original = config('database.default');
    config(['database.default' => $driver]);

    try {
        $connection = app('db')->connection($driver);
        $connection->useDefaultSchemaGrammar();

        return $connection->pretend(function () use ($connection) {
            foreach (migrationPortabilityFiles() as $file) {
                (require $file)->up();
            }

            return $connection;
        });
    } finally {
        config(['database.default' => $original]);
    }
}

function migrationPortabilityMysqlLog(): array
{
    return migrationPortabilityCompile('mysql', function ($pdo, $database, $prefix, $config) {
        return new class($pdo, $database, $prefix, $config) extends MySqlConnection
        {
            // compileRenameColumn() reads these to pick RENAME COLUMN vs.
            // legacy CHANGE syntax — unrelated to identifier length.
            public function isMaria(): bool
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.30';
            }

            // A seeder-style migration's insert() reads this only to
            // pretty-print its *log* entry; the executed statement itself
            // is never touched.
            protected function escapeString($value)
            {
                return "'".str_replace("'", "''", (string) $value)."'";
            }
        };
    });
}

function migrationPortabilityPostgresLog(): array
{
    return migrationPortabilityCompile('pgsql', function ($pdo, $database, $prefix, $config) {
        return new class($pdo, $database, $prefix, $config) extends PostgresConnection
        {
            protected function escapeString($value)
            {
                return "'".str_replace("'", "''", (string) $value)."'";
            }
        };
    });
}

/** @param  list<array{query: string}>  $log */
function migrationPortabilitySql(array $log): string
{
    return implode("\n", array_column($log, 'query'));
}

// ---------------------------------------------------------------------
// MySQL / MariaDB — 64-byte identifier limit (hard rejection)
// ---------------------------------------------------------------------

it('compiles the complete migration history through a real MySQL schema grammar with no identifier over 64 characters, beyond the one known pending defect', function () {
    $sql = migrationPortabilitySql(migrationPortabilityMysqlLog());

    preg_match_all('/`([^`]+)`/', $sql, $matches);
    $identifiers = array_unique($matches[1]);

    $over = array_values(array_filter($identifiers, fn ($id) => strlen($id) > 64));
    sort($over);

    // Exact set, not "not empty": a NEW regression here fails this test just
    // as loudly as silently fixing the known one without updating it would.
    expect($over)->toBe([
        'payment_reconciliation_findings_provider_provider_reference_index',
    ])
        ->and(count($identifiers))->toBeGreaterThan(100);
});

// ---------------------------------------------------------------------
// PostgreSQL — 63-byte identifier limit (silent truncation, not rejection)
// ---------------------------------------------------------------------

it('compiles the complete migration history through a real PostgreSQL schema grammar with no identifier over 63 characters, beyond the two known pending defects', function () {
    $sql = migrationPortabilitySql(migrationPortabilityPostgresLog());

    preg_match_all('/"([^"]+)"/', $sql, $matches);
    $identifiers = array_unique($matches[1]);

    $over = array_values(array_filter($identifiers, fn ($id) => strlen($id) > 63));
    sort($over);

    expect($over)->toBe([
        'payment_provider_events_provider_provider_reference_status_index',
        'payment_reconciliation_findings_provider_provider_reference_index',
    ])
        ->and(count($identifiers))->toBeGreaterThan(100);
});

// ---------------------------------------------------------------------
// Self-tests
// ---------------------------------------------------------------------

it('[self-test] the identifier scanner actually flags an over-limit name for each engine\'s real threshold', function () {
    $mysqlSql = 'alter table `x` add index `'.str_repeat('a', 65).'`(`a`)';
    preg_match_all('/`([^`]+)`/', $mysqlSql, $m);
    expect(array_values(array_filter(array_unique($m[1]), fn ($id) => strlen($id) > 64)))
        ->toBe([str_repeat('a', 65)]);

    $pgSql = 'alter table "x" add index "'.str_repeat('a', 64).'" ("a")';
    preg_match_all('/"([^"]+)"/', $pgSql, $m);
    expect(array_values(array_filter(array_unique($m[1]), fn ($id) => strlen($id) > 63)))
        ->toBe([str_repeat('a', 64)]);
});
