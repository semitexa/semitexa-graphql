<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/** A signed-in user who holds the permission reports.read. */
#[AsProtectedPayload(path: '/access/permission/{id}', methods: ['GET'])]
#[RequiresPermission('reports.read')]
#[ExposeAsGraphql(field: 'permissionThing', rootType: 'query', output: RuntimeOutputFixture::class)]
#[ExposeAsGraphql(field: 'permissionThingChanges', rootType: 'subscription', output: RuntimeOutputFixture::class, watchScopes: ['permission_things'])]
final class PermissionPayloadFixture
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
