<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Discovery;

use InvalidArgumentException;

/**
 * Derives a GraphQL field name from a Payload class name.
 *
 * `#[ExposeAsGraphql]` no longer requires `field:` in the common case: the field
 * is the operation's identity, and the operation IS the Payload, so the name can
 * come from the Payload class name by convention. `field:` survives only as an
 * explicit override for the cases where the convention would lie (e.g.
 * `CustomerByIdQueryPayload` exposed as `customer`, not `customerById`).
 *
 * Convention (applied to the SHORT class name, after the last `\`):
 *   1. strip a trailing `Payload`
 *   2. strip ONE trailing root-type token: `Query` | `Mutation` | `Subscription`
 *   3. lower-case the first character (lowerCamelCase)
 *
 * Examples:
 *   ArticleByIdQueryPayload      -> articleById
 *   CreateArticleMutationPayload -> createArticle
 *   DeleteArticleMutationPayload -> deleteArticle
 *
 * A class whose name collapses to nothing under the rules (e.g. `QueryPayload`)
 * is a misconfiguration: the deriver throws so the author declares `field:`
 * explicitly rather than registering a blank, un-queryable schema field.
 */
final class GraphqlFieldNameDeriver
{
    /** Root-type tokens stripped (after `Payload`) so the name reads as the operation. */
    private const ROOT_TYPE_SUFFIXES = ['Subscription', 'Mutation', 'Query'];

    public static function derive(string $payloadClass): string
    {
        $short = self::shortName($payloadClass);

        $base = self::stripSuffix($short, 'Payload');

        foreach (self::ROOT_TYPE_SUFFIXES as $token) {
            $stripped = self::stripSuffix($base, $token);
            if ($stripped !== $base) {
                $base = $stripped;
                break;
            }
        }

        if ($base === '') {
            throw new InvalidArgumentException(sprintf(
                'Cannot derive a GraphQL field name from "%s": the name collapses to empty '
                . 'after stripping the Payload/root-type suffixes. Declare field: explicitly '
                . 'on #[ExposeAsGraphql].',
                $payloadClass,
            ));
        }

        return lcfirst($base);
    }

    private static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Remove $suffix from the end of $value when it is a trailing suffix. A name
     * that IS exactly the suffix collapses to '' on purpose: that lets the
     * empty-name guard in {@see self::derive()} reject degenerate class names
     * like `QueryPayload`/`Payload` loudly instead of minting a bogus field.
     */
    private static function stripSuffix(string $value, string $suffix): string
    {
        if ($suffix === '' || !str_ends_with($value, $suffix)) {
            return $value;
        }

        return substr($value, 0, strlen($value) - strlen($suffix));
    }
}
