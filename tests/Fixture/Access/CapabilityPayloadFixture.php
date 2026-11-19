<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\RequiresCapability;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/** A signed-in user who holds the Audit capability. */
#[AsProtectedPayload(path: '/access/capability/{id}', methods: ['GET'])]
#[RequiresCapability(AccessCapabilityFixture::Audit)]
#[ExposeAsGraphql(field: 'capabilityThing', rootType: 'query', output: RuntimeOutputFixture::class)]
final class CapabilityPayloadFixture
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
