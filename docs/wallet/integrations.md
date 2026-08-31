# Integrating a payment provider

This describes the provider-agnostic payments domain (`App\Domain\Payments`)
and its first real implementation, Stripe (`App\Payments\Stripe`). The
domain was refactored from an earlier, Stripe-only design (`OrderPaymentIntent`,
`OrderPaymentIntentAttempt`, `StripePaymentService`, `StripeEventDispatcher`,
`stripe_unmatched_events`) once a second provider (PayPal, EasyPay, or a
Multibanco/MB WAY-capable PSP) became a real, near-term requirement — not
speculatively. Everything below is the current design; where it differs
from that earlier one, it's because the earlier one baked in an assumption
("one Order, one PaymentIntent, forever") that stopped being true once a
customer needed to retry a failed card payment with MB WAY instead.

## Known production limitations — read before building on top of this

These are deliberate, tested, documented boundaries of the current
integration — not oversights — but each one is a real constraint on what
you can safely build on top of this code without redesigning part of it
first:

- **No partial refunds.** A provider's refund event fires on partial
  refunds too, but this integration only reverses a transaction once the
  charge is *fully* refunded (see the event mapping below). A cumulative
  refunded amount of €20 on a €100 sale is silently ignored — the Wallet
  still shows the full €100 as reversible/settled. **Do not add a
  partial-refund button, a partial-refund API endpoint, or any other way to
  trigger a partial refund** until the Wallet's reversal accounting is
  redesigned to track refunds individually instead of "all or nothing."
