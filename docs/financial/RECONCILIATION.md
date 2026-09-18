# Financial Reconciliation — Design Document

**Status: Phase 1 IMPLEMENTED** (branch `feat/financial-reconciliation`,
branched from `main` at `3d74ae6`) **and hardened** by a post-implementation
evidence-correctness audit (§13) that found and fixed a real gap: an
earlier implementation pass treated any non-retryable retrieval failure as
confirmed resource absence, which was not evidenced. See §13 for the full
finding and §21 for which `RECON-XX` invariants are now mechanically
`ENFORCED` by a named test vs. still `PROPOSED`. `ARCHITECTURE.md` §9 and
`INVARIANTS.md`'s `RECON-XX` section have been updated to reflect Phase 1's
existence — this document remains the detailed design/audit record; those
are the canonical summaries.

**Revision note (original design-hardening pass):** the previous version of this document
labeled `REMOTE_SUCCEEDED_LOCAL_PENDING` as automatically actionable via
`PaymentAttemptRecoveryService::recover()`/`PaymentService::finalizeAttempt()`
without tracing whether those methods actually reach a settled state when no
webhook was ever delivered. §3 below traces the real code, line by line, and
the answer is **no — they do not, in the case that matters most.** Every
section downstream of that finding has been revised accordingly: **Phase 1
of this design now contains zero automatic financial actions.** It is a
detection-and-persistence-only system. This is not a weaker design than the
previous draft — it is the same design corrected to stop claiming a
convergence guarantee the code doesn't provide.

See also: `ARCHITECTURE.md` §4 (existing recovery/reconciliation ownership),
`INVARIANTS.md` (registry this would eventually extend), `FAILURE-MODEL.md`
(crash-point methodology reused here), `MONEY-FLOWS.md` (money flows this
must never duplicate).

---

## 1. Scope

Split explicitly into phases, because the previous single-pass design
understated how differently buildable/provable each part is.

