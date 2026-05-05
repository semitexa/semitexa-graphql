<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\Exception\NegotiationFailedException;
use Semitexa\Core\Request;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;

/**
 * Architecture guard: the POST /graphql endpoint advertises only those
 * response media types that Semitexa's ResourceResponse rendering pipeline
 * can actually deliver for the GraphQL execution envelope `{ data, errors?,
 * extensions? }`.
 *
 * Pairs with {@see EndpointOwnershipTest}: that pins WHO owns the route;
 * this pins WHAT formats it advertises. The two assertions are
 * intentionally separate — ownership and content negotiation are
 * independent contracts.
 *
 * The list is kept narrow on purpose. Adding a media type to `produces`
 * without a matching renderer behaviour would falsely advertise capability
 * that the pipeline does not implement (see the `excluded_*` cases below).
 */
final class EndpointResponseFormatsTest extends TestCase
{
    /**
     * The full, canonical produces list. Pinned exactly so any silent
     * change forces a deliberate update of this test.
     *
     * @var list<string>
     */
    private const EXPECTED_PRODUCES = [
        'application/json',
        'application/xml',
        'text/plain',
    ];

    public function test_endpoint_produces_only_renderer_supported_response_formats(): void
    {
        /** @var AsPublicPayload $route */
        $route = (new ReflectionClass(GraphqlEndpointPayload::class))
            ->getAttributes(AsPublicPayload::class)[0]
            ->newInstance();

        self::assertSame(
            self::EXPECTED_PRODUCES,
            $route->produces,
            'GraphqlEndpointPayload::$produces must match the renderer-supported '
            . 'set exactly. Adding a MIME without a matching ResponseRenderer '
            . 'branch (renderJson / renderXml / renderText / renderLayout) '
            . 'would falsely advertise capability.',
        );
    }

    public function test_endpoint_consumes_only_application_json(): void
    {
        /** @var AsPublicPayload $route */
        $route = (new ReflectionClass(GraphqlEndpointPayload::class))
            ->getAttributes(AsPublicPayload::class)[0]
            ->newInstance();

        self::assertSame(
            ['application/json'],
            $route->consumes,
            'The GraphQL-over-HTTP spec defines only application/json request '
            . 'bodies. Other content types (XML, form-encoded, application/graphql) '
            . 'are intentionally not advertised on this endpoint.',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function declaredAcceptToFormatKey(): iterable
    {
        // Exact MIME → format key the negotiator routes to. Each pair is
        // a renderer that the route can actually invoke.
        yield 'application/json → json' => ['application/json', 'json'];
        yield 'application/xml → xml'   => ['application/xml',  'xml'];
        yield 'text/plain → txt'        => ['text/plain',       'txt'];
    }

    /**
     * @dataProvider declaredAcceptToFormatKey
     */
    public function test_each_declared_accept_negotiates_to_the_expected_renderer_format(
        string $acceptMime,
        string $expectedFormatKey,
    ): void {
        $request = new Request(
            method: 'POST',
            uri: '/graphql',
            headers: ['Accept' => $acceptMime],
            cookies: [],
            query: [],
            post: [],
            server: [],
        );

        $resolved = ContentNegotiator::negotiateResponseFormat(
            self::EXPECTED_PRODUCES,
            $request,
            'json',
        );

        self::assertSame($expectedFormatKey, $resolved);
    }

    public function test_default_format_is_json_when_accept_is_missing(): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/graphql',
            headers: [],
            cookies: [],
            query: [],
            post: [],
            server: [],
        );

        $resolved = ContentNegotiator::negotiateResponseFormat(
            self::EXPECTED_PRODUCES,
            $request,
            'json',
        );

        self::assertSame('json', $resolved, 'application/json is the canonical default for GraphQL.');
    }

    public function test_default_format_is_json_when_accept_is_wildcard(): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/graphql',
            headers: ['Accept' => '*/*'],
            cookies: [],
            query: [],
            post: [],
            server: [],
        );

        $resolved = ContentNegotiator::negotiateResponseFormat(
            self::EXPECTED_PRODUCES,
            $request,
            'json',
        );

        self::assertSame('json', $resolved);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function excludedMediaTypes(): iterable
    {
        // These are intentionally NOT in produces — see GraphqlEndpointPayload's
        // class docblock for per-MIME rationale. The negotiator must reject
        // them with NegotiationFailedException so a 406 reaches the client.
        yield 'application/ld+json (would relabel to application/json)' => ['application/ld+json'];
        yield 'application/graphql-response+json (Resource-DTO path only)' => ['application/graphql-response+json'];
        yield 'text/html (no template handle on this endpoint)' => ['text/html'];
        yield 'application/vnd.api+json (no renderer in framework)' => ['application/vnd.api+json'];
    }

    /**
     * @dataProvider excludedMediaTypes
     */
    public function test_excluded_media_types_fail_negotiation(string $acceptMime): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/graphql',
            headers: ['Accept' => $acceptMime],
            cookies: [],
            query: [],
            post: [],
            server: [],
        );

        $this->expectException(NegotiationFailedException::class);

        ContentNegotiator::negotiateResponseFormat(
            self::EXPECTED_PRODUCES,
            $request,
            'json',
        );
    }
}
