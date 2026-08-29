# Operations

One section per public method of `WalletTransactionService`: preconditions,
effect, exceptions, and the test(s) that prove it. Confirmed against a real
consumer (the Stripe integration — see [integrations.md](integrations.md)),
not just against hypothetical usage.

## `record()`

- Preconditions: amount is a positive decimal string; category slug and
  status slug must resolve.
- Effect on balance: only if `status` resolves to `completed`.
- Idempotency: driven solely by the `WalletTransactionReference` parameter
  (see [idempotency.md](idempotency.md)) — `options.external_provider` /
  `options.external_reference` are inert.
- Exceptions: `InvalidTransactionAmountException`,
  `InsufficientWalletBalanceException`, unknown-slug `RuntimeException`s.
- Tests: `WalletTransactionServiceRecordTest`.

## `confirm()`

- Preconditions: transaction must be `pending`.
- Effect: applies the transaction's amount to the wallet balance, marks
  `completed`.
- Exceptions: `TransactionNotPendingException`,
  `InsufficientWalletBalanceException`.
- Tests: `WalletTransactionServiceConfirmTest`.

## `markFailed()`

- Preconditions: transaction must be `pending`.
- Effect: status → `failed`. Never touches the wallet balance.
- Tests: `WalletTransactionServiceMarkFailedTest`.

## `reverse()`

- Preconditions: original transaction must be `completed`, must not itself
  be a reversal, must not already have a reversal, and (if a wallet is
  passed) must belong to it.
- Effect: creates a new transaction via `record()`, linked via
  `related_transaction_id`.
- Exceptions: `TransactionNotCompletedException`,
  `CannotReverseReversalException`, `TransactionAlreadyReversedException`,
  `WalletMismatchException`.
- Tests: `WalletTransactionServiceReverseTest`.

## Return value

All four operations return the affected `StoreWalletTransaction`, freshly
reloaded from the database (`->fresh()`). Callers must not rely on the
in-memory instance they passed in still reflecting the persisted state —
always use the returned value, or reload explicitly.

## Real-world exercise

`App\Domain\Payments\Services\PaymentEventProcessor` calls all four
operations from provider-neutral event outcomes translated by each
provider's own adapter (e.g. `App\Payments\Stripe\StripeEventTranslator`,
driven by real Stripe event shapes — see
`tests/Feature/Payments/Stripe/StripeWebhookControllerTest.php`). This is
the first consumer that exercises the contract above outside of the
Wallet's own test suite. See `docs/wallet/integrations.md` for the full
payments domain architecture.
