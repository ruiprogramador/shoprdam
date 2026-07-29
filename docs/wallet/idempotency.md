# Idempotency

Confirmed against a real consumer (the Stripe integration — see
[integrations.md](integrations.md)), not just against hypothetical usage.

- Idempotency is keyed exclusively by the `WalletTransactionReference`
  argument (`provider` + `reference`). The `external_provider` /
  `external_reference` keys inside the `options` array are stored as-is
  but play no role in deduplication.
- First write wins: when a duplicate reference arrives, the original
  persisted payload (description, metadata, source, created_by) is never
  overwritten — only a `pending → completed` promotion is allowed to
  happen on the existing row.
- A unique DB constraint on `(external_provider, external_reference)` is
  the actual backstop against races between the app-level lookup and the
  insert; a caught `QueryException` (`23000`) falls back to re-reading the
  existing row.
- Reversals reuse the same mechanism, scoped additionally by
  `related_transaction_id` — so the same external reference can't
  accidentally match a reversal of a *different* original transaction.

## What a PSP integration must guarantee

- A stable id to use as the reference, decided at the moment the
  transaction is first recorded, that will be identical on every retried
  or duplicate delivery for the same logical event. Stripe's
  `PaymentIntent.id` (for the sale/pending transaction) and `Charge.id`
  (for its reversal) both satisfy this — they're stable identifiers issued
  once by Stripe, not something the integration invents per attempt.
- Tolerance for at-least-once delivery: a webhook handler must be safe to
  run twice for the same event. `StripeEventDispatcher` achieves this by
  catching the specific "already in that state" exception from the Wallet
  operation it calls (`TransactionNotPendingException` for
  `confirm()`/`markFailed()`, `TransactionAlreadyReversedException` for
  `reverse()`) and treating it as a no-op — never by checking status
  beforehand, which would race against a concurrent duplicate delivery.
