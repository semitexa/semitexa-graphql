<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Domain;

use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Domain\Model\GraphqlValidationLimits;

/**
 * The `/graphql` DoS guard. webonyx ships depth/complexity uncapped and
 * introspection open, so an unauthenticated nested query over relation cycles
 * fans out into a worker-stalling avalanche. These caps must reject an abusive
 * query at VALIDATION time, before any resolver/DB work — proven here against
 * webonyx's real DocumentValidator on a self-referential schema.
 */
final class GraphqlValidationLimitsTest extends TestCase
{
    private string $depthBefore = '';
    private string $introBefore = '';
    private string $appEnvBefore = '';

    protected function setUp(): void
    {
        $this->depthBefore = (string) getenv('SEMITEXA_GRAPHQL_MAX_DEPTH');
        $this->introBefore = (string) getenv('SEMITEXA_GRAPHQL_INTROSPECTION');
        $this->appEnvBefore = (string) getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        foreach (['SEMITEXA_GRAPHQL_MAX_DEPTH', 'SEMITEXA_GRAPHQL_INTROSPECTION', 'APP_ENV'] as $k) {
            putenv($k);
        }
        if ($this->appEnvBefore !== '') {
            putenv('APP_ENV=' . $this->appEnvBefore);
        }
    }

    #[Test]
    public function defaults_are_safe_when_no_env_is_set(): void
    {
        putenv('SEMITEXA_GRAPHQL_MAX_DEPTH');
        putenv('SEMITEXA_GRAPHQL_MAX_COMPLEXITY');

        $limits = GraphqlValidationLimits::fromEnvironment();

        self::assertSame(GraphqlValidationLimits::DEFAULT_MAX_DEPTH, $limits->maxDepth);
        self::assertSame(GraphqlValidationLimits::DEFAULT_MAX_COMPLEXITY, $limits->maxComplexity);
    }

    #[Test]
    public function introspection_is_off_in_production_and_on_elsewhere(): void
    {
        putenv('SEMITEXA_GRAPHQL_INTROSPECTION'); // no explicit override

        putenv('APP_ENV=prod');
        self::assertFalse(GraphqlValidationLimits::fromEnvironment()->introspectionEnabled);

        putenv('APP_ENV=dev');
        self::assertTrue(GraphqlValidationLimits::fromEnvironment()->introspectionEnabled);

        // Explicit override wins over the environment.
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_GRAPHQL_INTROSPECTION=on');
        self::assertTrue(GraphqlValidationLimits::fromEnvironment()->introspectionEnabled);
    }

    #[Test]
    public function a_query_deeper_than_the_cap_is_rejected_at_validation(): void
    {
        $limits = new GraphqlValidationLimits(maxDepth: 3, maxComplexity: 1000, introspectionEnabled: true);

        $errors = $this->validate($this->nestedQuery(6), $limits);

        self::assertNotSame([], $errors, 'A too-deep query must be rejected.');
        self::assertStringContainsStringIgnoringCase('depth', $errors[0]->getMessage());
    }

    #[Test]
    public function a_query_within_the_cap_passes_validation(): void
    {
        $limits = new GraphqlValidationLimits(maxDepth: 10, maxComplexity: 1000, introspectionEnabled: true);

        self::assertSame([], $this->validate($this->nestedQuery(4), $limits));
    }

    #[Test]
    public function the_rule_set_carries_the_configured_security_rules(): void
    {
        $rules = (new GraphqlValidationLimits(5, 200, false))->validationRules();

        self::assertInstanceOf(QueryDepth::class, $rules[QueryDepth::class]);
        self::assertInstanceOf(QueryComplexity::class, $rules[QueryComplexity::class]);
        self::assertInstanceOf(DisableIntrospection::class, $rules[DisableIntrospection::class] ?? null);
    }

    /** @return list<\GraphQL\Error\Error> */
    private function validate(string $query, GraphqlValidationLimits $limits): array
    {
        return DocumentValidator::validate($this->schema(), Parser::parse($query), $limits->validationRules());
    }

    /** A self-referential schema: Node { id, child: Node } — a relation cycle. */
    private function schema(): Schema
    {
        $node = null;
        $node = new ObjectType([
            'name' => 'Node',
            'fields' => static function () use (&$node): array {
                return [
                    'id' => ['type' => Type::string()],
                    'child' => ['type' => $node],
                ];
            },
        ]);

        return new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => ['root' => ['type' => $node]],
            ]),
        ]);
    }

    private function nestedQuery(int $depth): string
    {
        $inner = 'id';
        for ($i = 0; $i < $depth; $i++) {
            $inner = "child { {$inner} }";
        }

        return "query { root { {$inner} } }";
    }
}
