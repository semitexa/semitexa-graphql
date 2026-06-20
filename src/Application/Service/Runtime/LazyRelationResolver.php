<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Throwable;

/**
 * Lazily loads a single relation for a single parent, on demand, through the
 * relation's declared `#[ResolveWith]` resolver (a
 * {@see RelationResolverInterface}).
 *
 * This is the GraphQL counterpart to `ResourceExpansionPipeline`: where the
 * REST pipeline expands every requested include once per response over the
 * canonical DTO, GraphQL resolves field-by-field. webonyx invokes a relation
 * field's resolver ONLY when the query selects it, so selection is handled
 * natively — there is no `?include=` token to translate. Each selected
 * relation dispatches one `resolveBatch()` with a single-parent bucket (the
 * contract explicitly routes single parents through the same path). Batching
 * across list siblings is a later optimisation (today: one call per parent).
 *
 * The resolver returns raw `ResourceObjectInterface` DTO(s); webonyx then walks
 * them against the nested GraphQL type whose field resolvers read the DTO's
 * public properties — so no intermediate array projection is needed.
 */
#[AsService]
final class LazyRelationResolver
{
    private ?ContainerInterface $containerOverride = null;

    /**
     * @param string $resolverClass the relation's #[ResolveWith] resolver class
     * @param string $parentType    the parent Resource's canonical type
     * @param string $parentId      the parent's identifier value
     * @param bool   $isList        true for to-many relations
     *
     * @return mixed a DTO (to-one), a list of DTOs (to-many), null/[] when
     *               nothing resolves
     */
    public function resolve(string $resolverClass, string $parentType, string $parentId, bool $isList): mixed
    {
        $identity = ResourceIdentity::of($parentType, $parentId);
        $value = $this->loadMany($resolverClass, [$identity])[$identity->urn()] ?? null;

        return $value ?? ($isList ? [] : null);
    }

    /**
     * Resolve a relation for MANY parents in one batched call — the seam
     * {@see RelationBatchLoader} uses to collapse list-sibling N+1.
     *
     * @param list<ResourceIdentity> $identities parent identities sharing the resolver
     *
     * @return array<string, mixed> raw resolver output keyed by `ResourceIdentity::urn()`;
     *                               missing keys mean "no related entity" and are left out
     */
    public function loadMany(string $resolverClass, array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $container = $this->container();
        if ($container === null || !$container->has($resolverClass)) {
            return [];
        }

        $context = new RenderContext(RenderProfile::GraphQL, IncludeSet::empty());

        try {
            // get() can throw even when has() returned true (a failing factory),
            // so it lives inside the fail-open guard alongside resolveBatch — a
            // lookup failure degrades this relation, never the whole response.
            $resolver = $container->get($resolverClass);
            if (!$resolver instanceof RelationResolverInterface) {
                return [];
            }
            return $resolver->resolveBatch($identities, $context);
        } catch (Throwable) {
            // A resolver failure must not collapse the whole GraphQL response;
            // degrade these relations to absent and let other fields render.
            return [];
        }
    }

    /** @internal Test seam: bypass ContainerFactory in unit tests. */
    public function setContainerForTest(?ContainerInterface $container): void
    {
        $this->containerOverride = $container;
    }

    private function container(): ?ContainerInterface
    {
        if ($this->containerOverride !== null) {
            return $this->containerOverride;
        }
        try {
            return ContainerFactory::get();
        } catch (Throwable) {
            return null;
        }
    }
}
