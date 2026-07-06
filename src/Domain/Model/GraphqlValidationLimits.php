<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Model;

use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Semitexa\Core\Environment;

/**
 * Security limits for the public `/graphql` endpoint.
 *
 * webonyx applies NO depth/complexity cap by default and leaves introspection
 * open, so an unauthenticated POST of a deeply nested query over relation
 * cycles (`a{ b{ a{ b{ … } } } }`) or hundreds of aliased list fields fans out
 * into an avalanche of resolveBatch() calls + DB reads on one long-lived
 * Swoole worker — a classic GraphQL DoS. This value object resolves the
 * caps from the environment (safe defaults) and builds the webonyx
 * validation-rule set that enforces them.
 *
 * Env keys (all optional):
 *   SEMITEXA_GRAPHQL_MAX_DEPTH        — default {@see DEFAULT_MAX_DEPTH}
 *   SEMITEXA_GRAPHQL_MAX_COMPLEXITY   — default {@see DEFAULT_MAX_COMPLEXITY}
 *   SEMITEXA_GRAPHQL_INTROSPECTION    — explicit on/off; when unset,
 *                                       introspection is OFF in production and
 *                                       ON elsewhere (dev tooling needs it).
 */
final readonly class GraphqlValidationLimits
{
    public const ENV_MAX_DEPTH = 'SEMITEXA_GRAPHQL_MAX_DEPTH';
    public const ENV_MAX_COMPLEXITY = 'SEMITEXA_GRAPHQL_MAX_COMPLEXITY';
    public const ENV_INTROSPECTION = 'SEMITEXA_GRAPHQL_INTROSPECTION';

    public const DEFAULT_MAX_DEPTH = 15;
    public const DEFAULT_MAX_COMPLEXITY = 1000;

    public function __construct(
        public int $maxDepth,
        public int $maxComplexity,
        public bool $introspectionEnabled,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $depth = (int) Environment::getEnvValue(self::ENV_MAX_DEPTH, (string) self::DEFAULT_MAX_DEPTH);
        $complexity = (int) Environment::getEnvValue(self::ENV_MAX_COMPLEXITY, (string) self::DEFAULT_MAX_COMPLEXITY);

        return new self(
            maxDepth: $depth > 0 ? $depth : self::DEFAULT_MAX_DEPTH,
            maxComplexity: $complexity > 0 ? $complexity : self::DEFAULT_MAX_COMPLEXITY,
            introspectionEnabled: self::resolveIntrospection(),
        );
    }

    /**
     * The webonyx validation-rule set with the security caps applied. Starts
     * from the full default set (keyed by rule class) and REPLACES the three
     * security rules — which webonyx ships disabled — with configured ones,
     * so every other correctness rule keeps running.
     *
     * @return array<class-string, \GraphQL\Validator\Rules\ValidationRule>
     */
    public function validationRules(): array
    {
        $rules = DocumentValidator::allRules();
        $rules[QueryDepth::class] = new QueryDepth($this->maxDepth);
        $rules[QueryComplexity::class] = new QueryComplexity($this->maxComplexity);

        if (!$this->introspectionEnabled) {
            $rules[DisableIntrospection::class] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }

        return $rules;
    }

    private static function resolveIntrospection(): bool
    {
        $explicit = Environment::getEnvValue(self::ENV_INTROSPECTION);
        if ($explicit !== null && trim($explicit) !== '') {
            return in_array(strtolower(trim($explicit)), ['1', 'true', 'yes', 'on'], true);
        }

        $env = strtolower(trim((string) Environment::getEnvValue('APP_ENV', 'prod')));

        return !($env === 'prod' || $env === 'production');
    }
}
