<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Runtime;

use Semitexa\Core\Exception\NotFoundException;

/**
 * Test-only Handler. Returns a deterministic RuntimeOutputFixture wrapped
 * inside the Resource's `data` key — same convention demo handlers follow.
 *
 * Throws NotFoundException when the payload's id is `missing` so error
 * mapping can be exercised end-to-end.
 */
final class RuntimeHandlerFixture
{
    public function handle(RuntimePayloadFixture $payload, RuntimeResourceFixture $resource): RuntimeResourceFixture
    {
        if ($payload->getId() === 'missing') {
            throw new NotFoundException('RuntimeFixture', $payload->getId());
        }
        $output = new RuntimeOutputFixture(
            id: $payload->getId(),
            name: 'name-' . $payload->getId(),
            count: $payload->getLimit(),
            enabled: true,
            score: 0.5,
        );
        return $resource->with('data', $output);
    }
}
