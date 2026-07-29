# Invariants

Each rule below must hold regardless of caller. Confirmed against a real
consumer (the Stripe integration), not just against the Wallet's own tests.

- A wallet's balance never goes negative — enforced at the point a
  transaction would be applied (`record`, `confirm`, `reverse`), not
  after the fact.
- A completed transaction's `amount` and `balance_after` never change
  after creation.
- A wallet's `balance` is always derivable, in principle, by replaying its
  `completed` transactions in order — the column is a cache, not the
  source of truth.
- Every balance-affecting write locks the wallet row (`lockForUpdate`)
  inside a DB transaction before computing the new balance.
- A transaction's direction is fixed by its category and is never
  overridden per-transaction.
- Two transactions with the same `(external_provider, external_reference)`
  pair are always the same logical event (see [idempotency.md](idempotency.md)).
- `referenceable` is set once at creation and is never reassigned — a
  transaction's link to the business record that justified it (e.g. an
  `Order`) is as immutable as the transaction itself.
- Every operation is safe to retry: calling it again in a state it no
  longer applies to raises a specific, catchable exception rather than
  corrupting state (see [idempotency.md](idempotency.md)) — callers driven
  by at-least-once delivery (webhooks) are expected to catch that
  exception and treat it as a no-op, not to pre-check state themselves.
