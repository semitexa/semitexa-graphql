<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Attribute;

/** A requirement attribute no listener knows: the gate must refuse it, not skip it. */
#[Attribute(Attribute::TARGET_CLASS)]
final class RequiresMoonPhaseFixture
{
}
