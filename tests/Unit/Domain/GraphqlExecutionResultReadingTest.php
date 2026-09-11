<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Domain\Exception\UnexpectedGraphqlResultException;
use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;

/**
 * Reading a GraphQL result without descending into mixed.
 *
 * A GraphQL response is whatever shape the query asked for, so the type ends at
 * the first key and every caller indexed blindly from there. These cases pin the
 * half that makes the accessors worth having: they END in a type, and they fail
 * by NAMING the path rather than by returning null that compares equal to an
 * expectation or by an offset-on-mixed several frames away.
 */
final class GraphqlExecutionResultReadingTest extends TestCase
{
    private function fixture(): GraphqlExecutionResult
    {
        return new GraphqlExecutionResult(
            data: [
                'articles' => [
                    ['id' => 'gql_00001', 'title' => 'First', 'published' => true, 'views' => 12],
                    ['id' => 'gql_00002', 'title' => 'Second', 'published' => false, 'views' => 0],
                ],
                'articleById' => ['id' => 'gql_00001', 'title' => 'First'],
                'deleted' => null,
            ],
            errors: [],
        );
    }

    #[Test]
    public function a_path_ends_in_a_type(): void
    {
        $r = $this->fixture();

        self::assertSame('gql_00001', $r->string('articles.0.id'));
        self::assertSame(12, $r->int('articles.0.views'));
        self::assertTrue($r->bool('articles.0.published'));
        self::assertSame(['id' => 'gql_00001', 'title' => 'First'], $r->map('articleById'));
        self::assertCount(2, $r->list('articles'));
    }

    /**
     * The failure that matters most: a field the query never selected used to
     * read as null and compare equal to a null expectation. Now it says which
     * path, and what was actually there to choose from.
     */
    #[Test]
    public function a_field_that_is_not_there_names_itself_and_its_neighbours(): void
    {
        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('Cannot read "articles.0.slug": nothing at "articles.0.slug".');

        $this->fixture()->string('articles.0.slug');
    }

    #[Test]
    public function the_message_lists_what_was_actually_present(): void
    {
        try {
            $this->fixture()->string('articles.0.slug');
            self::fail('expected the read to be refused');
        } catch (UnexpectedGraphqlResultException $e) {
            self::assertStringContainsString('Present: id, title, published, views.', $e->getMessage());
        }
    }

    /** A present null is not an absent field, and the two must stay distinguishable. */
    #[Test]
    public function an_explicit_null_is_not_the_same_as_a_missing_field(): void
    {
        $r = $this->fixture();

        self::assertTrue($r->has('deleted'));
        self::assertTrue($r->isNull('deleted'));

        self::assertFalse($r->has('neverSelected'));
        self::assertFalse($r->isNull('neverSelected'), 'absent is not null');
    }

    #[Test]
    public function reading_the_wrong_type_says_which_type_it_found(): void
    {
        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('GraphQL result at "articles" must be a string, got a list of 2.');

        $this->fixture()->string('articles');
    }

    #[Test]
    public function descending_into_a_scalar_is_refused_rather_than_silently_null(): void
    {
        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('"articles.0.id.nope" is string, so nothing can be read out of it');

        $this->fixture()->string('articles.0.id.nope');
    }

    /** A list whose members are not objects fails here, not as a TypeError in the caller. */
    #[Test]
    public function a_list_of_scalars_is_refused_by_member(): void
    {
        $r = new GraphqlExecutionResult(data: ['tags' => ['a', 'b']], errors: []);

        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('GraphQL result at "tags.0" must be an object, got string.');

        $r->list('tags');
    }

    /**
     * A nested list is an array too, and a bare is_array() member check would
     * let it past as an object — handing the caller a value it cannot read
     * keys out of, which is the failure this class exists to replace.
     */
    #[Test]
    public function a_list_of_lists_is_refused_by_member(): void
    {
        $r = new GraphqlExecutionResult(data: ['rows' => [['id']]], errors: []);

        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('GraphQL result at "rows.0" must be an object, got a list of 1.');

        $r->list('rows');
    }

    /** An empty list is a list, and reading it must not be an error. */
    #[Test]
    public function an_empty_collection_reads_as_an_empty_list(): void
    {
        $r = new GraphqlExecutionResult(data: ['articles' => []], errors: []);

        self::assertSame([], $r->list('articles'));
        self::assertSame([], $r->map('articles'), 'an empty selection is ambiguous and both readings are legitimate');
    }

    /**
     * Execution that produced no value at all sends the reader to the errors,
     * rather than reporting a missing field that was never going to be there.
     */
    #[Test]
    public function reading_a_result_that_never_executed_points_at_the_errors(): void
    {
        $r = new GraphqlExecutionResult(
            data: null,
            errors: [['message' => 'Syntax Error', 'extensions' => ['code' => 'GRAPHQL_PARSE_FAILED']]],
        );

        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('the result carries no data');

        $r->string('articles.0.id');
    }

    #[Test]
    public function the_error_code_is_what_assertions_are_about(): void
    {
        $r = new GraphqlExecutionResult(
            data: null,
            errors: [['message' => 'Not found', 'extensions' => ['code' => 'NOT_FOUND']]],
        );

        self::assertSame('NOT_FOUND', $r->errorCode());
        self::assertSame('Not found', $r->error()['message']);
    }

    #[Test]
    public function asking_for_an_error_that_is_not_there_says_how_many_there_are(): void
    {
        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('no error at index 2 (0 error(s) present)');

        $this->fixture()->errorCode(2);
    }

    /**
     * A present null is not the same fact as a missing index.
     *
     * `errors[0] => null` means the server sent an entry and it is empty;
     * "no error at index 0" would send the reader looking for a query that
     * never ran. `??` cannot tell the two apart, which is why it is not used.
     */
    #[Test]
    public function an_error_entry_that_is_present_and_null_is_not_reported_as_absent(): void
    {
        $r = new GraphqlExecutionResult(data: null, errors: [null]);

        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('GraphQL result at "errors.0" must be an object, got null.');

        $r->error();
    }

    /**
     * `errors` is a decoded response, so its element type is a docblock and not
     * an enforced one. A server answering `errors: ["boom"]` must fail here,
     * naming the entry — not as a TypeError on an `array` return type.
     */
    #[Test]
    public function an_error_entry_that_is_not_an_object_is_refused(): void
    {
        $r = new GraphqlExecutionResult(data: null, errors: ['boom']);

        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('GraphQL result at "errors.0" must be an object, got string.');

        $r->error();
    }

    /** An error without our envelope is a mapper defect, and says so as a type error. */
    #[Test]
    public function an_error_carrying_no_code_is_refused(): void
    {
        $r = new GraphqlExecutionResult(data: null, errors: [['message' => 'bare']]);

        $this->expectException(UnexpectedGraphqlResultException::class);
        $this->expectExceptionMessage('errors.0.extensions.code');

        $r->errorCode();
    }
}
