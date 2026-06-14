<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Domain\Model\GraphqlSseMode;

/**
 * The PHASE 3 mode-resolution policy in isolation: the safe default (absent
 * key → authenticated-only), the fail-safe (unrecognised → disabled, never
 * open), and the per-mode admit matrix the streamer gate enforces.
 */
final class GraphqlSseModeTest extends TestCase
{
    public function test_absent_or_blank_value_defaults_to_authenticated_only(): void
    {
        self::assertSame(GraphqlSseMode::AuthenticatedOnly, GraphqlSseMode::fromEnvValue(null));
        self::assertSame(GraphqlSseMode::AuthenticatedOnly, GraphqlSseMode::fromEnvValue(''));
        self::assertSame(GraphqlSseMode::AuthenticatedOnly, GraphqlSseMode::fromEnvValue('   '));
    }

    public function test_recognised_values_resolve_with_trim_and_case_folding(): void
    {
        self::assertSame(GraphqlSseMode::Disabled, GraphqlSseMode::fromEnvValue('disabled'));
        self::assertSame(GraphqlSseMode::AuthenticatedOnly, GraphqlSseMode::fromEnvValue('authenticated-only'));
        self::assertSame(GraphqlSseMode::Everyone, GraphqlSseMode::fromEnvValue('everyone'));
        self::assertSame(GraphqlSseMode::Everyone, GraphqlSseMode::fromEnvValue('  EVERYONE  '));
        self::assertSame(GraphqlSseMode::Disabled, GraphqlSseMode::fromEnvValue('Disabled'));
    }

    public function test_unrecognised_value_fails_safe_to_disabled_never_open(): void
    {
        self::assertSame(GraphqlSseMode::Disabled, GraphqlSseMode::fromEnvValue('open'));
        self::assertSame(GraphqlSseMode::Disabled, GraphqlSseMode::fromEnvValue('authenticated'));
        self::assertSame(GraphqlSseMode::Disabled, GraphqlSseMode::fromEnvValue('everyone-else'));
        self::assertSame(GraphqlSseMode::Disabled, GraphqlSseMode::fromEnvValue('true'));
    }

    public function test_is_recognized_flags_only_real_typos(): void
    {
        // Absent/blank is the documented default, NOT a misconfiguration.
        self::assertTrue(GraphqlSseMode::isRecognized(null));
        self::assertTrue(GraphqlSseMode::isRecognized(''));
        self::assertTrue(GraphqlSseMode::isRecognized('disabled'));
        self::assertTrue(GraphqlSseMode::isRecognized(' Everyone '));
        self::assertFalse(GraphqlSseMode::isRecognized('enabled'));
        self::assertFalse(GraphqlSseMode::isRecognized('auth'));
    }

    public function test_admit_matrix(): void
    {
        // disabled → refuse any caller.
        self::assertFalse(GraphqlSseMode::Disabled->admits(true));
        self::assertFalse(GraphqlSseMode::Disabled->admits(false));
        // authenticated-only → admit exactly the authenticated caller.
        self::assertTrue(GraphqlSseMode::AuthenticatedOnly->admits(true));
        self::assertFalse(GraphqlSseMode::AuthenticatedOnly->admits(false));
        // everyone → admit any caller.
        self::assertTrue(GraphqlSseMode::Everyone->admits(true));
        self::assertTrue(GraphqlSseMode::Everyone->admits(false));
    }
}