- **Phase 1 (this document's implementation-ready scope):** Direction 1
  (local → provider) for **both** Stripe and EasyPay, using the
  already-existing `SupportsCanonicalRetrieval` capability. Detects
  mismatches, persists them as findings (§9, §10), reports them (§15).
  **Performs no automatic financial action of any kind** — §3's trace is
  why.
- **Phase 2 (explicitly deferred, not designed in implementation detail
  here):** Direction 2 (provider → local bulk listing), which needs a new
  `SupportsPeriodicExport`-shaped capability. Stripe's SDK can support it;
  EasyPay's actual API capability is unverified and is **not researched or
  guessed at** in this branch (per explicit instruction) — Phase 2 starts
  with Stripe only, and either confirms or explicitly rules out EasyPay
  support before claiming parity.
- **Phase 3+ (not designed here at all, contingent on a decision this
  document does not make):** any automatic financial corrective action.
  §3 and §7 show that no such action can honestly be proposed today, for
  any mismatch category, because no existing canonical code path converges
  a polled provider result into settlement without a real, delivered (or
  previously stored) provider event. Building one — a genuine "settle from
  a canonical retrieval, not just from a webhook" capability — is a
  separate, financially load-bearing design decision that deserves its own
  document and review, not a rider on this one.

Payouts are out of scope at every phase — see §9.2.

---

## 2. Current-state audit

(Unchanged from the prior pass except where §3 below supersedes a claim —
retained here for context.)

### What exists (verified against source)

| Concern | Class / file | What it actually does |
|---|---|---|
| Per-attempt exact settlement | `PaymentEventProcessor::markSettled()` | Resolves by `(payment_id, provider, provider_reference)` — never `current_payment_attempt_id`. Reached only from `apply()`, itself reached only by a translated webhook event or a replayed stored one — see §3. |
| Orphaned-attempt recovery | `App\Console\Commands\ReconcileOrphanedPaymentAttempts` | Two candidate sets: (a) `Pending` attempts stale by `created_at` — recovered via `PaymentAttemptRecoveryService::recover()`; (b) attempts with a claim but a stuck `pending` row in `payment_provider_events` — recovered via `PaymentService::finalizeAttempt()`'s replay step. Neither ever asks the provider "what do you say about an already-`Claimed` reference with no stuck event." |
| Unmatched webhook inbox | `payment_provider_events` / `PaymentEventProcessor::storeUnmatchedEvent()` / `replayUnmatchedEvents()` | A *push*-side queue for events that arrived before a local claim existed or before the sale was `completed`. Never polls a provider; only replays what already arrived. |
| Health snapshot | `php artisan payments:health` (`PaymentsHealthCheck`) | Read-only, local-staleness only. Never asks a provider anything. |
| Ledger-balance reconciliation | `php artisan wallet:audit` (`WalletLedgerAuditor`) | Entirely internal — never touches a provider. Detect-only, no `--fix` (`LEDGER-07`) — the direct precedent for Phase 1's own "detect, never correct" posture (§4). |
| Manual admin recovery | `PaymentRecoveryController` | Runs the *exact same* algorithms as the scheduled commands — never a separate "admin version." |

### Migrations / FK / unique constraints

`payment_attempts`: `unique(['provider', 'provider_reference'])`, nullable
`provider_reference`. `payment_recovery_actions.payment_attempt_id`:
`restrictOnDelete()` — the precedent this design's own finding-to-attempt FK
follows (§9). CROSS-14's migration is why a new financial-adjacent table
defaults to `restrictOnDelete()`, never `cascadeOnDelete()`.

---

## 3. Execution trace: does existing code converge without a webhook?

This is the load-bearing section of this revision. Every claim below is a
direct reading of `App\Domain\Payments\Services\PaymentService`,
`PaymentAttemptRecoveryService`, and `PaymentEventProcessor` as they exist
today — no inference, no assumption.

### 3.A — Pending + provider says succeeded + no webhook was ever delivered

Trigger: `PaymentAttemptRecoveryService::recover($attempt, ...)` on a
`Pending` attempt (the same call the scheduled command already makes for any
stale `Pending` attempt, regardless of reconciliation).

1. Age check passes (not yet exceeded) → `acquireLease()` — CAS `UPDATE ...
   WHERE status = 'pending'`, wins.
2. Calls `PaymentService::finalizeAttempt($attempt)`.
3. `finalizeAttempt()`: `$attempt->provider_reference === null` is true (it's
   `Pending`, never claimed) → calls `claimProviderReference($attempt)`.
4. `claimProviderReference()`: calls `$provider->createOrGetPayment($attempt)`
   — **not** `retrieveByReference()`. For Stripe, this is
   `paymentIntents->create([...], ['idempotency_key' => $attempt->idempotency_key])`.
   Because a PaymentIntent already exists under that exact idempotency key
   (that's *why* the provider reports "succeeded" when reconciliation
   independently checks it), Stripe's own idempotency guarantee returns the
   **same, already-succeeded** PaymentIntent rather than creating a new one.
   `assertResultMatchesAttempt()` passes (amount/currency/correlation
   genuinely match).
5. Persists the claim: conditional `UPDATE payment_attempts SET
   provider_reference = ..., status = 'claimed' WHERE id = ? AND
   provider_reference IS NULL` — wins. In the **same transaction**, calls
   `WalletTransactionService::record(..., status: 'pending', ...)` —
   creates a **pending** `sale` transaction. **Nothing here confirms it.**
6. Back in `finalizeAttempt()`: `$attempt = $attempt->fresh()` (now
   `Claimed`), then `$this->eventProcessor->replayUnmatchedEvents($attempt->provider,
   $attempt->provider_reference)`. This method's entire body is: `SELECT *
   FROM payment_provider_events WHERE provider = ? AND provider_reference = ?
   AND status = 'pending' ORDER BY id`. **If no webhook was ever delivered,
   this table has zero rows for this reference, and the query returns an
   empty collection.** The `->each()` loop executes zero times.

**Result: the attempt is now `Claimed`, with a `pending` (not `completed`)
Wallet transaction. `Payment.status` is still `Pending`. `PaymentEventProcessor::applySucceeded()`
was never called. The transaction is never confirmed, the Wallet balance is
never credited, `Order.order_status_id` never moves to `paid`.** `recover()`
made real, safe, idempotent progress (closing the exact crash window
`PaymentAttempt`'s own docblock describes) — but it did **not** settle the
payment, and nothing downstream of it will, until a real webhook (or a
manually stored/replayed event) eventually arrives. If one never arrives,
**this attempt is now `Claimed` forever**, and is *invisible to every other
existing mechanism*: `ReconcileOrphanedPaymentAttempts`'s first query only
selects `status = 'pending'` (this is now `claimed`); its second query
requires an *existing* `pending` `payment_provider_events` row (there isn't
one). `payments:health` measures local staleness only. Nothing currently
running ever looks at this attempt again.

**Conclusion: `recover()` does not converge case A to settlement. Labeling
`REMOTE_SUCCEEDED_LOCAL_PENDING` (local = `Pending`) as "automatically
actionable" was wrong.**

### 3.B — Claimed + provider says succeeded + no `PaymentProviderEvent` exists

Trigger: `PaymentService::finalizeAttempt($attempt)` directly (the action
`PaymentRecoveryController::replayClaimedAttempt()` and
`ReconcileOrphanedPaymentAttempts`'s second query both use).

1. `$attempt->provider_reference !== null` is true → `claimProviderReference()`
   is **skipped entirely**.
2. `$this->eventProcessor->replayUnmatchedEvents($attempt->provider,
   $attempt->provider_reference)` — same query as 3.A step 6: zero pending
   rows exist → zero iterations.

**Result: nothing happens at all. This call is a pure no-op.** No provider
call is even made (Stripe/EasyPay are never contacted from this path — only
`replayUnmatchedEvents()` runs, which is 100% local). It reads back the same
`Claimed` attempt it started with.

**Conclusion: `finalizeAttempt()` provides zero convergence for case B. It
must not be listed as an action for this case at all — calling it teaches
the system nothing and changes nothing.**

### 3.C — Claimed + provider says succeeded + a `pending` `PaymentProviderEvent` *does* exist

Trigger: same call as 3.B, `PaymentService::finalizeAttempt($attempt)`.

1. `claimProviderReference()` skipped (already claimed).
2. `replayUnmatchedEvents()`'s query now returns the stored pending event
   row. For each: `$translator->reconstructFromReplayPayload($stored->payload)`
   rebuilds the provider's native event shape from the allow-listed stored
   fields; `$translator->translate($nativeEvent)` produces a
   `ProviderEventOutcome{type: Succeeded, ...}`; `$this->apply($outcome)` →
   `applySucceeded()` → finds the `pending` sale transaction by
   `(external_provider, external_reference)`, calls
   `WalletTransactionService::confirm()` (credits the wallet,
   `pending → completed`), then `markSettled()` (`PaymentStatus::Paid`,
   `PaymentAttemptStatus::Succeeded`, `Order.order_status_id → paid`). The
   stored event row is marked `applied`.

**Result: this genuinely settles** *when `finalizeAttempt()` is actually
called*. §3.C alone only proves the mechanics of that call — it does not
yet prove the **existing scheduled system** actually calls it for this
state deterministically, without reconciliation's help. §3.D traces that
specifically, since it's the exact question item 3 requires an answer to
before this taxonomy category can be called "self-resolving."

### 3.D — Does the existing scheduler deterministically reach 3.C's state on its own?

Trigger: `App\Console\Commands\ReconcileOrphanedPaymentAttempts`, scheduled
`->everyFiveMinutes()->withoutOverlapping()` (`routes/console.php:13-16`,
read directly for this trace — not assumed from `ARCHITECTURE.md`'s prose
description, though it agrees).

1. The command's **second query** (candidate set 2, `handle()`'s second
   `PaymentAttempt::query()` block):
   ```php
   PaymentAttempt::query()
       ->whereNotNull('provider_reference')
       ->whereExists(function ($query) use ($eventsTable, $staleAfter) {
           $query->selectRaw('1')->from($eventsTable)
               ->whereColumn("{$eventsTable}.provider", 'payment_attempts.provider')
               ->whereColumn("{$eventsTable}.provider_reference", 'payment_attempts.provider_reference')
               ->where("{$eventsTable}.status", ProviderEventStatus::Pending)
               ->where("{$eventsTable}.created_at", '<=', now()->subMinutes($staleAfter));
       })
   ```
   This is a plain, deterministic SQL predicate — not a heuristic, not
   best-effort. It selects **every** `PaymentAttempt` with a
   `provider_reference` (i.e. `Claimed`, `Succeeded`, or `Failed` — deliberately
   not narrowed to `Claimed`, per the command's own docblock) for which a
   `payment_provider_events` row exists matching that *exact*
   `(provider, provider_reference)` pair, is still `Pending`, and has been
   `Pending` for at least `--stale-after` minutes (default **5**).
2. Each match is passed to `processAttemptWithUnmatchedEvents()`, whose
   entire body is `$paymentService->finalizeAttempt($attempt)` — exactly
   the call traced in §3.C.
3. §3.C's mechanics then apply unconditionally: `claimProviderReference()`
   is skipped (`provider_reference` is non-null by the query's own filter),
   `replayUnmatchedEvents()` finds the matching pending event (guaranteed
   to exist — it's the row the `whereExists` just matched against) and
   settles it via `PaymentEventProcessor::applySucceeded()`.
4. Whether the underlying sale transaction is still `pending` at that
   instant or was already `completed` by a live webhook that won the race
   in the meantime, the outcome converges either way:
   `WalletTransactionService::confirm()` settles it if still `pending`;
   `TransactionNotPendingException` is caught as a silent no-op if another
   path already completed it — `applySucceeded()` still marks the event row
   `applied` and returns `Applied` in both cases, so the event is not left
   `pending` forever regardless of which path actually did the settling.

**Conclusion: yes, this is deterministic, not merely likely.** Given a
`Claimed` (or later) attempt with an exact-matching `pending`
`payment_provider_events` row, the existing scheduler will select it and
call the exact code path §3.C already proved converges, within a bounded
number of ticks: the event must first age past `--stale-after` (default 5
minutes) *and* the command runs every 5 minutes, so convergence happens on
the first tick after the event turns 5 minutes old — worst case just under
10 minutes from when the event was stored, best case just over 5. This is
an existing, already-scheduled, already-tested (`ReconcileOrphanedPaymentAttemptsTest`,
per `FAILURE-MODEL.md` crash-point #6) behavior; reconciliation contributes
nothing new to it and must not claim credit for it or duplicate it (§8).
A dedicated regression test making this exact trace explicit is required
before Phase 1 code lands regardless (§17) — this document's own trace is
not a substitute for one.

### What this proves

There is exactly one code path in this entire codebase that reaches
`PaymentEventProcessor::applySucceeded()`: a translated `ProviderEventOutcome`,
sourced either from a live webhook delivery or from replaying a **previously,
independently stored** `PaymentProviderEvent` row. **There is no code path
today that settles a payment from a polled `ProviderPaymentResult`
(`retrieveByReference()`'s own return type) at all.** This is not a bug to
route around — it's a real, load-bearing architectural fact
(`ARCHITECTURE.md` §3's own diagram: `provider-native event/response → ...
→ canonical Event Processor` — the arrow's source is always an *event*,
never a *poll result*). Building a second arrow into that processor from a
poll result, even carefully, is exactly the "second settlement path" §4
forbids, no matter how the trigger condition is phrased. Phase 1 therefore
does not attempt it, and no category in §8 is marked automatically
actionable.

---

## 4. Definitions — what "financial reconciliation" means here

Unchanged from the prior pass — see original definitions for (A) Ledger
reconciliation, (B) Provider reconciliation (this branch's subject), (C)
Event/replay reconciliation (already owned — §3.C), (D) Recovery (already
owned — §3.A/B show its actual scope), (E) Corrective settlement (never
performed by this branch, at any phase decided so far — §3's conclusion).

---

## 5. Non-negotiable safety boundary

Unchanged in spirit, sharpened by §3: reconciliation never mutates the
Wallet, never sets `Payment`/`PaymentAttempt`/`Order` status directly, never
constructs a synthetic `ProviderEventOutcome` (§3's conclusion makes this
temptation concrete and names exactly why it's rejected), never infers
success/failure from a timeout, and — new in this revision — **never calls
`PaymentAttemptRecoveryService::recover()` or `PaymentService::finalizeAttempt()`
as a "corrective action" for a reconciliation finding**, because §3 proves
neither one reliably converges the case reconciliation would be invoking it
for. Phase 1 is read-only with respect to the Payments domain: it calls
`retrieveByReference()` (a GET, no side effect on the provider) and writes
only to its own new table.

---

## 6. Authority model

Unchanged from the prior pass — provider is authoritative for
succeeded/failed; local DB is authoritative for attempt existence; amount/
currency mismatches favor neither side automatically; webhook validation is
inherited for free from the existing adapters (§7). One addition following
§3: **"local Claimed, remote succeeded, no stored event" is now understood
to mean "we have no local proof this was ever properly delivered to us,"
not "we have local state that merely hasn't caught up yet"** — the
distinction matters for §8's severity assignment.

---

## 7. Proposed architecture (Phase 1 only)

```
                    ┌─────────────────────────────┐
                    │  Local candidate selection   │   durable read, no lock
                    │  (PaymentAttempts with a      │
                    │   provider_reference, past     │
                    │   a configurable minimum age)  │
                    └──────────────┬────────────────┘
                                   │
                                   ▼
                    ┌─────────────────────────────┐
                    │ retrieveByReference() (§7.1)  │   OUTSIDE any DB transaction
                    │ — read-only provider call      │   idempotent by construction
                    └──────────────┬────────────────┘
                                   │
                          success  │  failure (timeout/5xx/connection)
                     ┌─────────────┴─────────────┐
                     ▼                           ▼
      ┌───────────────────────────┐   ┌─────────────────────────┐
      │ Classification (§8)        │   │ Log + metric only (§13)  │
      │ pure function, no I/O       │   │ — NEVER a finding row     │
      └──────────────┬──────────────┘   └─────────────────────────┘
                      │
                      ▼
      ┌───────────────────────────┐
      │ Short transactional        │   CAS on `active_identity`
      │ upsert of the episode      │   (§9.1)
      │ (§9, §10)                   │
      └──────────────┬──────────────┘
                      │
                      ▼
      ┌───────────────────────────┐
      │ Log the observation (§13)  │   No automatic action follows —
      │ — that's the entire        │   see §3, §8. Every open episode
      │ pipeline. Full stop.        │   is surfaced for a human, never
      └───────────────────────────┘   acted on automatically.
```

Phase 1 has no "delegate to canonical action" box, unlike the previous
draft's diagram — §3 removed it.

### 7.1 Provider capabilities used

`App\Domain\Payments\Contracts\SupportsCanonicalRetrieval::retrieveByReference()`
— already implemented by both `StripePaymentProvider` and
`EasyPayPaymentProvider` (verified by reading both — §2 of the prior audit
pass). `SupportsPeriodicExport` (Direction 2) is not designed in
implementation detail in this document — a placeholder name only, deferred
to Phase 2 (§1).

One additional, narrow capability was added during implementation, after an
evidence-correctness audit (§13) found the original plan's reliance on
`classifyFailure()` alone insufficient:
`App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence::isConfirmedAbsent(Throwable $e): bool` —
optional, mirroring `SupportsCanonicalRetrieval`'s own optionality exactly.
`StripePaymentProvider` implements it (`$e instanceof ApiErrorException && $e->getHttpStatus() === 404`
— Stripe's own REST convention, already relied upon elsewhere in this exact
codebase via `getHttpStatus()`). `EasyPayPaymentProvider` deliberately does
**not** implement it: `EasyPayRequestException`'s own docblock buckets
400/403/404/422 together as equally "will fail identically on every retry,"
with no distinction this codebase can verify between "doesn't exist" and
any other definitive rejection. This is the one place in Phase 1 where a
few lines of genuinely new provider code were required — not a
Direction-2-shaped expansion, a precision fix to Direction 1's own evidence
correctness (§13).

---

## 8. Mismatch taxonomy (Phase 1, revised)

Every category below is **not automatically actionable** — see §3 for why
this is now uniform across the whole table, not just some rows. `severity`
and `evidence` are still meaningfully differentiated because they drive
triage priority even without automation.

| Category | Meaning | Evidence required | Severity | Self-resolves without intervention? |
|---|---|---|---|---|
| `MATCH` | Local and remote agree | Status/amount/currency/correlation all agree | — | Closes an open episode (§10), never opens one |
| `REMOTE_SUCCEEDED_AWAITING_REPLAY` | Provider says succeeded; local attempt is `Pending`/`Claimed`; a `pending` row **already exists** in `payment_provider_events` for this exact `(provider, provider_reference)` | Exact reference match; a corroborating stored event found | Low | **Yes, deterministically** — §3.D traces the exact existing scheduled path (`ReconcileOrphanedPaymentAttempts`'s candidate set 2 → `finalizeAttempt()` → `replayUnmatchedEvents()` → `PaymentEventProcessor::applySucceeded()`) and shows it is a plain, non-heuristic SQL predicate that will select and settle this state within a bounded number of scheduler ticks (5–10 minutes at default config), independent of reconciliation. Reconciliation's role is purely informational — its log line should say so explicitly, not imply a human needs to act |
| `REMOTE_SUCCEEDED_NO_SETTLEMENT_PATH` | Provider says succeeded; local attempt is `Pending`/`Claimed`; **no** stored/pending event exists at all for this reference | Exact reference match; a targeted query confirming no `payment_provider_events` row exists | **High** | No — §3.A/B prove nothing in this codebase settles this without a real event ever arriving. Stays open indefinitely unless a webhook eventually arrives (in which case it becomes `REMOTE_SUCCEEDED_AWAITING_REPLAY` momentarily, then resolves) or a human intervenes out of band |
| `REMOTE_FAILED_LOCAL_PENDING` | Provider says failed/declined/canceled; local is `Pending`/`Claimed` | Exact match | High | No — no canonical path turns "provider says failed" into a local terminal state outside a real webhook (`CROSS-09`) |
| `REMOTE_PENDING_LOCAL_TERMINAL` | Local is `Succeeded`/`Failed`; provider now reports something non-terminal | Exact match; genuinely surprising | High | No |
| `REMOTE_MISSING` | Local has a `provider_reference`; provider **specifically and positively confirms** the exact resource doesn't exist | The resolved provider implements `SupportsConfirmedResourceAbsence` and its `isConfirmedAbsent()` returns `true` for the exact exception `retrieveByReference()` threw (§7.1, §13) — **never** inferred merely from `FailureClass::NonRetryable`, which also covers auth failure, permission failure, a malformed request, and other definitive rejections that prove nothing about existence | High | No |
| `AMOUNT_MISMATCH` | Provider's amount differs from the local Order's | Exact match; amount differs | High | No |
| `CURRENCY_MISMATCH` | Provider's currency differs | Exact match; currency differs | High | No |
| `CORRELATION_MISMATCH` | Provider's echoed correlation id doesn't match the local Order | Exact match; correlation differs | High | No |
| `UNSUPPORTED_REMOTE_STATE` | A refund-shaped/partial-refund-shaped/otherwise-unhandled remote state (§16) | Remote state doesn't map to any known local transition | Medium–High (context-dependent) | No |
| `AMBIGUOUS` | Evidence inconsistent in a way no more specific category covers | — | Medium | Only by investigation |

`PROVIDER_UNAVAILABLE` (implemented as `ReconciliationOutcomeType::RetrievalFailed`
— renamed once its scope grew beyond transient outages, see §13) is
**removed from this table entirely**. It is not a mismatch category and is
never persisted as a finding — and, critically, it is what a **non-retryable**
retrieval failure produces too, unless the resolved provider specifically
confirms absence (§7.1, §13). `FailureClass::NonRetryable` alone was
initially (incorrectly) treated as sufficient evidence for `REMOTE_MISSING`
during Phase 1's first implementation pass — corrected before merge; see
§13's evidence-correctness audit for the full trace of why that was wrong
and what replaced it.

---

## 9. Persistence: findings as bounded episodes, not lifetime rows or an event log

The previous draft's "one lifetime row per `(provider, provider_reference)`,
reopened forever" model is replaced. It could not honestly answer: *"a
reference was mismatched in March, resolved, ran clean for four months, then
broke again in August for an unrelated reason — what happened?"* — reopening
the same row would silently overwrite `first_observed_at` and blur two
unrelated incidents into one. Full event-sourcing (a row per observation)
was rejected too, per explicit instruction and because §10 shows it isn't
needed to answer the real questions.

**Chosen model: option C — bounded episodes, with a mechanism guaranteeing
at most one *active* (unresolved) episode per `(provider, provider_reference)`
at a time, and an unlimited number of *historical* (resolved) episodes for
that same reference over its lifetime.**

An episode is the continuous span from when a mismatch was first observed
until it is either no longer observed or explicitly closed. Re-classifying
an *already-open* episode's category (e.g. `REMOTE_MISSING` today,
`AMOUNT_MISMATCH` on the next check because the reference existed but was
momentarily unindexed) is **not** a new episode — it's continuing evidence
about one ongoing incident. A **new** episode opens only after the prior one
for that exact reference has `resolved_at` set.

### 9.1 The uniqueness mechanism (portable across SQLite/MySQL/PostgreSQL)

A native partial unique index (`WHERE resolved_at IS NULL`) is not portable
— SQLite supports it, PostgreSQL supports it, **MySQL does not**, and this
codebase's own unique-violation handling
(`PayoutEventProcessor::isUniqueViolation()` checks both `'23000'` and
`'23505'`) shows it's written to support more than one driver. Phase 1
reuses a pattern **this codebase already relies on for an identical shape of
problem**: `payment_attempts.provider_reference` is nullable under a unique
index specifically so multiple not-yet-claimed rows (all `NULL`) can coexist
while at most one *claimed* row per reference can ever exist. Mirrored here:

- A new column, `active_identity` (nullable string), application-computed
  as `"{provider}:{provider_reference}"` **only while the episode is open**
  (`resolved_at IS NULL`), and set to `NULL` in the exact same write that
  sets `resolved_at` (closing it).
- `unique(active_identity)`. Every driver treats multiple `NULL`s as
  distinct under a unique index — proven already by
  `payment_attempts`' own migration comment — so any number of *resolved*
  episodes for the same reference coexist freely, while the database itself
  refuses a second simultaneously-open one.

This answers §9's threat model directly: a resolved finding reappearing
after months simply opens a **new** row (the old one's `active_identity` is
already `NULL`, so nothing blocks the insert); two concurrent workers
racing to open an episode for the same reference collide on the unique
index, and the loser re-reads and updates the winner's row instead (§11).

### 9.2 Payout scope — unchanged

Still entirely out of scope, at every phase, for the same reason as before:
`ManualPayoutProvider` has no remote system to reconcile against
(`PayoutProviderContract`'s own docblock anticipates this and declines to
add polling until a real automatic provider exists).

---

## 10. Lifecycle — two independent axes, not one overloaded status

Per explicit instruction: lifecycle and acknowledgement are **not** the
same axis, and acknowledgement is never allowed to imply correction. A
third, worker-ownership axis was considered and **removed** in this
revision — see §11 for why no lease is needed and what protects against
duplicate work instead.

| Axis | Values | Who sets it | What it means |
|---|---|---|---|
| **Episode status** (the only truth-about-the-mismatch axis) | `open`, `resolved` | System only, from observation | `open` = a mismatch is currently believed to exist. `resolved` = it is no longer observed. Phase 1 writes exactly one resolution reason for this (§18) — see the transition table below for why a second, corrective-action-based reason is deliberately not defined yet. |
| **Acknowledgement** (human-attention axis, fully independent) | `acknowledged_at` / `acknowledged_by`, both nullable | A human, via a future admin surface (not built in Phase 1 — see §21) | Records that a person has seen this episode. **Never changes `status`, `resolved_at`, or anything else.** An episode can be `open` **and** acknowledged simultaneously — that combination is the correct representation of "a human knows about this real, still-existing problem," which is exactly the state the previous draft's model couldn't express without looking falsely resolved. |

Phase 1 never sets `actionable = true` for anything (§3, §8) — there is
**no `actionable` column in Phase 1's schema at all**. Adding one now, for a
capability that doesn't exist and isn't designed, would be exactly the kind
of speculative column this document's own earlier draft was rightly
pressured to avoid elsewhere; a future phase that actually proves a
convergent action adds its own column against its own migration, justified
by its own trace (mirroring §3's method, not skipping it).

### Exact transitions

| Event | Transition |
|---|---|
| First mismatch observed for a reference with no currently-open episode | Insert new episode: `status=open`, `first_observed_at=last_observed_at=now()`, `observation_count=1`, `active_identity` set |
| Same mismatch observed again on a later pass | Update the same open episode: `last_observed_at=now()`, `observation_count++`. `first_observed_at` untouched |
| Category changes while still continuously unresolved | Same open episode: `category` updates, `observation_count++` — not a new episode (no resolution occurred in between) |
| Mismatch no longer observed (a later pass sees `MATCH`) | `status=resolved`, `resolved_at=now()`, `resolution_reason='no_longer_observed'`, `active_identity=NULL` |
| Operator acknowledges | `acknowledged_at=now()`, `acknowledged_by=$admin->id`. **`status` and `resolved_at` are untouched** — this is the explicit fix for the previous draft's flaw |
| Provider unavailable during this pass | No transition of any kind to any episode — see §13; nothing about this candidate's evidence changed, so nothing about its episode should either |
| A resolved reference mismatches again later | A **new** episode row (§9.1) — the old row's history is preserved exactly as it was, untouched |

**No row exists in this table for "a delegated corrective action is
confirmed to have converged."** Phase 1 has no corrective action (§3, §5),
so there is nothing for such a transition to describe, and no
`resolution_reason` value is reserved for it in this revision — see §18.
If a future phase introduces a genuine corrective action, it will define
its own resolution semantics through its own reviewed migration, against
its own proven convergence trace, the same way §3 required for this one;
this document does not pre-decide what that will look like.

There is no `needs_attention` status distinct from `open` in this revision.
Per instruction ("do not encode actionability implicitly through lifecycle
status"), and given §3/§8 make *every* Phase-1 category equally
non-actionable, a separate status value would carry no additional
information over `open` — severity (§8) is what actually differentiates
urgency, and it's its own column, not folded into status.

---

## 11. Concurrency

The `unique(active_identity)` constraint (§9.1) is the entire CAS backbone
for the one thing that actually needs correctness protection — the episode
*write* — via insert-and-recover-on-unique-violation, identical idiom to
`WalletTransactionService::record()`'s own `findByReference()`-then-resolve
pattern. No provider HTTP call ever happens inside the transaction that
writes an episode row (§7's diagram, §12). Since Phase 1 delegates no
action (§3, §5), the entire "reconciliation vs. recovery service" race
class from the previous draft no longer applies — there is nothing for
reconciliation to race against, because it never writes to
`payment_attempts`, `payments`, or any Wallet table.

### 11.1 Why the finding row carries no worker lease

The previous draft added a `locked_until` column to the finding, mirroring
`payment_attempts.locked_until`. Re-examined against what Phase 1 actually
does, that lease doesn't own anything a lease is for:

- `payment_attempts.locked_until` exists because the work it protects — a
  provider call plus a multi-column claim write — happens *against a row
  that already exists*, and two workers racing on it could both call the
  provider and both attempt the claim write; the lease makes the second
  one back off before it does either.
- Phase 1's candidate selection reads `PaymentAttempt` rows (which already
  exist and are not being leased by reconciliation), then calls
  `retrieveByReference()` (a read-only GET with no side effect on the
  provider — calling it twice for the same reference produces two identical
  results, not two different ones), then writes to a finding row **that
  may not exist yet** at the moment the work starts. A lease on a row that
  doesn't exist protects nothing; a lease acquired only after the GET
  already happened would arrive too late to prevent the one thing worth
  preventing (a duplicate GET).
- The actual correctness requirement — at most one *episode* per
  `(provider, provider_reference)` — is already fully guaranteed by
  `unique(active_identity)` (§9.1) regardless of how many workers reached
  that point concurrently: whichever write commits first wins, the second
  recovers onto the same row exactly like every other insert-and-recover
  path in this codebase. No lease makes that guarantee any stronger.

**What a lease would have prevented, and why that's acceptable without
one:** two reconciliation workers (or two overlapping scheduler ticks)
selecting the same `PaymentAttempt` candidate and each independently
calling `retrieveByReference()` for it. This is a wasted, duplicate
provider API call — an operational cost, never a correctness risk, since
the call is read-only and idempotent by nature (asking Stripe/EasyPay
"what's the status of X" twice returns the same answer twice; nothing
about the provider's own state changes). This class of duplication is
already bounded by the same **operational, not correctness**, mechanism
`ARCHITECTURE.md` §4 already documents as insufficient-for-correctness-but-fine-operationally
for the two existing reconciliation commands: `withoutOverlapping()->onOneServer()`
on the scheduled command itself. Phase 1's command uses the identical
scheduling pattern for the identical reason — it keeps duplicate GETs rare
in the ordinary case without needing them to be *impossible*, since
impossible was never a requirement here. No new lease mechanism is
introduced. If a future operational need for one is demonstrated (e.g. very
large candidate sets making even rare duplication costly), it would be
justified and added then, against that concrete evidence — not spent now
against a correctness property it wouldn't actually own.

---

## 12. Provider HTTP boundary

Unchanged shape, simplified content (no step 5 "delegated action" anymore):

```
1. Durable local candidate read (no lock)         — outside any transaction
2. Provider HTTP call: retrieveByReference()        — outside any transaction,
                                                        read-only, idempotent
3. Pure classification (§8)                        — no I/O, no lock
4. Short transaction: upsert the episode (§9, §10)  — the only DB write
```

Crash-point analysis:

| # | Crash point | State before crash | Recovery |
|---|---|---|---|
| 1 | Before the provider call | Nothing written | Next run repeats from scratch |
| 2 | Provider responds, process dies before classification | Nothing written | Next run re-calls the provider — a GET with no side effect |
| 3 | Classified, dies before the step-4 upsert commits | Nothing written | Next run redoes 1–4 |
| 4 | Episode persisted, process dies | Episode reflects the real, current mismatch | Nothing further was ever going to happen automatically (§3) — the next pass simply re-observes and updates it normally |

---

## 13. `RetrievalFailed` — operational signal, never a finding — and the `RemoteMissing` evidence contract

**Post-implementation revision.** Phase 1's first implementation pass
classified *any* non-retryable retrieval failure as confirmed absence
(`REMOTE_MISSING`) — i.e. `FailureClass::NonRetryable` alone was treated as
sufficient evidence. A dedicated evidence-correctness audit, conducted
before merge, found this **incorrect**: `FailureClass::NonRetryable` is a
much broader bucket than "confirmed this resource doesn't exist." Reading
`StripePaymentProvider::classifyFailure()`/`EasyPayPaymentProvider::classifyFailure()`
directly shows it also fires for authentication failure, permission
failure, a malformed request, a card error, and an idempotency conflict —
none of which say anything about whether the underlying resource exists.
`EasyPayRequestException`'s own docblock makes this concrete: it buckets
400/403/404/422 together as equally "will fail identically on every retry,"
with no distinction between "not found" and "forbidden"/"unprocessable."
Treating that whole bucket as `REMOTE_MISSING` would have meant this
codebase could persist a durable, High-severity, human-facing claim —
"this payment doesn't exist at the provider" — from evidence that only
actually proved "the request failed." This section states the corrected
contract; §7.1 describes the capability that implements it.

**The corrected evidence contract:**

> `RemoteMissing` may be emitted only when the resolved provider both
> implements `SupportsConfirmedResourceAbsence` and that provider's own
> `isConfirmedAbsent()` returns `true` for the exact exception
> `retrieveByReference()` threw. Every other retrieval failure — retryable
> or not — is a `RetrievalFailed` operational outcome: never persisted as a
> finding, and never touches an already-open one.

Concretely, per provider (§7.1, §2's original audit):

- **Stripe**: `isConfirmedAbsent()` checks `$e instanceof ApiErrorException && $e->getHttpStatus() === 404` —
  Stripe's own REST convention, distinct from 400 (malformed), 401
  (authentication), 403 (permission), and 402/`CardException` (a declined
  card, meaningless for a GET). The HTTP status is what's authoritative
  here, not the exception subtype — `InvalidRequestException` is thrown for
  both a 404 and a 400.
- **EasyPay**: does not implement the capability at all. No verified
  evidence in this codebase distinguishes EasyPay's "not found" response
  from any other definitive rejection — so **EasyPay retrieval failures can
  never produce a `RemoteMissing` finding in Phase 1**, only
  `RetrievalFailed`. This is a real, documented gap (Option 3 of the
  evidence-correctness audit: "it is better to lose `RemoteMissing`
  detection in Phase 1 than to persist a false financial assertion"), not
  an oversight — see `INVARIANTS.md`'s Known Non-Guarantees.

A retrieval failure (of any kind, for any provider) is **never written to
`payment_reconciliation_findings` at all**. Overloading the findings table
with operational/outage/inconclusive rows would force every future query
("show me open financial mismatches") to filter out non-financial noise,
and risks exactly the confusion §5/§6 exist to prevent. Concretely:

- Each retrieval failure is a structured `Log::warning` line — `provider`,
  the attempt id, the exception class, and whether it was retryable (never
  a raw provider exception message — same allowlist rule as
  `RecoveryErrorFormatter`). **No episode is touched for that candidate this
  pass, under any circumstance** — not created, not updated, not resolved,
  not re-categorized, and its `observation_count` is not incremented, even
  if an episode is already open for that exact identity. Only an
  authoritative later observation (a real `Found` result, or a genuinely
  confirmed absence) may ever change an episode. This is proven by
  `ProviderReconcilerTest`'s "a retrieval failure never touches an
  already-open finding for the same identity" test (§17, item I).
- The reconciliation command's own run summary counts retrieval failures
  per provider for that run and prints/logs them alongside the mismatch
  counts (§15) — visible, but explicitly labeled as an operational metric,
  never a financial finding.
- **Repeated** failures becoming visible as a health concern (e.g., "Stripe
  reconciliation has failed on every run for six hours") remains an
  observability design question for `payments:health`-style thresholds, not
  a reason to introduce a persistence table for it in Phase 1 — unchanged
  from the original design.

---

## 14. Automatic-action matrix (Phase 1: zero actionable rows, evidenced)

Rewritten from §3's trace, not from the taxonomy. Every row below is
identical in structure so the "no" is never a placeholder — each one names
what was actually checked.

| Category | Exact local precondition | Exact remote evidence | Automatically actionable? | Existing method that would be called | Why it does or doesn't converge | Idempotency owner | If the process crashes right after (N/A rows: nothing was triggered) | If no webhook ever arrives |
|---|---|---|---|---|---|---|---|---|
| `REMOTE_SUCCEEDED_AWAITING_REPLAY` | `Pending`/`Claimed`, `provider_reference` set | `retrieveByReference()` status = succeeded; a `pending` `payment_provider_events` row already exists for this reference | **No** — not because it's unsafe, but because §3.D proves the existing scheduler already deterministically converges it without reconciliation's help | None triggered by reconciliation | §3.D: `ReconcileOrphanedPaymentAttempts` candidate set 2 is a deterministic SQL predicate (not a heuristic) that selects exactly this state and calls `finalizeAttempt()` → `replayUnmatchedEvents()` → `PaymentEventProcessor::applySucceeded()`, proven in §3.C to settle correctly either way a concurrent webhook races it | `PaymentEventProcessor` + the existing scheduled command (pre-existing, unmodified) | N/A — reconciliation triggers nothing | Not applicable — a stored event already exists in this category by definition, so "no webhook ever arrives" cannot occur here |
| `REMOTE_SUCCEEDED_NO_SETTLEMENT_PATH` | `Pending`/`Claimed`, `provider_reference` set | `retrieveByReference()` status = succeeded; **no** stored event exists | **No** — §3.A/B prove `recover()`/`finalizeAttempt()` do not settle this | None | §3.A: `recover()` advances `Pending→Claimed` + a `pending` Wallet row, but never confirms it. §3.B: `finalizeAttempt()` on an already-`Claimed` attempt is a pure no-op with no stored event. Neither reaches `PaymentEventProcessor::applySucceeded()`. Calling either was proven, not assumed, insufficient | N/A — no action taken | N/A | **Stays `open` indefinitely.** This is the single most important documented gap this design surfaces (§3): there is currently no canonical way to settle this state without a real webhook eventually arriving (or a human resolving it out of band) |
| `REMOTE_FAILED_LOCAL_PENDING` | `Pending`/`Claimed` | Provider reports failed/declined/canceled | **No** | None | `CROSS-09`: no canonical path turns provider-reported failure into a local terminal state outside a real webhook, by explicit design | N/A | N/A | Stays `open`; resolves only if a genuine webhook later settles it either way |
| `REMOTE_PENDING_LOCAL_TERMINAL` | `Succeeded`/`Failed` | Provider reports non-terminal | **No** | None | No method in this codebase "un-terminalizes" an attempt; building one is a new settlement path | N/A | N/A | Stays `open` — needs investigation, not automation |
| `REMOTE_MISSING` | `provider_reference` set | Provider **specifically confirms** absence via `SupportsConfirmedResourceAbsence::isConfirmedAbsent()` (Stripe 404 only in Phase 1 — never EasyPay, never a generic `NonRetryable`, per §13's audit) | **No** | None | Ambiguous by construction (§6) — no safe default assumption exists | N/A | N/A | Stays `open` |
| `AMOUNT_MISMATCH` / `CURRENCY_MISMATCH` / `CORRELATION_MISMATCH` | Any | Provider's value differs from the Order's | **No, never** | None | §6: neither side is unconditionally authoritative for these facts | N/A | N/A | Stays `open` |
| `UNSUPPORTED_REMOTE_STATE` | Any | Refund/partial-refund/unrecognized-shaped remote state | **No, structurally** | None | §16: the underlying operation is unsupported; there is nothing to converge *to* | N/A | N/A | Stays `open` |
| `AMBIGUOUS` | — | Internally inconsistent evidence | **No** | None | Fallback only | N/A | N/A | Stays `open` |

**Zero rows are automatically actionable.** This is the correct, evidenced
answer to the confirmed instruction: *"If any automatic action still
depends on an assumed webhook/event that may never exist, or cannot prove
convergence through existing canonical code: READY TO IMPLEMENT PHASE 1:
NO"* would apply to any row that claimed otherwise — none does, so this
matrix itself is not what blocks Phase 1 (see the Final Report's verdict for
what actually remains open).

---

## 15. Observability

- CLI command (e.g. `app:reconcile-payments`), options parsed via the
  existing `ConfigInteger::parse()` (never a bare `(int)` cast), scheduled
  with `withoutOverlapping()->onOneServer()` like the existing two commands
  — an operational safeguard, not a correctness mechanism (§11 shows why
  none is needed here anyway).
- Per-episode structured logs on open/update/resolve, same `[...context]`
  shape as every other financial log line in this codebase (`provider`,
  `provider_reference`, `category` — never a raw provider response body).
- Per-run summary: counts by category, count of currently-open episodes by
  severity, count of provider-call failures this run (§13) — all directly
  queryable from the table without new aggregation infrastructure.
- Exit code mirrors `wallet:audit`/`payments:health`: `SUCCESS` when no
  `open` episode exists (or, if that's too strict operationally, a
  configurable severity threshold — an implementation-time judgment call,
  not a design blocker).
- No raw secrets, credentials, or unsanitized provider exception text ever
  reach a log line — same allowlist-only rule as `RecoveryErrorFormatter`.

---

## 16. Unsupported operations

Unchanged: partial refunds, EasyPay refunds, and disputes are recognized,
never invented handling for. A refund/dispute-shaped remote observation is
always `UNSUPPORTED_REMOTE_STATE` → stays `open`, is never partially
applied, never silently dropped.

---

## 17. Test strategy

- **Classification unit tests** — one per §8 row, pure function, no DB,
  including the new `REMOTE_SUCCEEDED_AWAITING_REPLAY` vs.
  `REMOTE_SUCCEEDED_NO_SETTLEMENT_PATH` split (must correctly query for an
  existing `pending` `payment_provider_events` row to distinguish them).
- **§3's trace, encoded as regression tests** — this is new, and arguably
  the most important addition: a test that creates a `Pending` attempt,
  calls `PaymentAttemptRecoveryService::recover()` with the provider
  already reporting `succeeded`, and asserts the attempt ends at `Claimed`
  with a `pending` (not `completed`) transaction — i.e., a test that
  actively proves §3.A's claim and would fail loudly if a future change to
  `PaymentService`/`PaymentEventProcessor` ever made this converge
  differently, which would in turn mean this document's automatic-action
  matrix needs re-review, not silent staleness.
- **§3.D's trace, encoded as a regression test** — required by item 3's own
  instruction: a test that stores a `Pending` `payment_provider_events` row
  for a `Claimed` attempt's exact `(provider, provider_reference)`, ages it
  past the default `--stale-after` (5 minutes), runs
  `ReconcileOrphanedPaymentAttempts`, and asserts the attempt reaches
  `Succeeded`/`Payment::Paid`/the wallet transaction `completed` —
  proving the *existing, unmodified* scheduler genuinely converges this
  case on its own, so `REMOTE_SUCCEEDED_AWAITING_REPLAY` can keep being
  documented as deterministic rather than merely plausible. If this test
  cannot be made to pass against the current codebase, §8/§14's
  classification for this category must be revisited before Phase 1 ships.
- **Episode lifecycle tests** — every row of §10's transition table.
- **Uniqueness/concurrency tests** — two workers racing to open an episode
  for the same reference; one racing to resolve while another observes a
  fresh mismatch; the `active_identity` NULL-on-resolve mechanism verified
  against real inserts (§9.1), on SQLite (this codebase's test DB) with an
  explicit note (mirroring `INVARIANTS.md`'s own Concurrency note) that this
  is a sequential simulation, not proof under true multi-connection
  parallelism.
- **Evidence-correctness tests (§13, post-implementation audit)** — a full
  matrix, per provider where technically representable, in
  `ProviderReconcilerTest`: confirmed absence → `RemoteMissing` (Stripe 404
  only); timeout/connection error/5xx (either provider) → `RetrievalFailed`,
  no finding; authentication failure, permission failure, a generic
  non-retryable 4xx that is *not* a confirmed-absence signal, and an
  ambiguous/unrecognized SDK exception → `RetrievalFailed`, never
  `RemoteMissing`; an EasyPay response shaped like "not found" (404) still
  never produces `RemoteMissing`, proving the fail-closed decision for that
  provider is real, not theoretical; an already-open finding is provably
  untouched (same category, same `observation_count`, same
  `last_observed_at`) across a subsequent retrieval failure; an already-open
  finding resolves normally once a later, authoritative retrieval agrees
  with local state.
- **Architecture tests**:
  - reconciliation code never writes `store_wallets`/`store_wallet_transactions`
    (sibling to `WalletLedgerSingleWriterTest`);
  - reconciliation code never sets `Payment.status`/`PaymentAttempt.status`/
    `Order.order_status_id` anywhere;
  - reconciliation code **never calls**
    `PaymentAttemptRecoveryService::recover()`,
    `PaymentService::finalizeAttempt()`, or `PaymentEventProcessor::apply()`
    at all — a direct, mechanical enforcement of §3/§5's conclusion, stronger
    than the previous draft's "only via existing methods" framing now that
    the answer is "never calls them";
  - reconciliation code never constructs a `PaymentProviderEvent` row.
- **Schema tests** — against the real migrated schema
  (`PRAGMA foreign_key_list`), mirroring `PaymentsSchemaDeletePolicyTest`.

---

## 18. Persistence schema (Phase 1)

### Proposed table: `payment_reconciliation_findings`

| Column | Type | Why |
|---|---|---|
| `id` | bigint PK | standard |
| `payment_attempt_id` | nullable, FK → `payment_attempts.id`, `restrictOnDelete()` | Nullable because Phase 1 is Direction-1-only and always starts from a known local attempt — in practice never `NULL` in Phase 1's own writes, but left nullable in the schema so Phase 2's Direction-2 orphans (no local attempt at all) don't need a breaking schema change later. `restrictOnDelete()` mirrors `payment_recovery_actions.payment_attempt_id` exactly (§2). |
| `provider` | `string(40)` | Matches `payment_attempts.provider` |
| `provider_reference` | `string` | Matches `payment_attempts.provider_reference` |
| `active_identity` | `string`, nullable, **`unique`** | §9.1's CAS mechanism — `"{provider}:{provider_reference}"` while open, `NULL` once resolved |
| `category` | `string(48)` | One of §8's finite values |
| `severity` | `string(20)` | Snapshotted at last observation, per §8 |
| `local_state` | `string(40)`, nullable | The local `PaymentAttemptStatus`/`PaymentStatus` observed |
| `remote_state` | `string(40)`, nullable | The provider's own raw status string, unmodified |
| `local_amount_minor_units` / `remote_amount_minor_units` | `bigint`, nullable | Integer minor units — never a float, matching `ProviderPaymentResult` |
| `local_currency` / `remote_currency` | `string(3)`, nullable | ISO codes as observed |
| `local_correlation_id` / `remote_correlation_id` | `string`, nullable | For `CORRELATION_MISMATCH` evidence |
| `status` | `string(20)` | `open` \| `resolved` only (§10) |
| `first_observed_at` | `timestamp` | Set once per episode, never updated |
| `last_observed_at` | `timestamp` | Updated on every re-observation |
| `observation_count` | `unsigned int`, default 1 | Per-episode, resets to 1 for a new episode |
| `resolved_at` | `timestamp`, nullable | Set only when `status = resolved` |
| `resolution_reason` | `string(40)`, nullable | **`no_longer_observed` only** — the single value Phase 1 code ever writes. No second value is defined or reserved (§10): a future corrective-action phase would need its own proven convergence trace (§3's method) before any such value could mean anything, and defining one now against an undesigned Phase 3 would itself be exactly the speculative schema this revision was asked to remove. Adding a value later is an additive, backward-compatible change to a `string` column — no migration risk is created by not reserving it now. |
| `acknowledged_at` | `timestamp`, nullable | Independent human-attention axis (§10) |
| `acknowledged_by` | nullable FK → `admins.id`, `nullOnDelete()` | An admin account being deleted later must never delete the fact that *someone* acknowledged this — mirrors `payment_recovery_actions.admin_id`'s own `nullOnDelete()` reasoning exactly |
| `evidence` | `json`, nullable | Small, bounded, already-sanitized supplementary fields only — never a raw provider payload or secret |
| `created_at` / `updated_at` | timestamps | standard |

**No `actionable` column** (§10) — Phase 1 has no concept for it to express.
**No `locked_until` column** (§11.1) — no correctness invariant depends on a
lease at the finding-row level; `unique(active_identity)` already owns the
one guarantee that matters, and duplicate provider GETs are financially
harmless. **No retention/pruning columns or config** (§19) — nothing is
ever pruned in Phase 1.

### Constraints / indexes

- `unique(active_identity)` — the entire CAS/dedup mechanism.
- `payment_attempt_id` FK: `restrictOnDelete()`.
- Index `(status, created_at)` — the only query pattern Phase 1 actually
  has ("open episodes, ordered by age"); no `(status, locked_until)` index,
  since that column no longer exists.
- Index `(provider, provider_reference)` (non-unique — historical episodes
  for the same reference are expected and must stay queryable together).

---

## 19. Retention (Phase 1: none)

**No pruning command, no retention config, no `--days` flag, in Phase 1.**
Per explicit instruction:

- `open` episodes: never pruned.
- `resolved` episodes: never pruned, in Phase 1.

This removes retention entirely as a Phase-1 blocker — no arbitrary
retention-days default needs to be chosen or defended now. A future,
separately reviewed retention-hardening branch may introduce pruning for
`resolved` episodes only, with its own audit of how long a resolved episode
stays operationally useful (the previous draft's unresolved "open question"
about a default retention window is **not** a question Phase 1 needs to
answer at all, since Phase 1 doesn't prune anything).

---

## 20. Migration safety

Unchanged: additive only, one new table, `restrictOnDelete()` on the
attempt FK (never `cascadeOnDelete()`), schema tests against the real
migrated schema (`PRAGMA foreign_key_list`).

---

## 21. RECON invariants (Phase 1 — status updated post-implementation)

Phase 1 is now implemented and tested (branch `feat/financial-reconciliation`).
Per the explicit instruction this update follows: **an invariant is marked
`ENFORCED` only where a named test mechanically proves it — never merely
because the implementing code exists.** Several remain `PROPOSED` below
precisely because no dedicated test covers them yet, even though the
current code likely satisfies them; closing that gap is listed as follow-up
work, not silently assumed.

| ID | Statement | Test | Status |
|---|---|---|---|
| RECON-01 | Reconciliation never creates a financial settlement path of any kind — Phase 1 performs zero automatic financial actions (§3, §14) | `tests/Architecture/ReconciliationNoFinancialMutationTest`, `ProviderReconcilerTest::'never mutates Payment, PaymentAttempt, Order, or Wallet state...'`, `ReconcilePaymentsAgainstProviderTest::'never calls the recovery/settlement path...'` | **ENFORCED** |
| RECON-02 | Reconciliation persistence is purely observational and cannot itself produce a financial effect | Same as RECON-01 | **ENFORCED** |
| RECON-03 | A finding/episode is tied to the exact `(provider, provider_reference)` identity `payment_attempts` itself uses — never `payment_id` alone, never "whichever attempt is current" | True by construction (`ProviderReconciler` operates on the concrete attempt passed to it, never `Payment.current_payment_attempt_id`) but no dedicated historical-vs-current-attempt regression test exists yet — see CROSS-10's own test for the shape such a test would take | PROPOSED |
| RECON-04 | Amount/currency/correlation mismatches are never auto-corrected in either direction | `ReconciliationClassifierTest` (Amount/Currency/CorrelationMismatch cases), plus RECON-01's no-mutation tests | **ENFORCED** |
| RECON-05 | A retrieval failure — retryable, or non-retryable without a provider-confirmed absence signal — is never classified as a financial mismatch category and is never persisted as a finding row, and never touches an already-open finding for that identity (§13, corrected post-implementation) | `ProviderReconcilerTest` items [B]–[I]: timeout/connection/5xx (retryable), authentication/permission/generic-4xx/ambiguous-SDK failure (non-retryable but not confirmed-absent), and the "never touches an already-open finding" test | **ENFORCED** |
| RECON-06 | A remote state corresponding to an unsupported local operation always fails closed to an open, non-actionable finding — never partially applied or silently dropped | `ReconciliationClassifierTest::'classifies EasyPay refunded as UnsupportedRemoteState...'`, `'...an unrecognized Stripe status...'` | **ENFORCED** |
| RECON-07 | No finding/episode, open or resolved, is pruned in Phase 1 (§19) | No pruning command/config exists at all in Phase 1 — vacuously true, but not exercised by a dedicated test | PROPOSED |
| RECON-08 | No provider HTTP call occurs inside a database transaction that holds a lock on an episode row | True by construction (`ProviderReconciler::reconcile()` calls `retrieveByReference()` before ever calling `ReconciliationFindingRepository`) but not independently proven by a test that would fail if this ordering regressed | PROPOSED |
| RECON-09 | At most one open (active) episode exists per `(provider, provider_reference)` identity at a time, enforced by `unique(active_identity)` (§9.1) — not merely by application convention | `ReconciliationFindingLifecycleTest::'never lets two open episodes exist simultaneously...'`, `'...converge on one row via unique(active_identity)...'`, `ReconciliationFindingSchemaDeletePolicyTest::'enforces uniqueness on active_identity at the database level'` | **ENFORCED** |
| RECON-10 | *(Reserved for a future phase that introduces an actual corrective action — not applicable to Phase 1, which has none.)* | N/A | N/A — DEFERRED |
| RECON-11 | Persisted findings contain no credentials, secrets, or raw unsanitized provider payloads | Phase 1 never writes to the `evidence` column at all — vacuously true, not exercised by a dedicated test | PROPOSED |
| RECON-12 | Repeated observation of the same open episode never creates a second row for the same identity, regardless of scheduler frequency | `ReconciliationFindingLifecycleTest::'updates the same open episode, not a new row...'` (both same-category and category-change variants) | **ENFORCED** |
| RECON-13 | Reconciliation may mark a finding actionable only when the current codebase already contains a canonical, idempotent operation proven — by an explicit, documented execution trace — to converge from that exact local state and evidence combination. In Phase 1, no category satisfies this. | `ReconciliationClassifierTest::'never returns an actionable-shaped category...'`, `ReconciliationExecutionTraceTest` (§3.A/§3.B/§3.D, encoding the trace this invariant depends on), `ReconciliationNoFinancialMutationTest` | **ENFORCED** |
| RECON-14 | Observation is never financial correction — persisting or updating a finding never itself constitutes, implies, or substitutes for a change to `Payment`/`PaymentAttempt`/Wallet state | Same as RECON-01 | **ENFORCED** |
| RECON-15 | Acknowledgement is never financial correction — `acknowledged_at`/`acknowledged_by` are independent of `status`/`resolved_at` and never change them (§10) | `ReconciliationFindingLifecycleTest::'acknowledgement never changes status or resolved_at...'` | **ENFORCED** |
| RECON-16 | Provider unavailability (retryable or not) is never payment failure — mirrors `CROSS-09` in reconciliation's own context (§13) | `ProviderReconcilerTest` items [B]–[H] (none ever produce `RemoteFailedLocalPending` or any other category) | **ENFORCED** |
| RECON-17 | Direction 2's absence or incompleteness (§1, Phase 2) never weakens the correctness of any Direction-1 finding — the two directions' findings are independently valid | Architectural/scope statement, not independently testable in isolation | PROPOSED |
| RECON-18 | No synthetic `ProviderEventOutcome` or `PaymentProviderEvent` row is ever constructed by reconciliation to simulate a webhook (§3, §5) | `tests/Architecture/ReconciliationNoFinancialMutationTest` (forbids `PaymentProviderEvent::create`), `ProviderReconcilerTest::'never mutates...'` (asserts `PaymentProviderEvent::count()` stays 0) | **ENFORCED** |
| RECON-19 | Exact historical attempt ownership is mandatory — a finding is never attributed to "whichever attempt is current" when a more specific, exact `(provider, provider_reference)` match exists, mirroring `CROSS-10` | Same caveat as RECON-03 — true by construction, no dedicated historical-attempt regression test yet | PROPOSED |
| RECON-20 | `RemoteMissing` may be emitted only when the resolved provider both implements `SupportsConfirmedResourceAbsence` and confirms absence for that exact exception — never inferred from `FailureClass::NonRetryable` alone, which also covers auth/permission/malformed-request failures that prove nothing about existence (§13) | `ProviderReconcilerTest` item [A] (Stripe 404 → `RemoteMissing`) and items [E]/[F]/[G]/[H] (every other non-retryable Stripe failure, plus every EasyPay failure including a 404-shaped one → `RetrievalFailed`, never `RemoteMissing`) | **ENFORCED** |

Follow-up (not a Phase 1 blocker, since every gap above is either vacuous
or covered indirectly): add a dedicated historical-vs-current-attempt test
(RECON-03/RECON-19, mirroring `PaymentEventProcessorExactAttemptSettlementTest`'s
own scenario shape) and a transaction-boundary test proving RECON-08's
ordering mechanically, the way `FAILURE-MODEL.md`'s crash-point tests do
for the existing domains.

---

## 22. Explicit non-goals (Phase 1)

- Any automatic financial corrective action, for any category, under any
  circumstance (§3, §14 — this is now unconditional, not "narrow").
- Direction 2 / `SupportsPeriodicExport`, for either provider.
- Researching or guessing at an EasyPay listing capability — Phase 2, if it
  happens, starts that research itself, explicitly.
- Any retention/pruning command or config.
- Payout reconciliation, at any phase, until a real automatic payout
  provider exists (§9.2).
- Any admin UI. The schema (§18) is shaped to answer an admin surface's
  likely questions later, but none is built now.
- Ledger reconciliation (`wallet:audit`'s job) and dispute handling (not a
  feature) — unchanged from the prior draft.

---

## Final review report

### A. Exact execution trace: Pending / provider-succeeded / no webhook

§3.A, in full above. Summary: `PaymentAttemptRecoveryService::recover()` →
`PaymentService::finalizeAttempt()` → `claimProviderReference()` advances
`Pending → Claimed` and creates a **pending** (uncompleted) Wallet
transaction via the provider's own idempotent `createOrGetPayment()`, then
`replayUnmatchedEvents()` finds zero stored events and does nothing further.
**Does not settle. Attempt remains `Claimed` indefinitely if no webhook ever
arrives.**

### B. Exact execution trace: Claimed / provider-succeeded / no webhook

§3.B, in full above. `PaymentService::finalizeAttempt()` skips
`claimProviderReference()` (already claimed) and `replayUnmatchedEvents()`
finds zero stored events. **Pure no-op. No provider call is even made.**

### C. Revised automatic-action matrix

§14 — nine categories, **zero automatically actionable**, each with an
evidenced, not assumed, reason.

### D. Revised finding identity/history model

§9 — bounded episodes (option C), identity = `(provider, provider_reference)`,
at-most-one-open-episode enforced portably via a nullable
`active_identity` unique column (§9.1), unlimited historical episodes
preserved per reference, no event-sourcing.

### E. Revised lifecycle

§10 — two independent axes: (1) episode status (`open`/`resolved`, system-only,
from observation) and (2) acknowledgement (`acknowledged_at`/`acknowledged_by`,
human-only, fully independent of status). There is no worker-ownership axis
and no `locked_until` field (§11.1) — no correctness invariant depends on a
lease at the finding-row level. There is no `needs_attention` status distinct
from `open`. Acknowledgement never implies resolution.

### F. Phase 1 exact scope

Direction 1 (local → provider), Stripe **and** EasyPay, via the existing
`SupportsCanonicalRetrieval` capability. Detection + episode persistence +
observability only. Zero automatic financial actions. No retention/pruning.
No admin UI.

### G. Deferred Phase 2 scope

Direction 2 (`SupportsPeriodicExport`), Stripe first; EasyPay's capability
explicitly unresearched and unconfirmed, not assumed either way.

### H. Remaining genuine open decisions

1. **The core missing capability `REMOTE_SUCCEEDED_NO_SETTLEMENT_PATH`
   exposes** — whether this codebase should ever gain a genuine
   "settle from a polled canonical result, not just a webhook" path — is
   explicitly **not decided here**. It is a separate, financially
   load-bearing design question (it would be a second way to reach
   `PaymentEventProcessor::applySucceeded()`-equivalent behavior) deserving
   its own document, review, and almost certainly its own hardening branch
   in the style of `harden/financial-architecture-contract`. Phase 1 only
   ever *surfaces* this gap; it does not attempt to close it.
2. Exit-code/threshold policy for the CLI (§15) — an implementation-time
   choice, not a correctness question.
3. Whether `payment_recovery_action_id` linkage is ever added to a finding
   — remains deferred; more clearly unnecessary now than before, since
   Phase 1 never triggers a recovery action from a finding at all.

### I. Exact Phase 1 implementation files

- `database/migrations/xxxx_create_payment_reconciliation_findings_table.php`
- `app/Domain/Payments/Models/ReconciliationFinding.php`
- `app/Domain/Payments/Enums/ReconciliationCategory.php`,
  `ReconciliationSeverity.php`, `ReconciliationStatus.php`,
  `ReconciliationResolutionReason.php`
- `app/Domain/Payments/Services/ProviderReconciler.php` (or similar naming
  — read-only classification + episode upsert only; **must not** depend on
  `PaymentAttemptRecoveryService`, `PaymentService`, or
  `PaymentEventProcessor` for anything beyond read-only model access)
- `app/Console/Commands/ReconcilePaymentsAgainstProvider.php` (naming
  placeholder)
- No change to `StripePaymentProvider`/`EasyPayPaymentProvider` — both
  already implement everything Phase 1 needs.

### J. Tests required before any production code (per the confirmed
instruction — write these first, watch them fail red, then implement)

- The §3.A/§3.B/§3.D regression tests (see §17) — these encode this
  document's central factual claims (including `REMOTE_SUCCEEDED_AWAITING_REPLAY`'s
  now-proven, not merely asserted, self-resolution via the existing
  scheduler) as executable proof, not prose.
- Every classification unit test in §8/§17.
- Every episode-lifecycle test in §10/§17.
- The three "reconciliation never calls X" architecture tests in §17.
- Concurrency/uniqueness tests for `active_identity` (§9.1, §17).
- Provider-unavailable tests proving no episode is touched (§13, §17).

---

**DESIGN SCORE: 9/10.** The safety boundary is no longer merely asserted —
it is proven by direct execution trace against the actual current
implementation, and the matrix that follows from it has zero speculative
rows. `REMOTE_SUCCEEDED_AWAITING_REPLAY`'s self-resolution claim is now
also a proven trace (§3.D) rather than a plausibility judgment, closing the
one piece of hand-waving the previous pass still had. The taxonomy, episode
model, and lifecycle are each independently justified against a concrete
threat scenario rather than general principle. The one point withheld: the
most consequential finding this design surfaces
(`REMOTE_SUCCEEDED_NO_SETTLEMENT_PATH` has no existing resolution path at
all) is correctly *not* solved here, but that also means Phase 1 ships
knowing it will accumulate permanently-open, high-severity findings with no
built-in path to zero — worth the reviewer's explicit sign-off that this is
an acceptable interim state, not a surprise discovered after implementation.

**PERSISTENCE DESIGN SCORE: 10/10.** The episode model with
`active_identity`-based CAS is a minimal, portable, precedented mechanism
that correctly separates "resource identity" from "incident identity"
without event-sourcing. Retention is fully removed as a Phase-1 concern.
The two points previously withheld are both resolved in this pass:
`resolution_reason` now defines exactly the one value Phase 1 code can
ever produce, with no speculative second value reserved against an
undesigned future phase; and `locked_until` is removed entirely, with
§11.1 demonstrating — not just asserting — that no correctness invariant in
this design depended on a lease at the finding-row level, and that the one
real cost of not having one (occasional duplicate, read-only, idempotent
provider GETs) is bounded by the same operational-only mechanism this
codebase already uses for its two existing reconciliation commands. Nothing
in this schema is speculative against work this document doesn't design.

**READY TO IMPLEMENT PHASE 1: YES.**

Every automatic action that could have depended on an assumed webhook/event
has been removed — §3's trace proves, rather than assumes, that Phase 1's
actual behavior (detect, classify, persist, log — never mutate) requires no
such convergence at all, because Phase 1 never attempts convergence. The
one open question that would matter for a "corrective action" phase (item
H.1) is explicitly out of Phase 1's scope, not a hidden dependency of it.
Phase 1 as designed here is read-only with respect to every existing
financial table and mutates only its own new one.

---

## Addendum — post-implementation evidence-correctness hardening

Filed after Phase 1 was implemented and initially approved above. A
targeted audit of provider-retrieval-failure semantics (§13) found that the
first implementation pass's `RemoteMissing` classification was evidenced
incorrectly: it treated `FailureClass::NonRetryable` — a broad bucket that
also covers authentication failure, permission failure, a malformed
request, a card error, and an idempotency conflict — as sufficient proof
that a provider resource doesn't exist. It isn't. Reading
`EasyPayRequestException`'s own docblock directly confirms this codebase
has never distinguished "not found" from "forbidden"/"unprocessable" for
EasyPay at all.

**Fix**: a new, optional, provider-owned capability,
`App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence::isConfirmedAbsent()`,
mirroring `SupportsCanonicalRetrieval`'s own optionality. `StripePaymentProvider`
implements it precisely (`getHttpStatus() === 404`, Stripe's own REST
convention, already relied on elsewhere in this codebase).
`EasyPayPaymentProvider` deliberately does not implement it — no verified
evidence exists for EasyPay's absence semantics, so EasyPay retrieval
failures can never produce `RemoteMissing` in Phase 1, only the (renamed)
operational `RetrievalFailed` outcome. This is Option 3 of the audit
("fail closed") applied specifically and only where the evidence required
it — Stripe's precise signal was not discarded along with it.

This was a genuine defect caught before merge, not a hypothetical: the
original implementation would have persisted a durable, High-severity
`RemoteMissing` finding from, for example, a misconfigured Stripe API key
(401) or a permission error (403) — neither of which says anything about
whether the payment exists. See §14/§21's updated rows (`RECON-05`,
`RECON-16`, new `RECON-20`) and `ProviderReconcilerTest`'s 18-case matrix
for the corrected, tested contract.

**EVIDENCE CORRECTNESS SCORE: 10/10** — the corrected contract is precise,
provider-neutral at the domain layer (no Stripe/EasyPay-specific knowledge
leaked past the adapter boundary), and every branch of the corrected logic
(confirmed-absent, retryable, non-retryable-but-inconclusive, an
already-open finding surviving a subsequent failure) has a named test that
would fail if the contract regressed.

**READY TO MERGE: YES**, contingent on the validation run in the
implementation session's own final report (targeted tests, full suite,
Architecture suite, Pint, `git diff --check`) all passing — see that
report for the actual numbers, not restated here to avoid this document
drifting out of sync with a run it didn't itself execute.