- **2-decimal currencies only.** `App\Domain\Payments\MinorUnits::fromDecimal()`
  always multiplies by 100. The `currencies` table has a real `precision`
  column, but nothing here (or in the Wallet's `decimal(_, 2)` schema)
  honors it. **Do not enable a zero-decimal currency (e.g. JPY) or a
  3-decimal one (e.g. BHD)** without fixing this at the schema level first.
- **An orphaned remote payment recovers within `--stale-after` minutes
  (default 5), not instantly.** See
  [Recovering orphaned payment attempts](#recovering-orphaned-payment-attempts-the-payment_attempts-outbox)
  and
  [Terminal webhooks that arrive before a local claim exists](#terminal-webhooks-that-arrive-before-a-local-claim-exists-the-payment_provider_events-inbox).
- **`payment_provider_events` retention only ever removes `applied` rows.**
  `App\Console\Commands\PrunePaymentProviderEvents` (scheduled daily) deletes
  `applied` rows once `processed_at` is older than
  `payments.provider_event_retention_days` (default 90) — see
  [Pruning applied provider events](#pruning-applied-provider-events). A
  `pending` row is **never** pruned by age, regardless of how old it is:
  an attempt whose claim is never created (`needs_attention`, never
  manually resolved) leaves its queued events `pending` indefinitely, and
  they stay queryable for as long as that's true.
- **At most one *non-terminal* attempt per Payment at a time** — see
  [One Payment, multiple attempts over time — never two live at once](#one-payment-multiple-attempts-over-time--never-two-live-at-once).
  Concurrent attempts across two providers for the same Payment (e.g. the
  customer opens two tabs and pays via both Stripe and MB WAY at once) are
  not supported; the second tab's attempt resumes the first's in-flight
  attempt instead of starting an independent one.

## Payment vs. PaymentAttempt vs. provider vs. method

Four concepts that are easy to conflate, kept deliberately distinct:

| Concept | What it is | Example |
|---|---|---|
| **Payment** | The single financial obligation an Order has. One per Order (`payments.order_id` unique). Owns its own lifecycle (`pending`/`paid`/`failed`/`refunded`) via `App\Domain\Payments\Enums\PaymentStatus`. | "Order #100 owes €42.50." |
| **PaymentAttempt** | One try to satisfy a Payment through a specific provider/method. Many per Payment *over time*; at most one non-terminal at once. | "Attempt #1: Stripe/card, failed." |
| **provider** | Which payment service processed the attempt. | `stripe`, `paypal`, `easypay` |
| **method** | How the customer paid, within that provider. | `card`, `mbway`, `multibanco`, `paypal` |

`provider` and `method` are **not** the same axis: EasyPay is one
*provider* that can process several *methods* (`mbway`, `multibanco`).
Modeling MB WAY or Multibanco as their own "provider" would be wrong — they
share EasyPay's actual integration (auth, API, webhook shape); only the
`method` column differs. `payment_attempts` carries both as plain strings,
validated only by what `App\Domain\Payments\PaymentProviderManager` can
actually resolve a driver for.

## One Payment, multiple attempts over time — never two live at once

```
Order #100, €42.50
  |
  v
Payment (pending)
  |
  +-- PaymentAttempt #1: stripe / card    -> failed
  +-- PaymentAttempt #2: easypay / mbway  -> expired
  +-- PaymentAttempt #3: easypay / paypal -> succeeded  => Payment paid
```

This is the core design decision this refactor made deliberately, not by
default: a Payment can go through several attempts (a declined card, then
a different method), but **at most one attempt may be non-terminal at any
given time** — see `App\Domain\Payments\Enums\PaymentAttemptStatus::blocksNewAttempt()`.
Starting a *new* attempt while one is still in flight (`pending`/`claimed`)
or already `succeeded` doesn't create a second row — it resumes the
existing one. A new attempt is only createable once the current one is
`failed`.

This is what closes the double-payment risk that unrestricted concurrent
attempts would open: if two attempts for the same Payment could both be
"live" at once (e.g. the customer pays via Stripe in one tab and MB WAY in
another), both could independently reach `succeeded` and double-credit the
Wallet. Serializing attempt creation — never two non-terminal attempts for
one Payment — makes that structurally impossible, at the cost of not
supporting genuinely concurrent multi-channel attempts. If that's ever a
real requirement, it needs its own explicit design (an idempotent
"first-succeeded-wins, reverse the other" reconciliation step), not a
quiet relaxation of this gate.

`App\Domain\Payments\Services\PaymentService::startAttempt()` enforces this
by locking the Payment row (`lockForUpdate()`), checking its current
attempt's status, and only then creating a new attempt row — the lock is
released *before* any remote provider call is ever made (see
[Transaction boundaries](#transaction-boundaries) below); holding a DB row
lock across an HTTP call is the anti-pattern this design avoids.

## The Wallet never sees provider vocabulary

`WalletTransactionService` has no knowledge of Stripe, PayPal, PaymentIntents,
webhooks, or any provider's event types — it only ever sees a generic
`(external_provider, external_reference)` pair via
`App\Domain\Wallet\WalletTransactionReference`. This didn't need to change
for this refactor: the Wallet boundary was already provider-agnostic.
`App\Domain\Payments\Services\PaymentEventProcessor` is the only class
allowed to translate *incoming* provider events into Wallet operations —
that's the direction where a provider's vocabulary must be turned into the
Wallet's.

`App\Domain\Payments\Services\PaymentService` is the *outbound* counterpart:
it starts/resumes attempts and records the matching pending Wallet
transaction, in the same call that starts a payment. It talks to both a
resolved provider and the Wallet, but only ever in that one fixed direction
— it never interprets a provider event, which is what keeps
webhook-translation responsibility concentrated in the event processor.

## Provider vs. domain: who's allowed to know what

```
Payments Domain (App\Domain\Payments)
      |
      +-- App\Payments\Stripe\StripePaymentProvider    (PaymentProviderContract)
      +-- App\Payments\Stripe\StripeEventTranslator     (ProviderEventTranslator)
      +-- App\Payments\Stripe\StripeWebhookController    (Stripe's own route)
```

`App\Domain\Payments\*` never imports a provider SDK type — enforced by
`tests/Architecture/PaymentsDomainBoundaryTest.php`, a plain file scan
(not a dedicated architecture-testing package, for one boundary that
doesn't need one). Two small contracts define the crossing:

- **`PaymentProviderContract`** — `name()`, `createOrGetPayment(PaymentAttempt): ProviderPaymentResult`
  (idempotent — safe to call again for the same attempt), `classifyFailure(Throwable): FailureClass`.
  Deliberately minimal: capture/authorize/expire aren't here — add a
  capability-specific interface (like `SupportsCanonicalRetrieval`, used
  only for the losing-side-of-a-race fallback) once a real provider
  actually needs it, instead of forcing every adapter to pretend it
  supports operations it doesn't have.
- **`ProviderEventTranslator`** — `translate(nativeEvent): ProviderEventOutcome`
  and `reconstructFromReplayPayload(array): nativeEvent`. The only place
  allowed to know a provider's own event vocabulary
  (`payment_intent.succeeded`, `charge.refunded`, ...); everything past
  `translate()` speaks the domain's small, fixed vocabulary instead
  (`ProviderEventType::Succeeded|Failed|Refunded|Informational|Unrecognized`).

`App\Domain\Payments\PaymentProviderManager` and
`ProviderEventTranslatorManager` (both thin `Illuminate\Support\Manager`
subclasses) resolve a provider/translator by name, registered from
`config('payments.providers')` in `App\Providers\PaymentServiceProvider` —
neither manager, nor `PaymentService`, nor `PaymentEventProcessor` ever
`new`s a concrete provider class or hardcodes a provider name. **Adding a
second provider is a `config/payments.php` entry plus its two classes —
nothing in the domain changes.**

A provider's own webhook route/controller stays separate per provider
(`routes/webhooks.php`'s `stripe/webhook` → `StripeWebhookController`
today), not one generic endpoint — signature verification, payload shape,
and native vocabulary differ enough between providers that forcing them
through one endpoint would either make every provider pretend to look like
Stripe or hide those differences behind conditionals. Each controller does
its own auth/parsing, then crosses the translation boundary immediately:

```php
$event = Webhook::constructEvent(...);                 // Stripe-specific
$outcome = $this->translator->translate($event);        // crosses the boundary
$this->eventProcessor->apply($outcome);                 // generic from here
```

## Event -> operation mapping

| Native event (Stripe today) | Generic type | Wallet operation | Notes |
|---|---|---|---|
| `payment_intent.succeeded` | `Succeeded` | `confirm()` | No-ops (via caught `TransactionNotPendingException`) if the transaction is already `completed`. If no local transaction exists yet, queued instead of dropped — see the inbox section below. |
| `payment_intent.payment_failed` | `Informational` | **none** | A single payment *attempt* failing doesn't kill the attempt's remote payment — most providers let the customer retry with a different payment method on the same reference, which can still end in `Succeeded`. Never queued for replay (I10). |
| `payment_intent.canceled` | `Failed` | `markFailed()` | Fires as its own, separate event when the remote payment is actually done. This — not the attempt-level failure above — is what terminally fails the PaymentAttempt. Same no-op safety for duplicate deliveries. |
| `charge.refunded` | `Refunded` | `reverse()` | No-ops (via caught `TransactionAlreadyReversedException`) on duplicate delivery. **Only reconciles a fully-refunded charge** — see the limitation above. Queued instead of dropped if no local transaction exists yet, **or** if one exists but isn't `completed` yet (a refund can never reverse a non-completed sale). |
| anything else | `Unrecognized` | none | Logged and ignored — never queued for replay. |

Both places that convert a Wallet decimal amount into a provider's
minor-unit integer (`record()`'s amount, and the refund comparison) go
through `App\Domain\Payments\MinorUnits::fromDecimal()` — a domain concern
now, not a Stripe one (it was `App\Payments\Stripe\StripeAmount` before
this refactor; the conversion itself has nothing to do with Stripe, every
provider this domain talks to takes integer minor units).

### Terminal Failed vs. a retryable attempt-level failure

`ProviderEventType::Failed` — and therefore
`PaymentAttemptStatus::Failed` — must mean **the remote payment this
attempt represents is irreversibly terminal and can never later settle
successfully.** This is a load-bearing financial invariant, not a naming
preference:

```
PaymentAttemptStatus::Failed::blocksNewAttempt() === false
```

`Failed` is the *only* status that lets
`PaymentService::createDurableAttempt()` start a fresh PaymentAttempt for
the same Payment (a different provider/method) while the old one still
exists — see
[One Payment, multiple attempts over time](#one-payment-multiple-attempts-over-time--never-two-live-at-once).
If a merely retryable, intermediate, or otherwise non-final provider signal
were translated as `Failed`, a second attempt could be created and go on to
*also* succeed while the first provider payment was still capable of
succeeding too — two live charge paths for one Payment, and a double
Wallet credit if both later settle. Concretely:

```
Attempt A (provider A) claimed — remote payment still capable of succeeding
   |
Attempt A incorrectly marked Failed  <-- must never happen
   |
Payment still Pending -> Attempt B (provider B) created
   |
Remote payment A later succeeds too -> two live charge paths, double credit
```

This is why a provider's own `ProviderEventTranslator` — never
`PaymentEventProcessor`, which just settles whatever type it's handed — is
the single place responsible for this distinction, for its own native
vocabulary. For Stripe (`App\Payments\Stripe\StripeEventTranslator`):

- **`payment_intent.payment_failed` → `Informational`, never `Failed`.** A
  single failed payment *attempt* doesn't kill the PaymentIntent — Stripe
  lets the customer retry with a different payment method on the same
  reference, which can still end in `payment_intent.succeeded`. Purely
  informational: never mutates PaymentAttempt/Payment/Wallet state, never
  queued for replay.
- **`payment_intent.canceled` → `Failed`.** Stripe's own irreversible
  terminal state for a PaymentIntent — once canceled, it cannot transition
  to `succeeded`. This is the *only* Stripe event this integration ever
  translates as `ProviderEventType::Failed`, and therefore the only path
  that can ever produce `PaymentAttemptStatus::Failed` in this codebase.

A future provider adapter must classify its own vocabulary the same way:
confirm which of its native events represent a genuinely final,
no-forward-transition state before ever mapping one to `Failed` — an event
that merely means "this attempt didn't work, but the underlying remote
payment/session could still resolve later" belongs at `Informational` (or
another non-terminal outcome appropriate to that provider), never `Failed`.
Introducing a new `PaymentAttemptStatus`/`ProviderEventType` case is only
warranted if the existing two-outcome model genuinely cannot represent a
provider's semantics safely — not as a default response to an awkward
mapping.

## One remote payment per attempt, one attempt claimed at a time

`WalletTransactionService::record()`'s own idempotency is keyed by
`(external_provider, external_reference)` — it has no way to know two
different references belong to the same PaymentAttempt. Without a separate
guard, calling `PaymentService::startAttempt()` twice for the same
in-flight attempt (a plain repeat call, or two concurrent requests) could
create two real remote payments and two independent pending "sale" Wallet
transactions — both could later be confirmed, double-crediting the Wallet.

Two mechanisms close this, at different layers:

- **Provider-side:** the remote payment is requested with a deterministic,
  attempt-scoped `idempotency_key` (`payment-{payment_id}-attempt-{attempt_id}`
  for Stripe). Two calls under the same key and the same params return the
  same remote payment rather than creating a second one. A
  `UNIQUE(provider, idempotency_key)` database constraint on
  `payment_attempts` backs this at the DB layer too — provider-scoped, not a
  bare unique on `idempotency_key` alone, since different providers may
  legitimately mint keys from the same application-side shape independently
  of one another (this is a defense-in-depth backstop against a future bug
  in key generation; the deterministic derivation above already makes an
  application-level collision practically impossible).
- **Local:** `payment_attempts` has a unique constraint on `(provider, provider_reference)`
  — the layer that actually decides whether a second Wallet transaction
  gets created, holding even in the hypothetical case where the
  provider-side idempotency key doesn't (e.g. a bug in how it's sent).
  Persistence is a conditional `UPDATE payment_attempts SET provider_reference
  = ? WHERE id = ? AND provider_reference IS NULL` — if two processes are
  finalizing the *same* attempt row concurrently (e.g. a reconciliation
  lease expired and got reclaimed while the original worker was still
  mid-call), only one `UPDATE` can win. The loser reads back whichever
  reference actually got persisted and, if it differs from its own result,
  fetches and validates *that* one (via `SupportsCanonicalRetrieval`)
  before ever trusting it — the database's row, not either caller's own
  provider response, is the source of truth for which reference an attempt
  claimed. A provider without a "retrieve by reference" capability fails
  closed instead (`PaymentAttemptMismatchException`) rather than trusting
  an unvalidated reference.

Wrapping the persistence step and `record()` in one transaction also means
a `record()` failure rolls the claim back too, instead of permanently
locking the attempt out of a working retry — a later call just calls the
provider again (same idempotency key, same remote payment) and tries once
more.

## The idempotency reference

The initial provider-originated Wallet transaction is anchored by a
`WalletTransactionReference($provider, $providerReference)` — `confirm()`
and `markFailed()` don't take a new reference themselves, they operate on
the transaction that reference already identified:

- The initial `record()` call uses the **payment's own reference**
  (Stripe's PaymentIntent id) — this is what ties every later webhook back
  to the same transaction.
- `reverse()` uses the **refund's own reference** (Stripe's Charge id) — a
  different reference than the original, since it identifies the reversal
  transaction itself, not the original sale.

## Linking back to application data without the Wallet knowing about it

The Wallet's `referenceable` morph column links a sale transaction to
whatever business record justified it — currently a minimal `Order` model.
Given that link, `PaymentEventProcessor` never needs to trust or parse
metadata coming back from a provider: it looks up the transaction by
`(external_provider, external_reference)`, then reads
`$transaction->referenceable` to find the `Order` to update.
`ProviderPaymentResult::$correlationId` (Stripe's `metadata.order_id`,
mapped generically) is still validated at claim time — see
[A recovered payment is checked against the Attempt before it's ever claimed](#a-recovered-payment-is-checked-against-the-attempt-before-its-ever-claimed)
— but the event processor's own correctness never depends on trusting it a
second time.

The lookup is further scoped to `whereNull('related_transaction_id')`, i.e.
it only ever matches an original sale transaction, never a reversal. This
assumes one provider reference maps to exactly one original transaction —
structurally enforced by the `external_ref_idx` unique index on
`(external_provider, external_reference)` in `store_wallet_transactions`.
A new provider adapter must preserve this 1:1 mapping.

## Which PaymentAttempt a settlement event transitions

`(provider, provider_reference)` — the same pair a Wallet transaction is
looked up by, above — is the **canonical identity of the historical
PaymentAttempt** a settlement event (`Succeeded`/`Failed`) transitions.
`PaymentEventProcessor::markSettled()` resolves it with:

```php
PaymentAttempt::where('payment_id', $payment->id)
    ->where('provider', $transaction->external_provider)
    ->where('provider_reference', $transaction->external_reference)
    ->first();
```

`Payment.current_payment_attempt_id` is a **different concept and is never
used for this lookup**: it only ever names whichever attempt is *currently*
gating new-attempt creation (`PaymentService::createDurableAttempt()`), not
which attempt historically claimed a given provider reference. Those two
can diverge — a terminally `failed` attempt is no longer current once a
retry with another provider/method starts (see
[One Payment, multiple attempts over time](#one-payment-multiple-attempts-over-time--never-two-live-at-once))
— and a late/replayed event for the old reference must only ever transition
*that original attempt*, never whichever attempt happens to be current by
the time the event is finally processed. Since `payment_attempts.(provider,
provider_reference)` is unique, this lookup can only ever resolve to the one
attempt that actually claimed the reference.

This fails closed: if a settlement event needs to transition an attempt and
no attempt claims the exact reference, `markSettled()` throws
`PaymentAttemptNotFoundException` rather than silently skipping the
transition or guessing at a different attempt. Every Wallet transaction
settled here was itself created from a PaymentAttempt's own claimed
`provider_reference` (see
[One remote payment per attempt, one attempt claimed at a time](#one-remote-payment-per-attempt-one-attempt-claimed-at-a-time)),
so this should be unreachable in practice; the exception is thrown from
inside the same `DB::transaction()` wrapping the Wallet mutation (see
[Retries and replays](#retries-and-replays) below), so it rolls that
mutation back too instead of leaving the Wallet settled with no
corresponding PaymentAttempt record of it.

A refund (`Refunded`) never invents an attempt transition — `markSettled()`
is called with `attemptStatus: null` for a refund, same as before this
hardening; see [Event -> operation mapping](#event---operation-mapping).

## Retries and replays

A provider integration must be safe to re-invoke with the same event
without side effects beyond the first call — including on the application
side, not just the Wallet. Every event-processor handler wraps its Wallet
call in a catch for the specific "already in that state" exception **and
returns immediately** rather than falling through to updating Order/Payment
status: the check and the mutation happen atomically inside
`WalletTransactionService`, so there is no read-then-write race between two
concurrent webhook deliveries, and a no-op Wallet call must also mean a
no-op status update (no timestamp bump, no re-fired model events).

The Wallet mutation and the Order/Payment/PaymentAttempt status update are
wrapped together in one `DB::transaction()` per handler. Without this, a
status-update failure *after* a successful `confirm()`/`markFailed()`/`reverse()`
would leave the Wallet transaction settled with the Order stuck out of
sync, and a replayed webhook could never repair it. With the wrap, that
failure rolls back the Wallet mutation too, so the transaction stays
`pending`/reversible and a later retry can still succeed cleanly.

## Transaction boundaries

`PaymentService::startAttempt()` calls a remote provider API and writes to
the database; these are never one atomic operation because a provider's
HTTP API is outside this application's database transaction. The design
accepts that gap rather than pretending a DB transaction could close it,
and instead makes it *bounded and automatically recovered*:

```
lock Payment row
   |
create durable PaymentAttempt row (pending)
   |
release lock                              <-- never held across the remote call
   |
call the provider                          <-- CRASH HERE: pending attempt, no reference
   |
conditional UPDATE: claim provider_reference + record() the pending Wallet transaction
   |
   +-- CRASH HERE: attempt pending, no reference — same as above, recovered the same way
   |
replay any queued payment_provider_events for this reference
```

Every crash window in this sequence resolves to the same recoverable
state: a `payment_attempts` row that's `pending` with no `provider_reference`.
`ReconcileOrphanedPaymentAttempts` finds it and calls the provider again
under the same idempotency key — see the next section.

## Recovering orphaned payment attempts: the `payment_attempts` outbox

A DB transaction can't make the provider call and the local write atomic,
but it can make the *fact that the provider was called* durable,
independently of whether the rest of the operation succeeds. That's what
the attempt row already is: written in its own fast commit *before*
`startAttempt()` ever calls the provider, carrying the Payment id, the
provider/method, and the deterministic idempotency key. Standard outbox
pattern, scoped to exactly this one gap.

`App\Console\Commands\ReconcileOrphanedPaymentAttempts` finds `pending`
attempts older than `--stale-after` minutes (default 5, scheduled every 5
minutes in `routes/console.php`) whose reconciliation lease isn't currently
held (see below) and recovers each by calling
`PaymentService::finalizeAttempt()` again — nothing more specialized than
that. Because the idempotency key is deterministic, the provider either
returns the original remote payment (the common case) or, if the original
request never actually reached it, creates it now — both are the correct
outcome of finally completing the original attempt.

The same command also sweeps a second, independent candidate set every run
— any attempt with an established claim (`provider_reference` not null:
`claimed`, `succeeded`, *or* `failed`, deliberately not narrowed to
`claimed`) that still has a `pending` `payment_provider_events` row for
that same reference — covering the narrower crash window between a claim
or settlement committing and its event replay running; see
[When replay runs](#when-replay-runs) below. Unlike the `pending` sweep
above, this one never touches the attempt's `status` or lease, whatever it
currently is: the claim/settlement is a consumed fact, not something to
retry — only the event replay is.

**Recovery only ever rebuilds pending local state — it never settles a
payment.** `finalizeAttempt()` only ever calls `record()` with `status:
pending`; it never calls `confirm()`. Turning a `pending` Wallet
transaction into `completed` stays the exclusive job of
`PaymentEventProcessor::applySucceeded()`, triggered only by a provider's
own webhook. Reconciliation answers "does this attempt have a local
representation yet?", never "did this payment succeed?" — only a
provider's webhook is allowed to answer the second one.

**State diagram** (`App\Domain\Payments\Enums\PaymentAttemptStatus`):

```
pending
  |  lease acquired (acquireLease(): UPDATE ... WHERE status = 'pending'
  |  AND (locked_until IS NULL OR locked_until <= now()))
  v
pending (still — the lease, in `locked_until`, is a separate concern
  |      from lifecycle status; see below)
  |-- provider call + local claim succeed --> claimed
  |-- retryable error, retries remain     --> pending (lease released, retried later)
  |-- non-retryable error                 --> needs_attention (terminal, manual)
  |-- retries exhausted (--max-attempts)  --> needs_attention (terminal, manual)
  |-- age exceeds --max-age               --> needs_attention (terminal, manual,
  |                                            no provider call made)
  `-- worker dies while leased            --> stays 'pending' until
                                               `locked_until` passes, then
                                               acquireLease() matches it again

claimed --(provider webhook: succeeded)--> succeeded
claimed --(provider webhook: canceled)---> failed

`needs_attention` and `succeeded`/`failed` are terminal for this attempt.
`failed` alone permits a *new* attempt for the Payment — see
"One Payment, multiple attempts over time" above.
```

The reconciliation lease (`payment_attempts.locked_until`) is deliberately
its own column, not folded into `status` (there is no `recovering` status
value) — the earlier Stripe-only design used a `recovering` status for
this, which meant an attempt's payment-lifecycle state and its
reconciliation-ownership state were entangled in one enum. Splitting them
means `status` answers "what happened to this attempt" and `locked_until`
answers "is a worker currently allowed to act on it" — two different
questions with two different owners.

**Every remote payment this method ever hands back is validated against
the attempt's Payment first** (see
[A recovered payment is checked against the Attempt before it's ever claimed](#a-recovered-payment-is-checked-against-the-attempt-before-its-ever-claimed))
— including the canonical one fetched via `retrieveByReference()` when a
call loses the finalization race, which is a *different* provider object
than the one validated earlier in the same call and is never assumed to
match just because the database says it's the one that won.

**Candidates are streamed, not loaded all at once.** The reconciler walks
candidates via `chunkById()` (page size: `payments.reconciliation_chunk_size`,
default 200) instead of `get()`, so its memory footprint doesn't grow with
the number of orphaned attempts. CLI options are validated up front
(`--stale-after >= 0`, `--max-attempts >= 1`, `--lease-timeout > 0`,
`--max-age > --stale-after`) — an invalid combination exits
`Command::INVALID` before touching the database or a provider.

### A recovered payment is checked against the Attempt before it's ever claimed

`PaymentService::assertResultMatchesAttempt()` compares a
`ProviderPaymentResult`'s amount, currency, and correlation id against the
attempt's Payment/Order right after the provider call returns — for both a
fresh result and a replayed one from the idempotency key — and throws
`PaymentAttemptMismatchException` on any mismatch, *before* the claim or
Wallet transaction is ever written. This is what stops a remote payment
that doesn't actually belong to this attempt's current data from ever
being recorded as its payment, instead of trusting "the provider gave it
back, so it must be right."

Being non-retryable, a mismatch sends the attempt straight to
`needs_attention` for manual review rather than retrying a request that
would return the exact same mismatched object every time —
`ReconcileOrphanedPaymentAttempts::isRetryable()` checks
`PaymentAttemptMismatchException` itself, a domain-level concern, before
ever delegating to the resolved provider's own `classifyFailure()`.

### `--max-age` bounds retries by absolute time, independently of `--max-attempts`

Every retry re-issues the same request under the same deterministic
idempotency key, which only guarantees the provider returns the original
payment while that key is still recognized — providers typically remove a
key after some retention window, after which reusing it risks being
processed as a new request instead. `--max-age` (default 720 minutes) is
checked *before* the retry logic, per attempt, on every run: an attempt
older than `--max-age` goes straight to `needs_attention`, no provider call
made, regardless of how many `recovery_attempts` it has left.

### Failure classification is delegated to the provider, never hardcoded

`ReconcileOrphanedPaymentAttempts` imports no provider SDK exception class
itself. `PaymentProviderContract::classifyFailure(Throwable): FailureClass`
is asked instead, resolved per attempt through `PaymentProviderManager`.
`App\Payments\Stripe\StripePaymentProvider::classifyFailure()` is where
Stripe's own exception hierarchy is known:

- **Retryable** — `RateLimitException`, `ApiConnectionException`, any other
  `ApiErrorException` with an HTTP status `>= 500`, and anything else
  unrecognized (including a local/database exception) — that permissive
  default is deliberate: an exception from `record()` or the DB
  transaction failing is exactly the crash/timeout window this whole
  mechanism exists to recover from.
- **Non-retryable** — `AuthenticationException`, `PermissionException`,
  `InvalidRequestException`, `CardException`, `IdempotencyException`.
  `RateLimitException` is checked **before** `InvalidRequestException`, not
  just alongside it: Stripe's SDK defines
  `RateLimitException extends InvalidRequestException`, so if the
  non-retryable arm were checked first, every 429 would match it via that
  inherited type and never reach retry logic at all.

A different provider's adapter classifies its own exceptions the same way,
independently — nothing here assumes Stripe's hierarchy.

### Two workers can't both call a provider for the same attempt

Every state change that decides who gets to *act* on a given attempt —
acquiring its lease, or sending it straight to `needs_attention` for being
too old — is a single `UPDATE ... WHERE` (see
`ReconcileOrphanedPaymentAttempts::acquireLease()`/`markNeedsAttention()`),
re-checking the attempt's eligibility against the database's current row,
not a possibly-stale copy this process read earlier. Only one of two
concurrent `UPDATE`s with the same `WHERE status = 'pending' AND (locked_until
IS NULL OR locked_until <= now())` can ever match a given row — the loser
sees 0 rows affected and moves on without ever calling the provider for
that attempt.

The `payment_attempts` `(provider, provider_reference)` unique constraint
remains a second, independent layer underneath this — see
[One remote payment per attempt, one attempt claimed at a time](#one-remote-payment-per-attempt-one-attempt-claimed-at-a-time)
above.

The scheduled entry in `routes/console.php` also applies
`->withoutOverlapping()->onOneServer()`, so a run that takes longer than
five minutes (or a deploy across multiple app servers) can't have two
processes reconciling the same attempts concurrently. This isn't
load-bearing for correctness — the mechanisms above already make two
concurrent workers safe — but it avoids relying on that as the only thing
standing between normal operation and two processes racing over the same
rows. `onOneServer()` requires the cache backend to be shared between
servers — confirm that's true of the configured cache driver before
relying on it operationally; correctness itself never depends on it.

**Manually exercising this recovery path end-to-end:**
`App\Console\Commands\CreateTestStripeOrder` (`app:stripe-test-order`)
takes a `--simulate-orphan` flag that stops right after Stripe creates the
remote payment, before the local claim/Wallet transaction would normally
be written — reproducing this exact gap on demand. Its own output prints
the commands to then run `app:reconcile-orphaned-payment-attempts
--stale-after=0` and confirm the recovered payment, for both webhook
orderings (see the next section).

## Terminal webhooks that arrive before a local claim exists: the `payment_provider_events` inbox

Provider webhooks are **at-least-once and give no ordering guarantee**.
Combined with the crash window above, that used to leave one case
unhandled (in the original Stripe-only design): a terminal event
(succeeded, canceled, a fully-refunded charge) arriving for a reference
that has no local claim yet.

```
DB attempt
   |
provider payment created
   |
CRASH
   |
terminal webhook may arrive  --> no local transaction to act on
   |
unmatched event persisted    --> payment_provider_events (pending)
   |
reconciliation
   |
claim created
   |
unmatched terminal event replayed
   |
correct final state (paid / failed / refunded)
```

Before this existed, the event processor's handlers simply returned when
no local transaction was found — a documented, tested gap where
reconciliation would recreate the Wallet transaction as `pending`, and it
would **stay pending forever**, even though the provider had already told
us the payment succeeded. That is no longer the final state — proven by
`tests/Feature/Payments/Stripe/ReconciliationWebhookOrderingTest.php`'s
full ordering matrix (below).

### The mechanism

`payment_provider_events` is a small, purpose-built inbox — not a general
event-sourcing store. A row is written when `PaymentEventProcessor` can't
resolve an event yet:

- `Succeeded` / `Failed`: no local transaction found for the reference at all.
- `Refunded`: no local transaction found, **or** one exists but isn't
  `completed` yet (a refund can never reverse a non-completed sale).

`Informational` events (Stripe's `payment_intent.payment_failed`) are never
queued — not terminal, nothing to replay it toward.

Persistence is idempotent on `(provider, provider_event_id)` — a
redelivery of the same event before it's resolved is a no-op, not a
duplicate row. Only an allow-listed, minimal reconstruction of the event is
stored (each provider's translator decides exactly what — see e.g.
`StripeEventTranslator::minimalPaymentIntentPayload()`), never the raw
provider payload, so nothing sensitive (e.g. a PaymentIntent's
`client_secret`, an API key, unrelated customer data) ends up in the
database.

### Replay reuses the exact same settlement logic — nothing is duplicated

`PaymentEventProcessor::replayUnmatchedEvents(provider, reference)` loads
every `pending` row for that pair, in the order they were originally
received, reconstructs the provider's native event via that provider's own
`ProviderEventTranslator::reconstructFromReplayPayload()`, translates it
again, and calls **the same `apply()`** a live webhook goes through. There
is exactly one place that knows how to turn a provider event into a Wallet
mutation, whether it's reached live or replayed.

Each handler returns an `EventApplicationOutcome` (`Applied`, `Unresolved`,
or `Ignored`) instead of `void`. A row is marked `applied` — and never
replayed again — only when its handler reports `Applied`: settled, or
permanently a no-op (a duplicate of an already-terminal event; a partial
refund amount that can never match, since it's a fixed historical value).
`Unresolved` (e.g. a refund still waiting on `succeeded` to confirm the
sale) leaves the row `pending` for the next trigger.

### When replay runs

- **`PaymentService::finalizeAttempt()`**, right after a claim is
  confirmed — fresh or recovered. This is the primary trigger: it fires
  from a normal first-time claim, and from
  `ReconcileOrphanedPaymentAttempts` calling this same method, so
  reconciliation gets replay "for free" without the reconciler needing any
  awareness of the inbox at all.
- **`PaymentEventProcessor::applySucceeded()`**, after confirming (or
  no-op'ing on a duplicate), replays any queued `Refunded` event for the
  same reference — this is what resolves refund-before-succeeded ordering
  without a second refund webhook ever arriving.
- **`ReconcileOrphanedPaymentAttempts`'s second candidate set**, on every
  scheduled run: any attempt with an established claim
  (`provider_reference` not null — `claimed`, `succeeded`, or `failed`)
  that still has a `pending` `payment_provider_events` row for its own
  `(provider, provider_reference)`. This exists because two different call
  sites commit a real state change and only *then* replay, as two
  non-atomic steps (see
  [Transaction boundaries](#transaction-boundaries)): `finalizeAttempt()`
  (claim commit, then `replayUnmatchedEvents()`) and
  `applySucceeded()` (settlement commit, then its own nested `replay()`
  call for a refund queued ahead of it). If the process dies — or that
  later call itself throws, e.g. an uncaught exception from a live webhook
  (`StripeWebhookController` has no `try`/`catch` around `apply()`, so this
  surfaces as a `500` with no retry of its own beyond whatever the
  provider's webhook redelivery policy allows) — between the two steps,
  the attempt already carries its real, correct status (possibly already
  terminal) but its queued event was never replayed, and neither trigger
  above ever fires for it again (a provider won't redeliver a webhook it
  already got a `200` for). Recovering this never reopens the attempt or
  touches its `status`/lease, whatever that status is — the
  claim/settlement already happened and isn't retried, only the replay is
  — it just calls `finalizeAttempt()` again, which for an attempt that
  already has a `provider_reference` skips the provider call entirely and
  goes straight to `replayUnmatchedEvents()`. A failure here is left for
  the *event* row's own `replay_attempts`/`last_replay_error` to surface;
  the next run's still-`pending` row picks it up again.

All three call sites make replay **unconditional and idempotent by
construction**: a `pending` row is retried every time any trigger fires;
an `applied` row is never selected again; and `apply()` itself is already
required to be safe against duplicate delivery for every event type it
handles, live or replayed.

**A subtlety that cost a real bug during development:** the nested replay
call from `applySucceeded()` must exclude `Succeeded`-type events from
what it re-processes. A row is only marked `applied` *after* `apply()`
returns for it — while a `Succeeded` row is mid-`apply()`, it's still
`pending` in the database. An unscoped nested replay would re-select that
same row, call `apply()` on it again (hitting the harmless
`TransactionNotPendingException` no-op path), and immediately trigger
*another* nested replay — forever. Excluding `Succeeded`-type events from
that one call site is what makes it safe: nothing a `Refunded`-type
`apply()` does ever triggers a nested replay itself, so it can't re-enter
this way. See `PaymentEventProcessor::replay()`'s docblock.

### Ordering guarantees this closes

Proven by `ReconciliationWebhookOrderingTest` — every case starts from the
same orphaned state (a provider has a remote payment, a `pending` attempt
row, nothing else):

| Case | Order of events | Outcome |
|---|---|---|
| A | reconciliation -> succeeded | `paid` |
| B | succeeded -> reconciliation | `paid` (previously: stuck `pending` forever) |
| C | succeeded -> duplicate succeeded -> reconciliation | `paid`, exactly once |
| D | reconciliation -> succeeded -> duplicate succeeded | `paid`, exactly once |
| E | canceled -> reconciliation | `failed` |
| F | reconciliation -> canceled | `failed` |
| G | payment_failed -> reconciliation | stays `pending` (not terminal) |
| H | payment_failed -> reconciliation -> succeeded | `paid` |
| I | stored terminal event -> reconciliation -> replay -> repeat replay | exactly-once outcome |
| J | (claim exists) -> refund -> succeeded | reversed exactly once, automatically, no second refund webhook needed |

No case above may credit or reverse a balance twice, re-touch `updated_at`
on a duplicate delivery, or leave a payment a provider has already told us
is terminal stuck `pending` indefinitely.

### Known limitations

- **Not a general retry/outbox framework.** It only stores the terminal
  event types above, keyed by `(provider, reference)`, and is only ever
  replayed by the two triggers listed. Don't extend it to other event
  types without reconsidering whether this is still the right shape.
- **Pruning only ever removes `applied` rows.** See
  [Pruning applied provider events](#pruning-applied-provider-events) below
  — a `pending` row is never removed by age.
- **Operationally**, an attempt stuck at `needs_attention` can also have
  `pending` rows in `payment_provider_events` — worth checking alongside
  the attempt row when investigating one manually.

## Pruning applied provider events

`payment_provider_events` is a replay inbox, not a general audit log: once a
row's handler has reported `EventApplicationOutcome::Applied` — settled, or
permanently a no-op — it has no further replay purpose, and in steady state
these `applied` rows would otherwise accumulate indefinitely.
`App\Console\Commands\PrunePaymentProviderEvents`
(`app:prune-payment-provider-events`, scheduled daily in `routes/console.php`
with `withoutOverlapping()->onOneServer()`) deletes them once they're old
enough that nobody investigating a recent incident would need them.

**The delete predicate, conceptually:**

```
status = Applied
AND processed_at IS NOT NULL
AND processed_at < now() - retention_days
```

- **`pending` rows are never pruned, at any age.** A `pending` row still has
  real recovery work attached to it —
  `PaymentEventProcessor::replayUnmatchedEvents()` may still need it,
  possibly indefinitely if its owning attempt is stuck `needs_attention` —
  regardless of `replay_attempts` or how long it's been sitting there.
- **The retention anchor is `processed_at`, not `created_at`.** How long ago
  a row was *resolved* is what determines how long it's still useful for
  debugging a recent incident — not how long ago it first arrived, which
  understates relevance for an event that sat `pending` for weeks against a
  stuck attempt before finally being replayed.
- **An `applied` row with a null `processed_at` is never eligible either.**
  This shouldn't happen in practice (`replay()` always sets `processed_at`
  when marking a row `applied`), but the predicate fails safe — never
  pruning — rather than guessing at a missing anchor.

Retention is configurable via `payments.provider_event_retention_days`
(`PAYMENTS_PROVIDER_EVENT_RETENTION_DAYS`, default 90 days) or overridden
per-run with `--days`. Deletion is a chunked, conditional bulk
`DELETE ... LIMIT` loop (`payments.provider_event_prune_chunk_size`, default
500) rather than a loaded-then-deleted collection, mirroring
`ReconcileOrphanedPaymentAttempts`'s own `chunkById()` rationale — the
command's memory footprint doesn't grow with the number of eligible rows.
Safe to run repeatedly (an already-deleted row simply doesn't match a later
run's predicate again) and safe if two instances overlap (each batch only
ever matches rows that still satisfy the predicate at the moment it runs).

## Database invariants

- `payments.order_id` — unique. One Payment per Order.
- `payments.current_payment_attempt_id` — nullable FK into `payment_attempts`;
  the attempt currently governing the Payment's fate (in flight, or the
  one that won). Set/cleared only under a row lock — see
  [One Payment, multiple attempts over time](#one-payment-multiple-attempts-over-time--never-two-live-at-once).
- `payment_attempts.payment_id` — not unique (many attempts allowed over
  time); `payments.current_payment_attempt_id` is what actually bounds
  concurrency, not a constraint on this table.
- `payment_attempts.(provider, provider_reference)` — unique. Multiple
  `NULL` references (not-yet-claimed attempts) may coexist; MySQL and
  PostgreSQL both treat multiple `NULL`s as distinct under a unique index.
  Also the canonical identity a settlement event resolves the exact
  historical PaymentAttempt by — see
  [Which PaymentAttempt a settlement event transitions](#which-paymentattempt-a-settlement-event-transitions).
- `payment_attempts.(provider, idempotency_key)` — unique. A
  defense-in-depth DB-layer backstop for the already-deterministic,
  attempt-scoped idempotency key (see
  [One remote payment per attempt, one attempt claimed at a time](#one-remote-payment-per-attempt-one-attempt-claimed-at-a-time)).
  Provider-scoped, not a bare unique on `idempotency_key` — different
  providers may legitimately reuse the same application-side key shape
  independently.
- `payment_attempts.(status, created_at)` and `(status, locked_until)` —
  composite indexes matching the reconciler's actual candidate query; a
  plain index on `status` alone would still force a scan to apply the
  second predicate.
- `payment_provider_events.(provider, provider_event_id)` — unique.
  Idempotent persistence regardless of webhook redelivery count.
- `payment_provider_events.(provider, provider_reference, status)` — index
  matching the replay query.
- `payment_provider_events` retention — `applied` rows are pruned once
  `processed_at` ages past `payments.provider_event_retention_days`; `pending`
  rows and any row with a null `processed_at` are never pruned by age — see
  [Pruning applied provider events](#pruning-applied-provider-events).
- `store_wallet_transactions.(external_provider, external_reference)` —
  unique (`external_ref_idx`, pre-existing, unchanged by this refactor).
  Provider-namespaced from day one, which is what let this refactor add a
  second provider's transactions to the same table safely.

## What a second provider adapter needs to implement

- `PaymentProviderContract`: `name()`, `createOrGetPayment()`,
  `classifyFailure()`. `SupportsCanonicalRetrieval::retrieveByReference()`
  only if the provider's API supports fetching a payment by reference —
  optional, used only for the finalization-race fallback.
- `ProviderEventTranslator`: `translate()` and
  `reconstructFromReplayPayload()`, mapping the provider's own webhook
  event shape into `ProviderEventOutcome` — confirm whether the provider's
  event mapping (succeeded / a non-terminal failed-attempt signal / a
  terminal-failure signal / refunded) is universal, or whether it splits
  these into more or fewer states than Stripe's four.
- Its own webhook route + controller (see
  [Provider vs. domain: who's allowed to know what](#provider-vs-domain-whos-allowed-to-know-what)),
  doing its own signature verification.
- A `config/payments.php` entry registering both classes under the new
  provider's driver name, plus its credentials under their own key in
  `config/services.php` (never a shared/generic credentials structure).
- Whether it can supply a stable id, at the moment the transaction is
  first recorded, suitable as the Wallet idempotency reference — every
  provider integration needs this regardless of any other design choice.
