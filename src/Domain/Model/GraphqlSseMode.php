<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Model;

/**
 * The admission mode for the SSE-subscription branch of `POST /graphql` (PHASE 3).
 *
 * A single endpoint that can hold a socket open is a security decision, so the
 * mode is operator-selected via the `SEMITEXA_GRAPHQL_SSE_MODE` env key (read
 * through `Environment::getEnvValue()`). This enum is the canonical home of the
 * three mode values AND of the resolution policy:
 *
 *   - key absent / empty   → {@see self::AuthenticatedOnly} — the documented
 *     safe default: an operator who never touched the key gets an auth-gated
 *     stream, not an open one.
 *   - unrecognised value   → fail SAFE to the MOST restrictive,
 *     {@see self::Disabled} (never fall open to `everyone`). The caller is
 *     expected to emit a warning so the misconfiguration is visible.
 *
 * The gate this feeds is a TRANSPORT/SECURITY decision evaluated before the
 * stream opens — it does not touch GraphQL semantics, and the no-SSE-header /
 * query / mutation one-shot paths never consult it.
 */
enum GraphqlSseMode: string
{
    case Disabled = 'disabled';
    case AuthenticatedOnly = 'authenticated-only';
    case Everyone = 'everyone';

    /** The operator-facing env key (documented in `.env.default`). */
    public const ENV_KEY = 'SEMITEXA_GRAPHQL_SSE_MODE';

    /**
     * Resolve a raw env value to a mode, side-effect free (unit-testable).
     * `null` / blank → the safe default; an unrecognised value → the most
     * restrictive mode. Use {@see self::isRecognized()} to detect the latter
     * for warning emission.
     */
    public static function fromEnvValue(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return self::AuthenticatedOnly;
        }

        return self::tryFrom(strtolower(trim($raw))) ?? self::Disabled;
    }

    /** Whether a raw env value names a real mode (absent/blank counts as recognised — it is the documented default). */
    public static function isRecognized(?string $raw): bool
    {
        if ($raw === null || trim($raw) === '') {
            return true;
        }

        return self::tryFrom(strtolower(trim($raw))) !== null;
    }

    /**
     * The admit decision for an SSE-subscription request under this mode.
     * `$authenticated` is the request's resolved authenticated-vs-guest state
     * (the same `AuthContextInterface` answer the framework auth pipeline
     * produced for this request).
     */
    public function admits(bool $authenticated): bool
    {
        return match ($this) {
            self::Disabled => false,
            self::AuthenticatedOnly => $authenticated,
            self::Everyone => true,
        };
    }
}
