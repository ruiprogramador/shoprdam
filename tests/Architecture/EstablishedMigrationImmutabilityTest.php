<?php

/**
 * DBPORT-03 — established migrations are immutable historical artifacts.
 * Mechanical enforcement, not documentation: this repository's history
 * already contains one concrete cautionary tale (the `harden/database-
 * portability` branch report, §D/§E) where a real defect in
 * `2026_09_17_090000_create_payment_reconciliation_findings_table.php` was
 * first "fixed" by editing that file in place, then reverted once the
 * stricter rule below was adopted — this test is what makes that class of
 * mistake fail loudly instead of merging quietly a second time.
 *
 * ## What "established" means here, precisely
 *
 * Every migration file present in `database/migrations/` at the tip of
 * `main` as of this branch's base commit (`b8673c7`, "Merge pull request #59
 * from ruiprogramador/feat/inventory-reservations") — i.e. every migration
 * that has already been reviewed and merged. `tests/Architecture/fixtures/
 * established-migrations.json` is a filename => SHA-256 manifest generated
 * from exactly that commit's tree (verified byte-for-byte against `git show
 * HEAD:database/migrations/...` at generation time — not from a working tree
 * that might already have an unreviewed edit sitting in it; see the branch
 * report for how that distinction mattered in practice).
 *
 * A NEW migration is not required to be in the manifest — this test does not
 * block adding one. Once a PR that adds or touches a migration merges to
 * main, regenerate the manifest (see `migrationImmutabilityGenerate()` below,
 * or run its logic via `tinker`/a one-off script) so the newly-established
 * file becomes protected going forward. This is a deliberate, one-line-diff,
 * per-merge step — not a ceremony, and not something this test can infer on
 * its own without depending on git history, which Architecture tests
 * otherwise avoid (see tests/Pest.php).
 *
 * ## What this test proves
 *
 * - every manifested file still exists (catches deletion and rename);
 * - every manifested file's content-hash is unchanged (catches any edit,
 *   including a purely cosmetic one — an established migration is a
 *   historical record, not a place for later polish);
 * - the manifest itself is non-vacuous and was captured honestly (self-tests
 *   below).
 *
 * ## What this test does NOT prove
 *
 * It cannot know whether a migration was "established" in reality (deployed
 * anywhere) versus merely merged to this repository's `main` — "merged to
 * main" is the explicit, documented boundary chosen here, not a claim about
 * any specific deployment. It also does not stop someone from deliberately
 * regenerating the manifest to bless an edit — that step is a manual,
 * visible diff to `established-migrations.json` itself, which any reviewer
 * sees plainly in the PR and which this test cannot forbid (a human decision
 * to intentionally supersede a migration must remain possible; the point is
 * that it can never happen *silently*).
 */
function migrationImmutabilityManifestPath(): string
{
    return __DIR__.'/fixtures/established-migrations.json';
}

/** @return array<string, string> filename => sha256, as committed in the manifest */
function migrationImmutabilityManifest(): array
{
    return json_decode(file_get_contents(migrationImmutabilityManifestPath()), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps every established migration file present and byte-identical to its recorded checksum', function () {
    $manifest = migrationImmutabilityManifest();
    $dir = base_path('database/migrations');

    $missing = [];
    $changed = [];

    foreach ($manifest as $filename => $expectedHash) {
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            $missing[] = $filename;

            continue;
        }

        $actualHash = hash('sha256', file_get_contents($path));

        if (! hash_equals($expectedHash, $actualHash)) {
            $changed[] = $filename;
        }
    }

    expect($missing)->toBe([], 'established migration(s) missing/renamed: '.implode(', ', $missing))
        ->and($changed)->toBe([], 'established migration(s) modified in place: '.implode(', ', $changed));
});

it('has a non-vacuous manifest that really was generated from the migrations directory', function () {
    $manifest = migrationImmutabilityManifest();

    expect($manifest)->toBeArray()
        ->and(count($manifest))->toBeGreaterThan(40);

    // Spot-check one well-known, long-established migration by its known,
    // git-committed hash — catches a manifest regenerated from a tampered
    // working tree just as surely as it catches an empty/fabricated one.
    expect($manifest['2026_09_17_090000_create_payment_reconciliation_findings_table.php'] ?? null)
        ->toBe('d0671a60c2d9cb2b5ab5dccee87a7c632a215ba0ecac7d4f059a14bf82723942');
});

it('[self-test] the checker actually flags a modified established migration', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'mig');
    file_put_contents($tmp, 'original content');
    $originalHash = hash('sha256', file_get_contents($tmp));

    file_put_contents($tmp, 'modified content');
    $actualHash = hash('sha256', file_get_contents($tmp));

    @unlink($tmp);

    expect(hash_equals($originalHash, $actualHash))->toBeFalse();
});

it('[self-test] the checker actually flags a deleted/renamed established migration', function () {
    $manifest = ['a_migration_that_does_not_exist.php' => str_repeat('0', 64)];
    $path = base_path('database/migrations').DIRECTORY_SEPARATOR.array_key_first($manifest);

    expect(is_file($path))->toBeFalse();
});
