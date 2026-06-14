<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Attributes;

use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;

final class ExposeAsGraphqlTest extends TestCase
{
    public function test_attribute_is_autoloadable_under_canonical_namespace(): void
    {
        // Regression guard: the file used to live at src/Attribute/ (singular)
        // while declaring namespace Semitexa\Graphql\Attributes (plural). PSR-4
        // could not resolve that and class_exists() returned false. Moving it
        // to src/Attributes/ fixed the autoload contract.
        self::assertTrue(class_exists(ExposeAsGraphql::class));
    }

    public function test_attribute_constructor_captures_all_fields(): void
    {
        $attr = new ExposeAsGraphql(
            field: 'productBySlug',
            rootType: 'query',
            output: \stdClass::class,
            description: 'fetch a product',
        );

        self::assertSame('productBySlug', $attr->field);
        self::assertSame('query', $attr->rootType);
        self::assertSame(\stdClass::class, $attr->output);
        self::assertSame('fetch a product', $attr->description);
    }

    public function test_attribute_defaults_root_type_to_null_for_derivation(): void
    {
        $attr = new ExposeAsGraphql(field: 'foo');

        // null rootType signals "derive from the route's HTTP method" — the
        // registry resolves it; the attribute itself pins nothing.
        self::assertNull($attr->rootType);
        self::assertNull($attr->output);
        self::assertSame('', $attr->description);
    }
}
