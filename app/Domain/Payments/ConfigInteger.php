<?php

namespace App\Domain\Payments;

/**
 * Strict, fail-closed integer parsing for payments-domain configuration
 * values (retention days, health thresholds, ...) that must never be
 * silently coerced by a bare `(int)` cast — `(int) 'abc'` and `(int) ''`
 * are both `0`, which for a retention/threshold value doesn't mean "invalid
 * input", it means "prune/flag everything immediately," a dangerously wrong
 * default to reach by accident. Every caller in this domain that reads a
 * configurable integer (App\Console\Commands\PrunePaymentProviderEvents,
 * App\Domain\Payments\Services\PaymentsHealthCheck) goes through this same
 * rule so "what counts as a valid value" can't drift between them.
 *
 * Deliberately just the parsing rule, not the failure behavior: a console
 * command wants to print an error and refuse to run (return
 * Command::INVALID); a report generator wants to throw so it can never
 * silently compute a misleading result against a wrong default. Both build
 * their own message/handling around parse()'s null; this class only ever
 * answers "does this value actually parse," never how to react to it not
 * parsing.
 */
final class ConfigInteger
{
    /**
     * Accepts only a value that is *exactly* an integer >= $min.
     * `FILTER_VALIDATE_INT` rejects '', 'abc', and '3.5' outright (returns
     * `false`, not a truncated/rounded guess); an otherwise-well-formed
     * integer below `$min` is then rejected explicitly. Never coerces —
     * either the value parses cleanly as >= $min, or this returns `null`.
     */
    public static function parse(mixed $value, int $min = 0): ?int
    {
        $normalized = is_int($value) ? (string) $value : $value;

        $filtered = is_string($normalized) ? filter_var($normalized, FILTER_VALIDATE_INT) : false;

        if ($filtered === false || $filtered < $min) {
            return null;
        }

        return $filtered;
    }

    /** A value's original, printable form for an error message — never the parsed/coerced result. */
    public static function printable(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
