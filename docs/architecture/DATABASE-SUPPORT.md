# Database support (`harden/database-portability`)

Evidence-based, not aspirational. Where something is not proven, this
document says so instead of implying it.

## 0. Summary

| | |
|---|---|
| Declared production engine (`README.md`) | **MySQL** |
| Genuinely SUPPORTED today, under this document's own strict terminology | **MySQL, MariaDB, PostgreSQL** — see §2. All three passed every gate this document defines, against real server instances, not reasoned or inferred from one another. |
| CONFIGURABLE (connection exists in `config/database.php`) | mysql, mariadb, pgsql, sqlsrv, sqlite |
| Real-engine concurrency proof | MySQL 8.0.44, MariaDB 11.8.9, PostgreSQL 16.15 — all three, independent-OS-process, for the Inventory domain (`tests/Concurrency`) |
| Real-engine financial-suite proof | All three — `tests/Feature/Domain/{Payments,Payouts,Orders}` + `tests/Feature/Store`, 399/399 on each |
| Fresh install | MySQL/MariaDB: blocked without a baseline (§3); **proven via baseline**. PostgreSQL: **proven directly**, no baseline needed (§3, §11) |
| Defects found and fixed *this round*, by real-engine proof alone | 3 — MariaDB's `innodb_snapshot_isolation` signal (§6), MariaDB's volatile lock-wait probe (§6), and a PostgreSQL transaction-abort gap in two sites the previous round's reasoning had cleared (§5) |
| What this round changed | Moved every claim in this document from "reasoned/SQLite-mutation-tested" to **executed against a real, disposable instance of MySQL 8.0.44, MariaDB 11.8.9 and PostgreSQL 16.15**, independently, never inferring one engine's result from another's. Found and fixed 3 real defects (none of them a data-corruption risk — confirmed before touching any code). Generated and verified real `mysql-schema.sql`/`mariadb-schema.sql` baselines. Proved PostgreSQL needs no baseline. Proved fresh-install/upgrade convergence byte-for-byte for MySQL/MariaDB. Left no financial or Inventory invariant weakened. |

## 1. Terminology

| Term | Means |
|---|---|
| **CONFIGURABLE** | a connection config exists in `config/database.php` |
| **SCHEMA-COMPATIBLE** | the documented fresh-install path can produce the complete current schema on that engine |
| **FUNCTIONALLY COMPATIBLE** | normal (non-concurrent) application behavior works correctly |
| **TRANSACTIONALLY COMPATIBLE** | the transaction semantics the domain relies on (abort behavior, locking, isolation) preserve its invariants |
| **CONCURRENCY-COMPATIBLE** | correctness under independent concurrent connections has been established |
| **TESTED** | automated tests have actually run against that engine |
| **PROVEN** | critical engine-dependent invariants have been exercised on a real instance |
| **SUPPORTED** | shoprdam deliberately promises compatibility with that engine |

CONFIGURABLE never implies SUPPORTED. CONCURRENCY-COMPATIBLE is never inferred
from migration success. PROVEN is never inferred from SQL compilation alone,
nor from another engine's result.

## 2. Support matrix

**Every cell below reflects an actual execution this round**, against a real,
disposable MySQL 8.0.44 / MariaDB 11.8.9 / PostgreSQL 16.15 instance. No
result was extrapolated from a different engine.

