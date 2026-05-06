<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Graphql\Domain\Contract\ResourceSerializerInterface;

/**
 * Default GraphQL Resource → value projection.
 *
 * Convention: the same Resource the HTTP transport uses already carries the
 * shape clients expect (Semitexa Handlers populate it via `withX(...)` /
 * `setField(...)`). We extract that shape from the Resource's render context
 * with a single rule: prefer the `data` key when present (matches the JSON
 * resources in the demo), otherwise return the whole render-context array.
 *
 * Resources that aren't `ResourceResponse` (e.g. plain DTOs returned by some
 * future handler refactor) are passed through untouched. Webonyx then walks
 * the value against the field's declared output type using the field
 * resolvers in `OutputTypeRegistry`.
 */
#[SatisfiesServiceContract(of: ResourceSerializerInterface::class)]
final class RenderContextResourceSerializer implements ResourceSerializerInterface
{
    public function serialize(object $resource): mixed
    {
        if (!$resource instanceof ResourceResponse) {
            return $resource;
        }

        $context = $resource->getRenderContext();
        if (array_key_exists('data', $context)) {
            return $context['data'];
        }
        return $context === [] ? null : $context;
    }
}
