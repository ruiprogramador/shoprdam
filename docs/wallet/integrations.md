# Integrating a payment provider

This is written from the first real integration (Stripe — see
`App\Payments\Stripe`), as agreed: no `PaymentGateway` interface exists yet.
One should only be extracted once a second provider needs the same shape,
so the interface reflects two real implementations instead of a guess.

## Known production limitations — read before building on top of this

These are deliberate, tested, documented boundaries of the current
integration — not oversights — but each one is a real constraint on what
you can safely build on top of this code without redesigning part of it
first:

- **No partial refunds.** Stripe's `charge.refunded` fires on partial
  refunds too, but this integration only reverses a transaction once the
  Charge is *fully* refunded (see the event mapping below and
  [Retries and replays](#retries-and-replays)). A `charge.amount_refunded`
  of €20 on a €100 sale is silently ignored — the Wallet still shows the
  full €100 as reversible/settled. **Do not add a partial-refund button, a
  partial-refund API endpoint, or any other way to trigger a Stripe partial
  refund** until the Wallet's reversal accounting is redesigned to track
  refunds individually (own reference per refund, cumulative-not-binary
  comparison) instead of "all or nothing."
- **2-decimal currencies only.** `StripeAmount::toMinorUnits()` always
  multiplies by 100. The `currencies` table already has a real `precision`
  column, but nothing here (or in the Wallet's `decimal(_, 2)` schema)
  honors it. **Do not enable a zero-decimal currency (e.g. JPY) or a
  3-decimal one (e.g. BHD) for Stripe payments** without fixing this at the
  schema level first — silently doing so would over- or under-charge by a
  factor of the currency's minor-unit base.
- **An orphaned Stripe PaymentIntent recovers within `--stale-after` minutes
  (default 5), not instantly.** If Stripe successfully creates a
  PaymentIntent but the following `record()` call fails or the process
  dies, `App\Console\Commands\ReconcileOrphanedPaymentIntents` (scheduled
  every 5 minutes, see `routes/console.php`) picks it up — see
  [Recovering orphaned PaymentIntents](#recovering-orphaned-paymentintents-the-order_payment_intent_attempts-outbox).
  There is still a window before that runs (and, separately, before a run
  succeeds) where the gap described in
  [External non-atomicity](#external-non-atomicity-stripe--the-database)
  is real — this doesn't make the two operations atomic, it bounds how long
  the inconsistency can persist and automates closing it.
- **An Order gets at most one PaymentIntent, ever.** `order_payment_intents`
  has a unique constraint on `order_id`, and
  `StripePaymentService::createPaymentIntentForOrder()` is idempotent per
  Order: calling it twice (a plain repeat call, or a genuine race between two
  requests) returns the same PaymentIntent instead of creating a second one
  and a second pending Wallet transaction. There is currently no supported
  way to retry a failed/canceled Order's payment with a *new* PaymentIntent
  — that would need an explicit product decision (new Order vs. releasing
  the existing claim) before being built.

## The Wallet never sees provider vocabulary

`WalletTransactionService` has no knowledge of Stripe, PaymentIntents,
Charges, or webhook event types. `App\Payments\Stripe\StripeEventDispatcher`
is the only class responsible for translating *incoming* Stripe webhook
events into Wallet operations — that's the direction where Stripe's
vocabulary must be turned into the Wallet's.

`StripePaymentService` is the *outbound* counterpart: it creates the Stripe
PaymentIntent and records the matching pending Wallet transaction, both in
the same request that starts a payment. It does talk to both Stripe and the
Wallet, but only ever in that one fixed direction (create intent, then
record) — it never interprets a Stripe event, which is what keeps the
webhook-translation responsibility concentrated in the dispatcher.
`StripeWebhookController` only verifies the webhook signature and hands the
event to the dispatcher; it talks to Stripe's SDK but never to the Wallet.

## Event -> operation mapping (Stripe)

| Stripe event | Wallet operation | Notes |
|---|---|---|
| PaymentIntent created (app-initiated, not a webhook) | `record(status: pending)` | Done synchronously in `StripePaymentService::createPaymentIntentForOrder()`, in the same request that creates the PaymentIntent — not in response to a webhook. |
| `payment_intent.succeeded` | `confirm()` | No-ops (via caught `TransactionNotPendingException`) if the transaction is already `completed` — safe against Stripe's at-least-once webhook delivery. |
| `payment_intent.payment_failed` | **none — informational only** | Stripe fires this when a single payment *attempt* fails; the PaymentIntent itself is not done and typically reverts to `requires_payment_method`, so the customer can retry with a different payment method and still reach `payment_intent.succeeded`. The handler only logs (id, status, error message) and never touches the Wallet or the Order. |
| `payment_intent.canceled` | `markFailed()` | Stripe fires this as its own, separate event when the PaymentIntent is actually done (explicitly canceled, or auto-canceled after too many failed attempts / expiry) — this, not `payment_intent.payment_failed`, is what terminally fails the transaction. Matches the Wallet's `failed` status being terminal (see [lifecycle.md](lifecycle.md)): we only ever call `markFailed()` from an event Stripe itself treats as final. Same no-op safety as the other handlers for duplicate deliveries. |
| `charge.refunded` | `reverse()` | No-ops (via caught `TransactionAlreadyReversedException`) on duplicate delivery. **Only reconciles a fully-refunded Charge.** `charge.amount_refunded` is the Charge's *cumulative* refunded total, not this event's delta, so the dispatcher compares it against the original transaction's full amount and only calls `reverse()` (for the full amount) once they match. Any `charge.refunded` event for a Charge that isn't fully refunded yet — including an intermediate event in a series of partial refunds — is logged and ignored; partial reversals are not accounted for at all. |

Both places that convert a Wallet decimal amount into Stripe's minor-unit
integer (`record()`'s amount here, and the refund comparison below) go
through the single `StripeAmount::toMinorUnits()` helper rather than each
inlining `* 100` — see that class for the 2-decimal-currency assumption it
still makes.

An earlier version of this integration made the mistake of trying to detect
"the PaymentIntent is really done" by checking `status === 'canceled'` on
the PaymentIntent embedded in a `payment_intent.payment_failed` event. That
never actually happens in practice — a failed attempt's status is
`requires_payment_method`, not `canceled` — so that check was silently dead
code: `markFailed()` was, for all practical purposes, never reachable, and
duplicate cancellation testing accidentally tested a payload Stripe never
sends. `payment_intent.canceled` is what carries that signal for real.

## One PaymentIntent per Order

`WalletTransactionService::record()`'s own idempotency (see below) is keyed
by `(external_provider, external_reference)`, i.e. by *PaymentIntent id* —
it has no way to know two different PaymentIntent ids belong to the same
Order. Without a separate guard, calling
`createPaymentIntentForOrder($order)` twice for the same Order (a plain
repeat call, or two concurrent requests) would create two real Stripe
PaymentIntents and two independent pending "sale" Wallet transactions for
one Order — both could later be confirmed, double-crediting the Wallet.

Two mechanisms close this, at different layers:

- **Stripe-side:** the PaymentIntent is created with a deterministic
  `idempotency_key` derived from the Order id
  (`order-{$order->id}-payment-intent`). Two calls with the same key and the
  same params (always true here — the params are only ever derived from the
  Order's own persisted `amount`/`currency`) return the same PaymentIntent
  object rather than creating a second one.
- **Local:** `order_payment_intents` has a unique constraint on `order_id`
  (and on `payment_intent_id`, so the 1:1 relationship holds in both
  directions). `createPaymentIntentForOrder()` writes the
  `(order_id, payment_intent_id)` claim row and calls `record()` inside the
  same `DB::transaction()`. This is the layer that actually decides whether
  a second Wallet transaction gets created — it holds even in the
  hypothetical case where the Stripe-side idempotency key doesn't (e.g. a
  bug in how it's sent), which is why it isn't treated as redundant with the
  key above.

The claim row, not whatever a given call's own Stripe response happens to
be, is the source of truth for which PaymentIntent an Order actually has a
Wallet transaction for. On a duplicate-key violation (this call lost the
race, or it's a plain repeat call), `createPaymentIntentForOrder()` reads
the already-claimed `payment_intent_id` back and, only if it differs from
this call's own PaymentIntent id, fetches that one from Stripe instead —
so the method never hands back a PaymentIntent the Wallet has no record of,
even in the (practically unreachable, given the idempotency key) case where
Stripe assigned two different ids for the same Order.

That recovery query runs **after** `DB::transaction()` has returned control
via a caught `QueryException`, never inside a `catch` nested in the
transaction closure. `DB::transaction()` always calls `rollBack()` before
rethrowing (`Illuminate\Database\Concerns\ManagesTransactions`), so by the
time the recovery query runs, the transaction is already closed on any
engine — including ones (PostgreSQL) that refuse to run further statements
in a transaction that already failed one, unlike MySQL/SQLite, which
tolerate it. This also means the recovery path doesn't need to match a
specific SQLSTATE for "unique violation" (MySQL and SQLite both report
`23000`; PostgreSQL reports the more specific `23505`) — it only asks
whether a claim exists for this Order, which is true regardless of engine.

Wrapping the claim-row insert and `record()` in one transaction also means a
`record()` failure rolls the claim row back too, instead of permanently
locking the Order out of a working retry — a later call just hits Stripe
again (same idempotency key, same PaymentIntent) and tries once more.

## The idempotency reference

The initial Stripe-originated Wallet transaction is anchored by a
`WalletTransactionReference('stripe', <id>)` — `confirm()` and `markFailed()`
don't take a new reference themselves, they operate on the transaction that
reference already identified:

- The initial `record()` call uses the **PaymentIntent id** — this is what
  ties every later webhook back to the same transaction.
- `reverse()` uses the **Charge id** (`$charge->id`) — a different reference
  than the original, since it identifies the reversal transaction itself,
  not the original sale. Stripe's separate refund object id is never used
  here.

## Linking back to application data without the Wallet knowing about it

The Wallet's `referenceable` morph column (already used for e.g. linking a
transaction to a `Store`) is reused to link a sale transaction to whatever
business record justified it — currently a minimal `Order` model. Given
that link, `StripeEventDispatcher` never needs to trust or parse
`metadata` coming back from Stripe: it looks up the transaction by
`(external_provider, external_reference)`, then reads `$transaction->referenceable`
to find the `Order` to update. Metadata is still sent to Stripe (useful for
the Stripe Dashboard and manual debugging) but the dispatcher's own
correctness never depends on it.

The lookup (`external_provider`, `external_reference`) is further scoped to
`whereNull('related_transaction_id')`, i.e. it only ever matches an original
sale transaction, never a reversal. This assumes one PaymentIntent maps to
exactly one original transaction — true today because `record()` is called
once per PaymentIntent in `StripePaymentService::createPaymentIntentForOrder()`,
and structurally enforced by the `external_ref_idx` unique index on
`(external_provider, external_reference)` in the `store_wallet_transactions`
table: two "sale" rows can never share the same `(stripe, <PaymentIntent id>)`
pair, so `findOriginalTransactionByPaymentIntentId()`'s `->first()` can never
silently pick one of several matches. A second adapter must preserve this
1:1 mapping, or `findOriginalTransactionByPaymentIntentId` needs to be
revisited.

## Retries and replays

A provider integration must be safe to re-invoke with the same event
without side effects beyond the first call — including on the application
side, not just the Wallet. This is why every dispatcher handler wraps its
Wallet call in a catch for the specific "already in that state" exception
**and returns immediately** rather than falling through to `markOrder()`:
the check and the mutation happen atomically inside
`WalletTransactionService`, so there is no read-then-write race between two
concurrent webhook deliveries, and a no-op Wallet call must also mean a
no-op `Order::update()` (no timestamp bump, no re-fired model events).

The Wallet mutation and `markOrder()` are wrapped together in one
`DB::transaction()` per handler (they're on the same connection, so this is
possible, unlike the Stripe-API case below). This closes what used to be a
known gap: without it, a `markOrder()` failure *after* a successful
`confirm()`/`markFailed()`/`reverse()` would leave the Wallet transaction
settled with the Order stuck out of sync, and a replayed webhook could never
repair it — the Wallet call would just hit its "already in that state"
exception and return before ever reaching `markOrder()` again. With the
wrap, that failure rolls back the Wallet mutation too, so the transaction
stays `pending`/reversible and a later retry (of the same event, or the
underlying process) can still succeed cleanly
(`StripeWebhookControllerTest`: *"rolls back the Wallet confirmation when
marking the order paid fails, keeping the pair atomic"*).
`WalletTransactionService`'s own methods open their own `DB::transaction()`
internally; nesting inside this outer one is safe and just uses a
savepoint.

## External non-atomicity: Stripe + the database

`StripePaymentService::createPaymentIntentForOrder()` calls the Stripe API
and then writes to the database; these cannot be one atomic operation
because Stripe's HTTP API is outside our database transaction. If the
PaymentIntent is created on Stripe but the following `record()` call fails
(or the process dies in between), Stripe has a PaymentIntent with no local
Wallet transaction pointing at it, and no webhook will ever resolve it,
since the dispatcher only acts on transactions it can already find by
reference. This integration accepts that gap rather than pretending a DB
transaction could close it — see `App\Console\Commands\CreateTestStripeOrder`
for the same tradeoff made explicit in a real caller (it deletes its local
Order on failure but says so plainly: Stripe's side may still need manual
cleanup). What it doesn't accept is leaving that gap permanently
unreconciled — see the next section.

## Recovering orphaned PaymentIntents: the `order_payment_intent_attempts` outbox

A DB transaction can't make the Stripe call and the local write atomic, but
it can make the *fact that Stripe was called* durable, independently of
whether the rest of the operation succeeds. That's what
`order_payment_intent_attempts` is: a row written in its own fast commit,
*before* `createPaymentIntentForOrder()` calls Stripe, carrying the Order id
and the deterministic idempotency key. This is a standard outbox pattern,
scoped to exactly the one gap described above.

The row starts `pending` and is flipped to `claimed` inside the same
transaction that writes the `order_payment_intents` claim and calls
`record()` — so a `pending` row still lingering after that transaction
either committed or rolled back means precisely one thing: Stripe was asked
for a PaymentIntent, and this process never found out (or never got the
chance to record) what happened next.

`App\Console\Commands\ReconcileOrphanedPaymentIntents` finds `pending`
attempts older than `--stale-after` minutes (default 5, scheduled every 5
minutes in `routes/console.php`) and recovers each by calling
`createPaymentIntentForOrder()` again — nothing more specialized than that.
Because the idempotency key is deterministic and the params it's built from
never change after the Order is created, Stripe either returns the original
PaymentIntent (the common case: it already existed) or, if the original
request never actually reached Stripe, creates it now — both are the
correct outcome of finally completing the original attempt. The existing
claim logic in `createPaymentIntentForOrder()` (unique `order_id` on
`order_payment_intents`) already makes repeat calls safe, so reconciliation
adds no new duplication risk.

**Recovery only ever rebuilds pending local state — it never settles a
payment.** `createPaymentIntentForOrder()` only ever calls `record()` with
`status: pending`; it never calls `confirm()`. Turning a `pending` Wallet
transaction into `completed` (and the Order into `paid`) stays the
exclusive job of `StripeEventDispatcher::handlePaymentIntentSucceeded()`,
triggered only by Stripe's own webhook telling us the PaymentIntent
actually succeeded. The reconciliation job answers "does this Order's
payment attempt have a local representation yet?", never "did this payment
succeed?" — those are deliberately different questions, and only Stripe's
webhook is allowed to answer the second one.

On repeated failure (Stripe still unreachable, or failing for some other
reason), the command tracks `recovery_attempts` and `last_recovery_error`
on the attempt row and, after `--max-attempts` (default 5), marks it
`needs_attention` and stops retrying it automatically — logged via
`Log::error` with the Order id and idempotency key, for whatever alerting
is wired to the log channel. A `needs_attention` attempt is excluded from
future runs; picking it back up (e.g. after a manual check that nothing
harmful happened on Stripe's side) requires deliberately resetting its
`status` back to `pending`.

**`--max-age` bounds retries by absolute time, independently of
`--max-attempts`.** Every retry re-issues the same request under the same
deterministic idempotency key, which only guarantees Stripe returns the
original PaymentIntent while that key is still recognized — Stripe may
remove a key after it has been around for at least 24 hours, and reusing
it afterward risks being processed as a new request instead of returning
the original PaymentIntent. `--max-age` (default 720 minutes, i.e. 12h —
comfortably inside that window) is checked *before* the retry logic, per
attempt, on every run: an attempt older than `--max-age` goes straight to
`needs_attention`, no Stripe call made, regardless of how many
`recovery_attempts` it has left. This means raising `--max-attempts` alone
(e.g. to ride out a longer Stripe outage) can never, by itself, push a
retry past the safe recovery window.

The scheduled entry in `routes/console.php` also applies
`->withoutOverlapping()->onOneServer()`, so a run that takes longer than
five minutes (or a deploy across multiple app servers) can't have two
processes reconciling the same attempts concurrently. This isn't load-
bearing for correctness — the unique constraints on `order_payment_intents`
and the deterministic idempotency key already make two concurrent calls to
`createPaymentIntentForOrder()` for the same Order safe (see [One
PaymentIntent per Order](#one-paymentintent-per-order)) — but it avoids
relying on that as the only thing standing between normal operation and
two processes racing over the same rows.

The duplicate-claim protection itself is enforced at the database engine
level, not by the scheduler: the unique `order_id` constraint on
`order_payment_intents` is the authority that decides which of two
concurrent `createPaymentIntentForOrder()` calls wins, and the losing call
reads the winner's claim back after its own insert rolls back (see [One
PaymentIntent per Order](#one-paymentintent-per-order)). This holds
regardless of whether the two calls came from one worker retrying, two
schedulers that both slipped past `onOneServer()`, or any other source —
the constraint doesn't know or care where a call came from.
`ReconcileOrphanedPaymentIntentsTest`'s *"returns the existing claim, not a
duplicate, when two sequential recovery calls target the same orphaned
attempt"* exercises exactly this decision point (two calls contending for
the same claim, one necessarily losing) but does so with two sequential
calls on one connection, not two genuinely concurrent ones — the test
suite runs against SQLite `:memory:` (a separate database per process,
with no `busy_timeout` configured), which can't host two real OS
processes writing to the same database, and even a file-based SQLite
database would only exercise SQLite's whole-database write lock
(`database is locked`) rather than the row-level unique-constraint
violation that MySQL/PostgreSQL raise in production. A genuine concurrency
test for this would need to run against the production database engine
(e.g. a MySQL service in CI), not SQLite.

**Manually exercising this recovery path end-to-end:**
`App\Console\Commands\CreateTestStripeOrder` (`app:stripe-test-order`)
takes a `--simulate-orphan` flag that stops right after Stripe creates the
PaymentIntent, before the local claim/Wallet transaction would normally be
written — reproducing this exact gap on demand instead of only being able
to test the happy path. Its own output prints the commands to then run
`app:reconcile-orphaned-payment-intents --stale-after=0` and confirm the
recovered PaymentIntent.

## What a second PSP adapter should confirm before a `PaymentGateway` interface gets extracted

- Whether "create a pending transaction synchronously when the payment
  attempt starts" still holds, or whether some providers only tell you
  about a payment after the fact.
- Whether the event mapping above (succeeded / a non-terminal failed-attempt
  signal / a terminal-failure signal / refunded) is universal, or whether
  other providers split these into more or fewer states. Stripe's own
  attempt-vs-terminal split (`payment_intent.payment_failed` vs.
  `payment_intent.canceled`) is a good reason not to assume "failed" is a
  single event for every provider.
- Whether every provider can supply a stable id suitable as the
  idempotency reference at the moment the transaction is first recorded.