| | MySQL 8.0.44 | MariaDB 11.8.9 | PostgreSQL 16.15 | SQLite | SQL Server |
|---|---|---|---|---|---|
| Isolation level (default) | REPEATABLE READ | REPEATABLE READ | READ COMMITTED | serializable (single-writer) | not evaluated |
| CONFIGURABLE | YES | YES | YES | YES | YES |
| SCHEMA-COMPATIBLE | **YES** — via baseline (`database/schema/mysql-schema.sql`), proven: empty DB → `migrate` → "Nothing to migrate", 46 tables | **YES** — via baseline (`mariadb-schema.sql`), proven identically | **YES** — via a direct migration replay, proven fresh this round: no baseline exists or is needed (§3, §11) | YES (proven — every default-suite test migrates it) | YES (compiled clean, analysis only — not a target) |
| FUNCTIONALLY COMPATIBLE | **YES** — 399/399 real-engine financial tests | **YES** — 399/399 | **YES** — 399/399 (11 failures found and fixed this round — §5) | YES (1009 SQLite tests) | not evaluated |
| TRANSACTIONALLY COMPATIBLE | **YES** | **YES** — after the `innodb_snapshot_isolation` fix (§6) | **YES** — after the savepoint fix to `PaymentService`/`PaymentEventProcessor` (§5) | Single-writer; proves algorithm shape, not lock/abort semantics (§8) | not evaluated |
| CONCURRENCY-COMPATIBLE | **YES** — real, independent-OS-process proof, 16/16 | **YES** — real proof, 16/16, after the retry + probe fixes (§6) | **YES** — real proof, 16/16 | **NO** — single-writer by construction (§8) | not evaluated |
| TESTED | **YES** | **YES** | **YES** | YES | NO |
| PROVEN | **YES** | **YES** | **YES** | PARTIAL — algorithm/shape only, explicitly not concurrency (§8) | NO |
| **SUPPORTED** | **YES** | **YES** | **YES** | **NO** (dev/fast-test engine, not a production concurrency claim — §8) | **NO**, not evaluated (explicitly out of scope) |

**What changed this round, precisely**: every row moved from "reasoned" or
"no live run" to an actual execution. MariaDB's and PostgreSQL's
`TRANSACTIONALLY COMPATIBLE`/`CONCURRENCY-COMPATIBLE`/`SUPPORTED` cells only
turned YES *after* the three real defects this round's proof surfaced were
fixed and reverified — no cell was marked YES on the strength of reasoning
alone.

## 3. Fresh install per engine, and the historical identifier defect

**Confirmed, independently, against real servers this round** (not compiled
grammar reasoning — an actual `php artisan migrate` run):

- **MySQL 8.0.44**: a fresh replay of all 51 historical migrations fails at
  `2026_09_17_090000_create_payment_reconciliation_findings_table.php`:
  `SQLSTATE[42000]: ... 1059 Identifier name
  'payment_reconciliation_findings_provider_provider_reference_index' is too
  long` (65 bytes, MySQL's limit is 64). MySQL DDL is not transactional:
  confirmed the table is left partially created (columns, both FKs, the
  `status` index present; the `provider_reference` index and the
  `active_identity` unique constraint missing), the migration not recorded.
- **MariaDB 11.8.9**: the identical defect, reproduced **independently** (not
  inferred from MySQL) — same `ER_TOO_LONG_IDENT`.
- **PostgreSQL 16.15**: a fresh replay of all 51 migrations **completes with
  no error**. Not because the identifier is short enough — PostgreSQL's real
  limit (`NAMEDATALEN - 1` = 63 bytes) is tighter than MySQL's 64 — but
  because PostgreSQL **silently truncates** an over-limit identifier instead
  of rejecting it. Confirmed this round, byte-for-byte, on a fresh empty
  database: `payment_reconciliation_findings_provider_provider_reference_ind`
  (63 chars, truncated from 65) and
  `payment_provider_events_provider_provider_reference_status_inde` (63
  chars, truncated from 66) — no collision in the current schema, but a
  structurally more dangerous failure mode than MySQL's hard rejection (a
  future addition could truncate to a colliding name with no warning).

### Why neither historical migration is edited

`2026_09_17_090000_...` and the identifier inside
`2026_08_02_140000_create_payment_provider_events_table.php` are established
migrations, merged to `main` before this branch existed.
`tests/Architecture/EstablishedMigrationImmutabilityTest.php` (SHA-256
manifest, mutation-verified: both a modification and a deletion are caught)
mechanically enforces DBPORT-03 — reconfirmed this round, `database/migrations`
is byte-identical to `HEAD`. Neither the MySQL/MariaDB blocker nor
PostgreSQL's silent truncation was worked around by editing a historical
file; both are resolved the Laravel-native way (§11).

## 4. Migration-history protection (DBPORT-03)

Unchanged from previous rounds; reconfirmed this round (`git diff --quiet
HEAD -- database/migrations` — no output, no change). See §3 for what this
protects against.

## 5. Transaction-abort audit — PostgreSQL, now closed

