# Failure / Crash Model — shoprdam

## Crash point matrix

Payments and Payouts share the same shape (durable row → provider call →
CAS claim → terminal outcome), so this matrix applies to both except where
noted. "Domain lock/CAS" refers to the specific mechanism; "test" cites the
test file that exercises the exact scenario, where one exists.

| # | Crash point | Durable state before crash | Recovery owner | Protection | Duplicate-effect risk | Lost-effect risk | Test |
|---|---|---|---|---|---|---|---|
| 1 | Before creating the attempt | None | Caller simply retries the top-level request | Payment: `payments.order_id` unique; Payout: `unique(store_id, idempotency_key)` + `insertOrIgnore` | None — nothing exists yet | None | `PayoutServiceRequestTest` |
| 2 | After the durable attempt row, before the provider call | Attempt row `Pending` | Scheduled reconciliation or admin retry | Deterministic idempotency_key (`payment-{id}-attempt-{id}` / `payout-{id}-attempt-{id}`) | None — provider never called yet | None — row exists, will be picked up | `ReconcileOrphanedPaymentAttemptsTest`, `ReconcileOrphanedPayoutAttemptsTest` |
| 3 | Provider executes, response is lost | Attempt row `Pending`, provider may have created a remote resource | Reconciliation retries under the *same* idempotency key | Contract requirement: provider adapter must be idempotent per key (`createOrGetPayment`/`createTransfer`) | Depends on provider honoring its own idempotency contract — not verifiable from this codebase alone | None if the provider is honestly idempotent | Documented in `PaymentProviderContract`/`PayoutProviderContract` docblocks; not independently tested against a real provider |
| 4 | Provider times out | Attempt row `Pending`/`Claimed` | Recovery service | **Timeout is never treated as failure** — see §"Timeout semantics" below | None | None — stays retryable/NeedsAttention, never silently abandoned | `PayoutAttemptRecoveryServiceTest`-equivalent coverage in `ReconcileOrphanedPayoutAttemptsTest` |
| 5 | Provider responds, process crashes before local settlement | Provider has a remote resource; nothing local yet beyond the `Pending` row | Reconciliation re-calls the provider (idempotent) | Same idempotency-key contract as #3 | Same caveat as #3 | Recoverable as long as the provider is idempotent | — |
| 6 | Local claim commits, but the provider-event/replay step doesn't (Payments only) | `PaymentAttempt.provider_reference` set; `payment_provider_events` may have a stuck `pending` row | `ReconcileOrphanedPaymentAttempts`'s second candidate query (attempts with a claim + a stale pending event) | `PaymentEventProcessor::replayUnmatchedEvents()` is idempotent, safe to re-run | None — replay is a no-op once nothing pending remains | None | `ReconcileOrphanedPaymentAttemptsTest` |
| 7 | Duplicate webhook delivery | Event already applied | The processor itself | `unique(provider, provider_event_id)` on `payment_provider_events`; CAS status checks on attempts (`whereIn('status', [...non-terminal...])`) | Prevented — second delivery is a no-op | None | `PaymentEventProcessorExactAttemptSettlementTest` (Payments), `PayoutEventProcessorTest` "duplicate succeeded delivery" (Payouts) |
| 8 | Webhook out of order (e.g. refund before settlement confirms) | — | `payment_provider_events` queues it as `pending`, replayed once the precondition is met | `applySucceeded()` triggers a nested replay excluding its own event type (avoids infinite recursion) | None | None while the attempt eventually settles; **indefinite** `pending` if it never does (documented in `PrunePaymentProviderEvents`'s own docblock) | `PaymentEventProcessorExactAttemptSettlementTest` |
| 9 | Historical event arrives after a new attempt exists | Old attempt superseded, new one current | The processor resolves by `(provider, provider_reference)`, never by `current_*_attempt_id` | This is CROSS-10, tested explicitly | Prevented by construction | None | `PaymentEventProcessorExactAttemptSettlementTest` (Payments' "late failed event" test), `PayoutEventProcessorTest` "EXACT CURRENT ATTEMPT ISOLATION" (Payouts) |
| 10 | Two workers race to recover the same attempt | Lease column `locked_until` | Whichever wins the conditional `UPDATE` | CAS — `UPDATE ... WHERE status='pending' AND (locked_until IS NULL OR locked_until<=now())` | Prevented — only one UPDATE can match | None | `ReconcileOrphanedPayoutAttemptsTest` "never calls the provider for an attempt whose lease is still held" |
| 11 | Admin and scheduled worker race | Same lease mechanism | Same CAS as #10 — admin retry and scheduled reconciliation are *the same code path* | `PaymentAttemptRecoveryService`/`PayoutAttemptRecoveryService`, shared by both callers | Prevented — same mechanism as #10 | None | `PayoutRecoveryControllerTest` "retry" tests |
| 12 | DB commit fails mid-transaction | Whatever committed before the failing statement | Automatic rollback (Laravel `DB::transaction()`) | Standard ACID — no bespoke handling needed or present | None — atomic | None — atomic | Not independently tested (relies on the DB engine's own guarantee) |
| 13 | Duplicate idempotency key submitted | Existing row | Returns the existing row/replays | `unique(store_id, idempotency_key)` (Payout), `unique(provider, idempotency_key)` (PaymentAttempt/PayoutAttempt) | Prevented | None | `PayoutServiceRequestTest`, `PaymentAttemptIdempotencyKeyUniquenessTest` |
| 14 | Duplicate provider reference | Rejected at the DB layer | `QueryException` surfaces to the caller (Payments) / `ExternalTransferReferenceAlreadyUsedException` (Payouts, translated from the same DB error) | `unique(provider, provider_reference)` / `unique(provider, external_transfer_reference)` | Prevented | N/A | `PayoutEventProcessorTest` "rejects a real bank reference already used" |
| 15 | Malformed provider event | Never queued/applied | Translator maps unrecognized shapes to `Unrecognized`/`Informational` | Fail-closed by classification, not by exception | None | Silently ignored by design (logged) | Translator-level tests |
| 16 | Wrong amount | Rejected before any mutation | `assertResultMatchesAttempt()` (Payments) / `assertOutcomeMatchesPayout()` (Payouts) compare against the Payment/Payout's own stored values | Application-level check, no DB constraint (amounts aren't FK-checkable) | Prevented — throws `PaymentAttemptMismatchException`/`PayoutAttemptMismatchException` before any write | None | `PayoutEventProcessorTest` "fails closed when a succeeded outcome does not match" |
| 17 | Wrong currency | Same mechanism as #16 | Same | Same | Same | Same | Same |
| 18 | Wrong correlation/reference | Same mechanism as #16 (`correlationId` check) | Same | Same | Same | Same | Same |

## Timeout semantics (explicit, verified)

**HTTP exception/timeout is never proof of financial failure, and never
triggers an automatic reversal.** Verified by reading every `catch` block in
`PaymentAttemptRecoveryService::recover()` and
`PayoutAttemptRecoveryService::recover()`: both only ever produce
`RecoveryOutcome::NeedsAttention` (non-retryable or exhausted) or
`RecoveryOutcome::RetryPending` (retryable) on exception — **neither ever
calls the domain's own `applyFailed()`/`abandon()`**. The only path to a
terminal negative state is real evidence flowing through the Event
Processor. This holds for both domains; CROSS-09 formalizes it.

## Critical finding — financial history is cascade-deletable through a live, unguarded path

**Status: RESOLVED by `harden/financial-history-cascade-protection`.** This
section is kept as a historical record of what was found and how — do not
read the reproduction below as still live; see "Resolution" at the end for
what closed it, and CROSS-14 in `INVARIANTS.md` (now **ENFORCED**) for the
current guarantee.

Discovered during an earlier branch's forensic audit (Phase 1 of that
branch's own design record), not introduced by it, and deliberately not
fixed in that branch, per its own scope rules — the paragraph below is
preserved as it was written then.

**Reproduction (as it stood before the fix below):**
1. A vendor (any `User` with `userType` `vendor`, owning a `Store`) visits
   their profile settings and submits the standard "delete my account" form
   (`Vendor/Profile/Edit`, wired to `profile.destroy`).
2. `App\Http\Controllers\User\ProfileController::destroy()` ran
   `$user->delete()` — a real, hard `DELETE` (`App\Models\User` has no
   `SoftDeletes`) — with no check beforehand.
3. `stores.user_id` was `cascadeOnDelete()` (`database/migrations/2026_07_02_063758_create_stores_table.php`)
   → the `Store` row would be hard-deleted at the database level,
   **bypassing** `Store`'s own `SoftDeletes` trait entirely (a DB-level FK
   cascade never goes through Eloquent model events).
4. `store_wallets.store_id` was `cascadeOnDelete()`
   (`database/migrations/2026_07_02_063759_create_store_wallets_table.php`)
   → every `StoreWallet` the store owned would be hard-deleted.
5. `store_wallet_transactions.store_wallet_id` was `cascadeOnDelete()`
   (`database/migrations/2026_07_02_065351_create_store_wallet_transactions_table.php`)
   → **every ledger row that store ever had — every sale, refund,
   commission, withdrawal, reversal — would be permanently destroyed.**
6. `orders.store_id` was also `cascadeOnDelete()`
   (`database/migrations/...create_orders_table.php`), and `payments.order_id`
   / `payment_attempts.payment_id` were also `cascadeOnDelete()` — so the
   Payments-side audit trail would be destroyed too, for any attempt that
   never happened to accumulate a `payment_recovery_actions` row (which
   alone `restrictOnDelete()`d).

**The one thing that stopped this before the fix** was `payouts.store_id`,
which was already `restrictOnDelete()`. If the store had *any* Payout
history, step 3 would fail outright with an unhandled `QueryException` —
meaning the account-deletion feature would **crash** for any vendor who
ever requested a payout, instead of silently destroying history. Neither
outcome was acceptable: silent data loss for stores with no payout history,
or an unhandled 500 for stores with any.

**No guard existed.** `StoreObserver` only implements `creating`/`created`;
there was no `deleting` hook, no balance check, no confirmation step beyond
re-entering the account password.

**Impact (as it stood):** total, irrecoverable loss of a store's financial
ledger and payment history via a completely ordinary, already-shipped
self-service action. Classified as the shoprdam equivalent of "known path
to silent financial history deletion" — one of the explicit red-flag
categories the discovering branch was asked to watch for.

**Invariant violated (as it stood):** CROSS-14 ("Financial history is
append-only and cannot disappear via operational cascade") — true for
Payouts, false for Payments/Wallet.

## Resolution

`harden/financial-history-cascade-protection` closed this gap with two
independent layers, deliberately not just one:

1. **Database-level (the actual guarantee):**
   `database/migrations/2026_09_16_180000_restrict_financial_history_cascades.php`
   changes `payments.order_id`, `payment_attempts.payment_id`,
   `orders.store_id`, `store_wallets.store_id`,
   `store_wallet_transactions.store_wallet_id`, and `stores.user_id` to
   `restrictOnDelete()` — mirroring the pattern already proven in Payouts.
   This alone makes the cascade in steps 3–6 above impossible: the database
   now refuses any of those deletes outright while financial-history
   children still exist, the same way `payouts.store_id` already did.
   Proven against the real migrated schema (`PRAGMA foreign_key_list`) by
   `tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest`, and
   proven safe to run against a database already holding real financial
   history (existing rows survive, `down()`/`up()` genuinely flip the live
   FK policy both ways) by
   `tests/Feature/Domain/Payments/FinancialHistoryCascadeMigrationSafetyTest`.

2. **Application-level (the deliberate product decision the earlier branch
   left open):** `App\Http\Controllers\User\ProfileController::destroy()`
   now refuses to delete a User who owns any Store — including a
   soft-deleted one — *before* calling `$user->delete()` at all, returning
   a normal validation-error redirect ("Your account cannot be deleted
   while it owns a store. Please contact support.") instead of ever
   reaching the database constraint. This means the DB-level fix above is a
   genuine defense-in-depth backstop, not the only thing standing between a
   vendor and either a 500 or a blocked action — the product chose **block,
   always**, not soft-delete/anonymize (see `INVARIANTS.md`'s "Known
   Non-Guarantees" for what that trade-off still doesn't offer). Proven for
   a plain customer (unaffected), an empty Store, a soft-deleted-only
   Store, and Stores with Wallet/ledger, Payment, Payout, and mixed
   financial history, by
   `tests/Feature/ProfileAccountDeletionFinancialHistoryTest` (scenarios
   A1–A7).

**What rolling this back would reopen:** running this migration's `down()`
sets all six FKs back to `cascadeOnDelete()` — schema-only, it does not
touch any existing row — but doing so genuinely restores the exact
vulnerability reproduced above for any deletion performed afterward,
regardless of the application-level guard still being in place (a
deployment that rolled back the migration but kept the old
`ProfileController` code, or any other future code path that calls
`$user->delete()`/`$store->delete()` directly, would be exposed again).
`FinancialHistoryCascadeMigrationSafetyTest` proves the round-trip is
*data*-safe; it is not a claim that running with `down()` applied is an
*operationally* safe state.

## Other known gaps in the failure model

- **No payout-attempt health command.** `payments:health` has no Payout
  equivalent — a `NeedsAttention` or stale `PayoutAttempt` is only visible
  via direct DB inspection or the admin recovery controller's own eligible
  set, not a dashboard-style report.
- **No unmatched-event inbox for Payouts.** `PayoutEventProcessor` has no
  `payout_provider_events` equivalent to `payment_provider_events` — by
  design, since `ManualPayoutProvider`'s confirmation always targets an
  already-known attempt (see `PayoutEventProcessor`'s own docblock). This
  becomes a real gap the moment a webhook-driven automatic payout provider
  is added; not a problem for the `ManualPayoutProvider`-only state today.
- **Provider-idempotency contract is not independently verified.** Crash
  points #3 and #5 above depend on Stripe/EasyPay/a future provider actually
  being idempotent under a repeated key — this codebase enforces its own
  side of that contract (always sending the same key) but cannot prove the
  provider's side from unit/feature tests alone.
