<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/** Only a signed-in user; exposed as a query and as a subscription. */
#[AsProtectedPayload(path: '/access/protected/{id}', methods: ['GET'])]
#[ExposeAsGraphql(field: 'protectedThing', rootType: 'query', output: RuntimeOutputFixture::class)]
#[ExposeAsGraphql(field: 'protectedThingChanges', rootType: 'subscription', output: RuntimeOutputFixture::class, watchScopes: ['protected_things'])]
final class ProtectedPayloadFixture
{
    private string $id = '';

    public function getId(): string { return $this->id; }

    public function setId(string $id): void
    {
        if ($id === '') {
            throw new ValidationException(['id' => ['id must not be empty']]);
        }
        $this->id = $id;
    }
}
