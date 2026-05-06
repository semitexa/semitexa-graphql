<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Runtime;

use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Tiny Resource that lets Runtime tests drive the Payload→Handler→Resource
 * → serializer chain without booting the SSR or HTML renderer pipelines.
 *
 * Carries arbitrary data via `with()` so a test can populate the render
 * context with either a single output object or a list.
 */
final class RuntimeResourceFixture extends ResourceResponse
{
    public function with(string $key, mixed $value): self
    {
        $context = $this->getRenderContext();
        $context[$key] = $value;
        $this->setRenderContext($context);
        return $this;
    }
}
