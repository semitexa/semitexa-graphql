<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Authorization\Domain\Contract\CapabilityInterface;

enum AccessCapabilityFixture: string implements CapabilityInterface
{
    case Audit = 'fixture.audit';
}
