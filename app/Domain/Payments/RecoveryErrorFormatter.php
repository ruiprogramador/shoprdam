<?php

namespace App\Domain\Payments;

use Throwable;

/**
 * Turns a provider/domain exception — or the mere fact that a diagnostic
 * string already exists on a stored column
 * (payment_attempts.last_recovery_error, payment_provider_events.last_replay_error)
 * — into text that is safe to persist to
 * App\Domain\Payments\Models\PaymentRecoveryAction.detail or render in
 * App\Http\Controllers\Admin\PaymentRecoveryController.
 *
 * Never includes a raw exception message or stored diagnostic string,
 * anywhere, under any condition. An earlier version of this class tried to
 * denylist known secret/credential/payload shapes and pass everything else
 * through unredacted — that is not actually fail-closed: it only ever
 * catches formats already anticipated (a specific key prefix, a specific
 * header name), and a future provider SDK version, a new EasyPay/Stripe
 * error variant, or a wholly different provider added later could put
 * something sensitive into a shape this denylist has never seen. The only
 * guarantee that survives every future provider change is to never show
 * the message at all — only structured metadata (provider, exception
 * class, HTTP status, and — where the caller already computed it —
 * whether the failure was retryable) whose *shape* this domain itself
 * controls, regardless of what a provider ever puts in a message string.
 *
 * This does mean an admin inspecting a failure here sees less detail than
 * the same failure's own log line. That tradeoff is deliberate: a raw
 * provider exception message still reaches a human in exactly one place —
 * this domain's own Log::/Command::error() output
 * (App\Domain\Payments\Services\PaymentAttemptRecoveryService's callers,
 * PaymentEventProcessor, the CLI commands) — a different trust boundary
 * than this web admin UI. Reaching that output requires server/log access
 * an operator who can only reach the admin panel does not have; accepting
 * that existing, unrelated diagnostic-logging surface is not the same as
 * this class (or the admin UI built on it) also exposing the same free
 * text to a browser.
 */
final class RecoveryErrorFormatter
{
    /**
     * Structured-only summary for PaymentRecoveryAction.detail and admin
     * flash messages. Deliberately never includes $exception->getMessage()
     * — see the class docblock.
     */
    public static function summarizeForAudit(Throwable $exception, string $provider, ?bool $retryable = null): string
    {
        $parts = [$provider, $exception::class];

        if (($status = self::httpStatus($exception)) !== null) {
            $parts[] = "http {$status}";
        }

        if ($retryable !== null) {
            $parts[] = $retryable ? 'retryable' : 'non-retryable';
        }

        return implode(' / ', $parts);
    }

    /**
     * Whether an already-stored diagnostic string exists — never its
     * content. Use this to render "an error is on record" without ever
     * rendering what it says; see the class docblock for why.
     */
    public static function hasStoredMessage(?string $message): bool
    {
        return $message !== null && trim($message) !== '';
    }

    private static function httpStatus(Throwable $e): ?int
    {
        if (method_exists($e, 'getHttpStatus')) {
            $status = $e->getHttpStatus();

            return is_int($status) ? $status : null;
        }

        if (isset($e->status) && is_int($e->status)) {
            return $e->status;
        }

        return null;
    }
}
