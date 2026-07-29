# Wallet domain

The Wallet is the ledger of a store's money. Every credit or debit is
recorded as an immutable `StoreWalletTransaction` against a `StoreWallet`;
the wallet's `balance` is a cached projection of its completed
transactions, never the source of truth on its own.

This directory is a **contract**, not an implementation guide. It describes
what the domain guarantees, independently of how `WalletService` or
`WalletTransactionService` happen to be implemented today. Implementation
details (class names, method signatures) are referenced only where useful
for navigation; the guarantees themselves are what must survive a refactor.

Each rule below is written so it can be checked against the test suite.
When a rule and a test disagree, one of the two is wrong — fix the
mismatch, don't silently pick a side.

## Core concepts

| Concept | What it is |
|---|---|
| `StoreWallet` | One balance per store per currency. Created via `WalletService`. |
| `StoreWalletTransaction` | A single, immutable ledger entry against a wallet. |
| Transaction category | Classifies *why* money moved (`sale`, `commission`, `customer_refund`, ...) and fixes its direction (credit/debit). Seeded in [TransactionCategorySeeder](../../database/seeders/TransactionCategorySeeder.php); slugs and directions are permanent once shipped. |
| Transaction status | Where a transaction is in its lifecycle (`pending`, `completed`, `failed`, ...). Seeded in [TransactionStatusSeeder](../../database/seeders/TransactionStatusSeeder.php). |
| Reference | `(provider, reference)` pair used for idempotency against external systems (e.g. a Stripe PaymentIntent id). |

## Documents

- [lifecycle.md](lifecycle.md) — transaction states, allowed transitions, what a reversal actually is.
- [operations.md](operations.md) — contract of each public operation (`record`, `confirm`, `markFailed`, `reverse`).
- [invariants.md](invariants.md) — rules that must never break, regardless of caller.
- [idempotency.md](idempotency.md) — behavior under duplicate calls and duplicate webhook deliveries.
- [integrations.md](integrations.md) — how a payment provider integration should talk to the Wallet.

## Non-goals (for now)

- This is not a guide to integrating a specific PSP (Stripe, Eupago, ...) —
  see [integrations.md](integrations.md) for the conceptual mapping, once
  a first PSP integration exists to validate it against.
- This is not API documentation for a future `Wallet` facade. No facade
  exists yet; one should only be introduced once real consumers
  (a payment integration, at minimum) reveal what shape it needs to have.