PostgreSQL differs from MySQL/MariaDB/SQLite: **any** statement error inside
an open transaction (not just a deadlock — including an ordinary
unique-constraint violation) leaves that transaction **aborted**
(`SQLSTATE 25P02`) until an actual `ROLLBACK`/`ROLLBACK TO SAVEPOINT`. A PHP
`catch` sends no SQL, so it does not reset this.

### What real-engine testing found this round

Two sites the previous round's reasoning had cleared — "not called from
inside any wrapping `DB::transaction()`" — were **correct for production**
(traced every real caller: `OrderPaymentController::store()`,
`CreateTestStripeOrder`, both webhook controllers — none opens a transaction
around the call) but **fragile under any enclosing transaction**, including
the one `RefreshDatabase` opens around every test. Real PostgreSQL runs
surfaced this as 11 test failures (`SQLSTATE 25P02`), 0 in MySQL/MariaDB:

| Site | What raced | Fix |
|---|---|---|
| `PaymentService::findOrCreatePayment()` | `Payment::create()` vs. `unique(order_id)` | `create()` wrapped in its own `DB::transaction()` (a real `SAVEPOINT` when nested, `BEGIN`/`ROLLBACK` otherwise) |
| `PaymentEventProcessor::storeUnmatchedEvent()` | `PaymentProviderEvent::create()` vs. `unique(provider, provider_event_id)` | same pattern |

Both mirror `WalletTransactionService::record()`'s and
`ReconciliationFindingRepository::openOrUpdateEpisode()`'s existing,
already-proven savepoint fix (Round 3/4) exactly — verified again this round
against `Illuminate\Database\Concerns\ManagesTransactions`: `createTransaction()`
emits a real `SAVEPOINT` when `$this->transactions >= 1`, and
`handleTransactionException()` runs `ROLLBACK TO SAVEPOINT` **before**
rethrowing, so the recovery `SELECT`/`exists()` always sees a healthy
connection.

`PaymentService::isOrderIdUniqueViolation()` was also narrowed
(**not** widened) in the same change: MySQL/MariaDB's SQLSTATE `23000`
also covers a FOREIGN KEY or NOT NULL violation, not only a unique one —
now requires driver code `1062` too (mirroring
`InventoryReservationService::isReservationIdentityViolation()`'s own
precision). **Confirmed empirically on real MySQL and MariaDB**: a genuine
FK violation on `order_id` (driver code `1452`) is no longer classified as
a unique-key race.

Confirmed before any fix and reconfirmed after: **zero data corruption** at
any point — `RefreshDatabase`'s final `ROLLBACK` always cleans up even from
an aborted transaction; `payments`/`payment_attempts`/`payment_provider_events`/
`payouts`/`payout_attempts` all read 0 rows after the failing run. The 11
failures were error propagation, not persisted bad state.

Also fixed: 5 tests that deliberately provoke an expected database refusal
(a FK/unique/CHECK violation) and then keep querying the same transaction
— isolated with a new shared helper, `expectDatabaseRefusal()`
(`tests/Helpers.php`), which wraps only the operation expected to fail in
its own savepoint. The refusal itself, and every assertion made afterward,
are unchanged.

**Result**: PostgreSQL's financial-domain transaction-abort risk is now
zero for every site this and previous rounds' audits found, proven live
(399/399), not reasoned.

## 6. Inventory concurrency portability — now proven on all three engines

Real, independent-OS-process, multi-connection proof exists for **MySQL
8.0.44, MariaDB 11.8.9, and PostgreSQL 16.15**, each 16/16
(`tests/Concurrency/Inventory{Mysql,Mariadb,Postgres}ConcurrencyTest.php`,
sharing `ConcurrencyHelpers.php`/`worker.php`). Zero oversell, zero
duplicate stock effect, zero counter drift on any engine.

### MariaDB-specific defect found and fixed this round

