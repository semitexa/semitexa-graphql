<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Graphql\Application\Service\Runtime\RenderContextResourceSerializer;
use Semitexa\Graphql\Domain\Contract\GraphqlProjectionInterface;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeResourceFixture;

final class RenderContextResourceSerializerTest extends TestCase
{
    public function test_prefers_data_key_when_present(): void
    {
        $resource = (new RuntimeResourceFixture())->with('data', ['id' => 'a', 'name' => 'A']);

        $value = (new RenderContextResourceSerializer())->serialize($resource);

        self::assertSame(['id' => 'a', 'name' => 'A'], $value);
    }

    public function test_returns_full_render_context_when_no_data_key(): void
    {
        $resource = (new RuntimeResourceFixture())
            ->with('count', 7)
            ->with('flag', true);

        $value = (new RenderContextResourceSerializer())->serialize($resource);

        self::assertSame(['count' => 7, 'flag' => true], $value);
    }

    public function test_empty_render_context_returns_null(): void
    {
        $resource = new RuntimeResourceFixture();

        $value = (new RenderContextResourceSerializer())->serialize($resource);

        self::assertNull($value);
    }

    public function test_passes_through_non_resource_objects(): void
    {
        $output = new RuntimeOutputFixture(id: 'a', name: 'A', count: 1, enabled: true);

        $value = (new RenderContextResourceSerializer())->serialize($output);

        self::assertSame($output, $value);
    }

    /**
     * A REST resource writes its body with setContent() and keeps the render
     * context empty — a non-empty one makes the HTTP renderer replace the body.
     * Before the projection contract such a field always resolved to null.
     */
    public function test_a_resource_that_states_its_projection_is_asked_first(): void
    {
        $output = new RuntimeOutputFixture(id: 'a', name: 'A', count: 1, enabled: true);
        $resource = new class ($output) extends ResourceResponse implements GraphqlProjectionInterface {
            public function __construct(private readonly object $output)
            {
                parent::__construct();
                $this->setContent('{"data":[]}');
            }

            public function toGraphqlValue(): mixed
            {
                return $this->output;
            }
        };

        self::assertSame($output, (new RenderContextResourceSerializer())->serialize($resource));
    }
}
