<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Application\Service\Runtime\RenderContextResourceSerializer;
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
}
