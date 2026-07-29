# Integrating a payment provider

This is written from the first real integration (Stripe — see
`App\Payments\Stripe`), as agreed: no `PaymentGateway` interface exists yet.
One should only be extracted once a second provider needs the same shape,
so the interface reflects two real implementations instead of a guess.

## The Wallet never sees provider vocabulary

`WalletTransactionService` has no knowledge of Stripe, PaymentIntents,
Charges, or webhook event types. The only class allowed to speak both
languages is `App\Payments\Stripe\StripeEventDispatcher`. Everything else
in `App\Payments\Stripe` either talks to Stripe (`StripePaymentService`,
`StripeWebhookController`) or talks to the Wallet (via
`WalletTransactionService`), never both.

## Event -> operation mapping (Stripe)

| Stripe event | Wallet operation | Notes |
|---|---|---|
| PaymentIntent created (app-initiated, not a webhook) | `record(status: pending)` | Done synchronously in `StripePaymentService::createPaymentIntentForOrder()`, in the same request that creates the PaymentIntent — not in response to a webhook. |
| `payment_intent.succeeded` | `confirm()` | No-ops (via caught `TransactionNotPendingException`) if the transaction is already `completed` — safe against Stripe's at-least-once webhook delivery. |
| `payment_intent.payment_failed` | `markFailed()` | Same no-op safety for duplicate deliveries. |
| `charge.refunded` | `reverse()` | No-ops (via caught `TransactionAlreadyReversedException`) on duplicate delivery. |

## The idempotency reference

Every Wallet operation triggered by Stripe uses a
`WalletTransactionReference('stripe', <id>)`:

- The initial `record()` call uses the **PaymentIntent id** — this is what
  ties every later webhook back to the same transaction.
- `reverse()` uses the **Charge (or refund) id** — a different reference
  than the original, since it identifies the reversal transaction itself,
  not the original sale.

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

## Retries and replays

A provider integration must be safe to re-invoke with the same event
without side effects beyond the first call. This is why every dispatcher
handler wraps its Wallet call in a catch for the specific "already in that
state" exception rather than checking status beforehand — the check and
the mutation happen atomically inside `WalletTransactionService`, so there
is no read-then-write race between two concurrent webhook deliveries.

## What a second PSP adapter should confirm before a `PaymentGateway` interface gets extracted

- Whether "create a pending transaction synchronously when the payment
  attempt starts" still holds, or whether some providers only tell you
  about a payment after the fact.
- Whether the three-event mapping above (succeeded / failed / refunded) is
  universal, or whether other providers split these into more states.
- Whether every provider can supply a stable id suitable as the
  idempotency reference at the moment the transaction is first recorded.