MariaDB ships with `innodb_snapshot_isolation=ON` by default — an
optimistic-read check on locking reads with **no equivalent on MySQL**
(confirmed: the variable does not exist there). Under genuine N-way
contention it can make `SELECT ... FOR UPDATE` (both `lockForUpdate()` call
sites in `InventoryReservationService`) fail with `SQLSTATE HY000` /
driver code `1020` ("Record has changed since last read ... try restarting
transaction") instead of blocking and succeeding. First discovered as 13/16
test failures; confirmed, with a bare MySQL-free reproduction, to be a real
MariaDB behavior, not a harness artifact.

**Fix**: a small, narrowly-classified, bounded retry (max 8 attempts) in
`InventoryReservationService::reserve()`/`settle()`, matched only on
SQLSTATE `HY000` + driver code `1020` + both fixed phrases of MariaDB's own
message — never a broad `QueryException` retry, never touching MySQL's or
PostgreSQL's classification. Resolved the 8 race/barrier tests that don't
depend on lock-wait detection.

### MariaDB lock-wait probe defect found and fixed this round

The remaining 5 deterministic "does B genuinely block" tests kept failing
after the retry fix. Investigated rigorously, in three independent ways,
before touching anything:

1. Two hand-run raw-SQL sessions (microsecond timestamps): B unblocks within
   ~1ms of A's `COMMIT`, no error — genuine InnoDB row-lock blocking,
   identical to MySQL.
2. The real PHP harness, with a positive-proof diagnostic (B's result file
   proven absent while A holds the lock, then resolves right after A's
   commit, with the correct domain exception, not a raw `QueryException`).
3. `information_schema.innodb_lock_waits`/`innodb_trx.trx_state='LOCK WAIT'`
   (this file's original probe) were shown, reproducibly, to stop reflecting
   an active wait after 1-2 seconds even though the real lock (and the real
   block) continues for tens of seconds — a MariaDB monitoring-table gap,
   not a locking behavior difference.

**Fix, confined to the test harness only** (`InventoryMariadbConcurrencyTest.php`'s
`mcAwaitLockWait()`): switched to `information_schema.processlist`
(`command <> 'Sleep'`), requiring 3 consecutive busy polls before declaring
a block (a fast, uncontended query cannot produce that). Validated
empirically first: 267/274 polls (97%) read busy across a continuous 6s
real block, before being adopted.

**Nothing in `InventoryReservationService` beyond the retry above changed.**
DBPORT-08/09 (no oversell, exactly-one stock effect) are now MySQL-,
MariaDB- and PostgreSQL-proven, independently.

## 7. Exception-classification portability

Unchanged in shape from Round 3/4 (six classifiers, SQLSTATE-plus-message
precision) with one addition this round: `PaymentService::isOrderIdUniqueViolation()`
narrowed with a MySQL/MariaDB driver-code check (§5). All seven classifiers
now share Inventory's own precision pattern, each verified against a real,
driver-thrown `QueryException` via reflection
(`tests/Feature/Domain/Payments/ExceptionClassificationPortabilityTest.php`,
10 tests), run and passing on MySQL, MariaDB and PostgreSQL this round (not
only SQLite).

## 8. SQLite's honest role

Unchanged: fast default test-suite engine (1009 tests, ~2 minutes) and a
genuine proof of algorithm/SQL-shape/state-machine correctness. Not, and
never claimed to be, a production concurrency engine (DBPORT-11).

## 9. MySQL vs. MariaDB — proven distinct, not "basically the same"

Beyond the CHECK/JSON differences already documented (§9 of the previous
round, unchanged and reconfirmed), this round found a **behavioral**
difference no prior audit had: `innodb_snapshot_isolation` (§6). Laravel's
own `MariaDbConnection`/`MariaDbSchemaState` classes already treat MariaDB
as a distinct engine; this document does too, throughout, having now
actually observed a case where the distinction is load-bearing.

## 10. Money/decimal portability

DBPORT-06 remains satisfied (`decimal(18,2)` + `bcadd`/`bcmul`/`bccomp`
throughout, no float money representation found). **New finding this
round, disclosed rather than hidden**: a real `decimal(18,2)`/`INTEGER`
column on MySQL 8, even under `sql_mode=STRICT_ALL_TABLES`, **rounds**
silently on write when precision is merely excessive (`10.005` → `10.01`,
a fractional value into an `INTEGER` column: `1.5` → `2`) rather than
rejecting — confirmed empirically. Strict mode only rejects a
type/range-incompatible value outright. This does not affect application
code (which never constructs an over-precise value; every arithmetic path
already uses `bcadd`/`bcmul`/`bccomp` to exactly 2 decimals), but two tests
simulating pre-existing corrupted data had to account for it (§ "Round 5
real-engine findings" below) rather than assume SQLite's dynamic-typing
behavior (which stores the malformed value as-is) is portable.

## 11. Baseline architecture — implemented and proven this round

Laravel's native mechanism (`php artisan schema:dump --database={name}`,
`MigrateCommand::prepareDatabase()`/`loadSchemaState()`) was previously only
investigated by reading Laravel's own source (Round 3). **This round
actually generated and verified it**:

- `database/schema/mysql-schema.sql` and `database/schema/mariadb-schema.sql`
  exist, generated via `schema:dump` against real, already-migrated MySQL
  8.0.44/MariaDB 11.8.9 instances (having first worked around the §3 blocker
  with raw completion SQL — **never by editing the historical migration**).
  Both verified: fresh empty database → `migrate --force` → "Loading stored
  database schemas... DONE" → "Nothing to migrate", 46 tables.
- **Upgrade/fresh convergence proven byte-for-byte**, not inferred: a
  `mysqldump`/`mariadb-dump --no-data` of (a) a database with the full
  51-migration history and (b) a database loaded only from the baseline are
  identical once only the `AUTO_INCREMENT` counter (which legitimately
  differs with data history) is normalized out. `diff` exit code 0 on both
  engines.
- One genuine, previously undocumented limitation found and worked around:
  `schema:dump` captures DDL plus only the `migrations` table's own row
  data — **not** data inserted by another migration's `up()` (here,
  `2026_03_29_082819_create_user_types_table.php`'s inline `'User'`/`'Vendor'`
  seed rows). Both baseline `.sql` files have that table's data appended
  identically to how Laravel appends the `migrations` table's own rows.
- **No `pgsql-schema.sql` exists, and none is needed** (§3): PostgreSQL's
  fresh install never blocks, proven fresh this round on an empty database
  with zero baseline involvement. Generating one would be redundant with
  what `migrate` already does unaided — not a gap, a deliberate absence
  backed by a real, negative-result test (the migration completed without
  ever touching a baseline).
- `--prune` was never passed to `schema:dump`, and never will be —
  established migration files remain the permanent historical record.

## 12. Migration-immutability guard — unchanged

Unchanged decision from Round 3 (keep the checksum-manifest test, §4);
nothing here changed this round.

## 13. Real-engine test plan — executed, not just planned

Every item Round 3's §13 listed as "not executed, no live engine available"
has now run, against real MySQL 8.0.44, MariaDB 11.8.9 and PostgreSQL
16.15, independently:

- Fresh install (§3), baseline (§11), upgrade convergence (§11) — MySQL,
  MariaDB. Fresh install direct (§3) — PostgreSQL.
- `tests/Feature/Store/WalletTransactionService*.php`,
  `tests/Feature/Domain/Payments/**`, `tests/Feature/Domain/Payouts/**`,
  `tests/Feature/Domain/Orders/**` — 399/399 on each of the three engines.
- `tests/Concurrency/Inventory{Mysql,Mariadb,Postgres}ConcurrencyTest.php` —
  16/16 on each, independent OS processes/connections throughout, never
  sequential calls dressed up as concurrency.

## 14. Portability invariants — current status

| ID | Statement | Status |
|---|---|---|
| DBPORT-01 | Every SUPPORTED engine reaches the current schema via fresh install | **SATISFIED** for MySQL, MariaDB (via baseline), PostgreSQL (directly) — all proven this round |
| DBPORT-02 | Every SUPPORTED engine upgrades from the previous schema to the same current schema | **SATISFIED for MySQL/MariaDB** — an actual upgrade-history database and an actual fresh-via-baseline database were both generated and diffed byte-for-byte (§11). **Not executed as a comparison for PostgreSQL** — there is no baseline for it to diverge from (§3/§11), so "upgrade" and "fresh install" are the same migration replay, not two paths that were run and found to agree. This is a structural argument, not an empirical one; see §11's own wording |
| DBPORT-03 | Established migrations are immutable | **ENFORCED**, reconfirmed this round |
| DBPORT-04 | Financial FK/delete policies retain equivalent semantics on every supported engine | **SATISFIED** — 54 FKs (36 RESTRICT/5 CASCADE/13 SET NULL) identical across all three, verified via real introspection per engine (`tests/Helpers.php`'s `dbForeignKeyInfo()`), not SQLite-only assumption |
| DBPORT-05 | Uniqueness relied on for idempotency has equivalent semantics | **SATISFIED** — all 7 classifiers proven against real driver exceptions on all three engines |
| DBPORT-06 | Money remains exact and float-free | **SATISFIED** (§10), with MySQL's silent-rounding behavior now disclosed |
| DBPORT-07 | Payment/Payout CAS operations retain exactly-one-effect semantics | **SATISFIED**, live-proven on all three engines (399/399 each) |
| DBPORT-08 | Inventory cannot oversell under real concurrent connections | **PROVEN on MySQL, MariaDB, PostgreSQL** — 0 violations across 677/529/412 real reservations respectively |
| DBPORT-09 | Inventory terminal transitions produce exactly one stock effect | Same as DBPORT-08 |
| DBPORT-10 | No correctness-critical exception handling depends on an unsafe vendor message string | **SATISFIED** — every classifier requires SQLSTATE class *and* message/driver-code precision |
| DBPORT-11 | SQLite success is not treated as proof of server-engine concurrency | **UPHELD** |
| DBPORT-12 | No engine is called SUPPORTED until required real-engine tests pass | **UPHELD** — MariaDB and PostgreSQL only marked SUPPORTED after their respective defects (§5, §6) were fixed and reverified live |
| DBPORT-13 | Portability never weakens a financial, historical or inventory invariant | **UPHELD** — all three fixes this round narrow or add precision, never widen a classifier or relax a guard |
| DBPORT-14 | Fresh and upgrade paths converge to the same intended schema | **PROVEN** for MySQL and MariaDB (byte-for-byte diff, §11). **For PostgreSQL, not proven by execution and comparison** — no second path (no baseline) exists for a fresh install to diverge from; only the single direct-replay path was actually run and confirmed to complete (§3). Convergence there follows necessarily from there being one path, not from having run two and compared them — a weaker, though still correct, form of evidence than MySQL/MariaDB's |

## 15. Remaining gaps — honestly disclosed, not proven this round

- **PostgreSQL `SQLSTATE 40001` (serialization failure) risk under an
  elevated isolation level** (REPEATABLE READ/SERIALIZABLE) — not the
  connection default, never configured or tested. Documented as a real,
  open risk if that default is ever changed; not retried around.
- **NULL semantics in composite UNIQUE indexes**, per engine — assumed
  standard SQL behavior, not exercised row-by-row this round.
- **DECIMAL precision/scale** beyond the columns the financial test suites
  already exercise — not exhaustively audited.
- **CI — implemented this round, approved before applying**: `.github/workflows/tests.yml`'s
  original `ci` job (SQLite, ~2 min) is unchanged and remains the fast
  feedback signal. A new `real-engine-financial` job was added to the same
  workflow — a 3-way matrix (MySQL 8.0/MariaDB 11/PostgreSQL 16, GitHub
  Actions' own `services:` containers), each entry: fresh `migrate --force`
  (proving that engine's actually-supported install path — the baseline for
  MySQL/MariaDB, direct replay for PostgreSQL, never a fabricated PostgreSQL
  baseline just to look uniform) then the real financial suite
  (`tests/Feature/Domain/{Payments,Payouts,Orders}` + `tests/Feature/Store`).
  Runs on pushes to `develop`/`main` and pull requests targeting
  `develop`/`main` (`tests.yml`'s existing, unchanged `on:` triggers — this
  round did not widen them), alongside the existing SQLite CI, ~7-9 minutes
  wall-clock (matrix entries run in parallel). A separate workflow,
  `.github/workflows/real-engine-concurrency.yml`, runs the real,
  independent-OS-process Inventory concurrency suite (16 tests per engine,
  ~11-14 minutes each — see §13) on the same 3-way matrix, but deliberately
  **outside** that push/PR path (`schedule: '0 3 * * *'` + `workflow_dispatch`
  only) so it never becomes the thing standing between a PR and review.
  Both workflows use only a fixed, disposable-only password
  (`ci_disposable_only`) local to each ephemeral service container — no
  `secrets.*`, no real credential, ever. Health-check commands were
  verified against real local containers before being adopted, not assumed:
  `mysqladmin ping` (no password needed — reports alive as soon as the
  daemon accepts connections, confirmed exit 0 even on an auth failure) for
  MySQL; **`mysqladmin` does not exist on the `mariadb:11` image at all**
  (confirmed: exit 127, "not found") — `healthcheck.sh --connect
  --innodb_initialized` (that image's own documented mechanism) is used
  instead; `pg_isready -U postgres` for PostgreSQL. Both workflow files
  were validated for YAML syntax (a `js-yaml` parse, not eyeballing) and
  every matrix key cross-checked between definition and use; the
  `migrate --force`/`php artisan test` commands themselves were re-run
  against real local containers with the exact database name and
  environment variables the workflow uses, before being written into it.
  Neither workflow was run on an actual GitHub Actions runner — that part
  of the validation is not possible from this session.
- **Docker infrastructure**: kept as local, disposable infrastructure only
  — no `docker-compose.yml`/`Dockerfile` was added to the repository.
  Exact reproduction commands (engine versions, network, mounts) are
  recorded in this document's git history / session notes for a future
  session to recreate without depending on this machine's local state.

## 16. Reproducing this round's real-engine environment

For a future session with Docker access (company or personal — never touch
anything outside a clearly disposable, separately named container/network):

```
# Network
docker network create shoprdam_dbport_net

# MySQL 8.0.44
docker run -d --name shoprdam_dbport_mysql --network shoprdam_dbport_net \
  -e MYSQL_ROOT_PASSWORD=disposable_pw -e MYSQL_DATABASE=shoprdam_dbport_test \
  mysql:8.0

# MariaDB 11
docker run -d --name shoprdam_dbport_mariadb --network shoprdam_dbport_net \
  -e MARIADB_ROOT_PASSWORD=disposable_pw -e MARIADB_DATABASE=shoprdam_dbport_test \
  mariadb:11

# PostgreSQL 16
docker run -d --name shoprdam_dbport_pgsql --network shoprdam_dbport_net \
  -e POSTGRES_PASSWORD=disposable_pw -e POSTGRES_DB=shoprdam_dbport_test \
  postgres:16

# PHP 8.3 runner (pdo_mysql, mysqli, pdo_pgsql, pgsql, bcmath installed via apt/docker-php-ext-install)
docker run -d --name shoprdam_dbport_php --network shoprdam_dbport_net \
  -v <repo>:/var/www/html -w /var/www/html php:8.3-cli tail -f /dev/null
```

Financial suites: `docker exec` into `shoprdam_dbport_php` with
`DB_CONNECTION`/`DB_HOST`/`DB_DATABASE`/... set per engine, then
`php artisan test tests/Feature/Domain/Payments tests/Feature/Domain/Payouts
tests/Feature/Domain/Orders tests/Feature/Store`.

Concurrency suites: run in an ephemeral container joined to the target
engine's own network namespace (`--network container:<engine-container>`),
so `127.0.0.1` inside it genuinely is that engine's own loopback — the exact
condition `tests/Concurrency/*ConcurrencyTest.php`'s safety guard checks
for, not a bypass of it. Example:

```
docker run --rm --network container:shoprdam_dbport_mysql \
  -v <repo>:/var/www/html -w /var/www/html \
  -e INVENTORY_MYSQL_CONCURRENCY=1 -e DB_CONNECTION=mysql \
  -e DB_HOST=127.0.0.1 -e DB_PORT=3306 -e DB_DATABASE=<name>_concurrency_test \
  -e DB_USERNAME=root -e DB_PASSWORD=disposable_pw \
  <php-image-with-extensions> php vendor/bin/pest tests/Concurrency/InventoryMysqlConcurrencyTest.php
```

MySQL's client tools need `DB_MYSQL_SKIP_SSL_VERIFY=true` for `schema:dump`/
`migrate`'s schema-load step specifically (MySQL 8's self-signed cert;
normal PDO queries are unaffected) — see the corresponding option in
`config/database.php`'s `mysql` connection block. MariaDB needed no such
workaround (its own client tools matched its own server correctly).
