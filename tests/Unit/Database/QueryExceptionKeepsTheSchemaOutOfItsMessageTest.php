<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\QueryException;

/**
 * A customer read their own database schema out of an HTTP 400.
 *
 * `Model::_save()` threw the driver's message as a plain `\Exception`. On PostgreSQL a
 * unique-constraint violation names the table, every column in the statement, the index that
 * was violated and the value that violated it. An authorization server's token endpoint had
 * a catch-all that answered `error_description` with the exception message — correctly, most
 * of the time, because that catch is also where the endpoint raises its own refusals, the
 * ones whose message *is* the response.
 *
 * So the constraint violation went out the same way, and the reply came back:
 * «μάλιστα, μου γυρνάς και την SQL σου».
 *
 * A consumer could not fix it cleanly. `\Exception` is what a hand-written refusal throws
 * *and* what a failed insert throws, so `catch` cannot separate them; masking the catch-all
 * turned *Unsupported grant type* into *something went wrong*. What shipped there was a
 * heuristic on the message text — a guess about text in front of a decision about trust.
 *
 * Two things are asserted here: that the type says what happened, and that
 * `getMessage()` can be printed while the detail is still available to whoever logs.
 */
#[CoversClass(QueryException::class)]
class QueryExceptionKeepsTheSchemaOutOfItsMessageTest extends TestCase
{
    /** The driver text from the report, near enough. */
    private const DRIVER_TEXT = <<<'TEXT'
        0:ERROR:  duplicate key value violates unique constraint "idx_usertokens_token_lookup"
        DETAIL:  Key (token_lookup)=(35153ba8) already exists.
        TEXT;

    private const SQL = 'INSERT INTO public.usertokens ("userid", "token_lookup") VALUES ($1, $2)';

    /**
     * The driver's text is not the message.
     *
     * The single assertion the report reduces to: whatever a consumer prints, it is not the
     * schema.
     */
    public function testTheDriverTextIsNotTheMessage(): void
    {
        // Arrange & Act
        $exception = new QueryException(self::DRIVER_TEXT, self::SQL);

        // Assert
        $this->assertStringNotContainsString('usertokens', $exception->getMessage());
        $this->assertStringNotContainsString('idx_usertokens', $exception->getMessage());
        $this->assertStringNotContainsString('token_lookup', $exception->getMessage());
        $this->assertStringNotContainsString('INSERT', $exception->getMessage());
        $this->assertSame(QueryException::SAFE_MESSAGE, $exception->getMessage());
    }

    /**
     * Nor is the SQL, which is the half that carries the values.
     *
     * `getPaginated()` put `$qb->toSql()` straight into an exception message, so anything
     * that rendered the exception rendered the statement — and a `WHERE` clause is made of
     * whatever the request asked for.
     */
    public function testTheSqlIsNotTheMessageEither(): void
    {
        // Arrange & Act
        $exception = new QueryException('driver text', self::SQL);

        // Assert
        $this->assertStringNotContainsString('usertokens', $exception->getMessage());
        $this->assertSame(self::SQL, $exception->getQuery());
    }

    /**
     * And the detail is not lost — only moved somewhere printing it is deliberate.
     *
     * This is the half that makes the change acceptable: an operator diagnosing a failed
     * save needs the index name and the colliding value. Discarding them would trade one
     * unusable state for another.
     */
    public function testTheDetailIsStillThereForWhoeverLogs(): void
    {
        // Arrange & Act
        $exception = new QueryException(self::DRIVER_TEXT, self::SQL);

        // Assert
        $this->assertStringContainsString('idx_usertokens_token_lookup', $exception->getDriverMessage());
        $this->assertStringContainsString('idx_usertokens_token_lookup', $exception->getDetail());
        $this->assertStringContainsString('INSERT INTO public.usertokens', $exception->getDetail());
    }

    /**
     * A call site that can say something useful without saying what, does.
     *
     * «Could not save Foo» is worth more to whoever sees it than «the database refused the
     * query», and it names a class rather than a table — the difference between what an
     * application calls a thing and what its schema does.
     */
    public function testACallSiteCanSupplyItsOwnSafeMessage(): void
    {
        // Arrange & Act
        $exception = new QueryException(
            self::DRIVER_TEXT,
            self::SQL,
            null,
            'Could not save App\\Models\\Token.'
        );

        // Assert
        $this->assertSame('Could not save App\\Models\\Token.', $exception->getMessage());
        $this->assertStringContainsString('idx_usertokens', $exception->getDriverMessage());
    }

    /**
     * An empty safe message falls back to the default rather than to nothing.
     *
     * An exception whose `getMessage()` is `''` renders as a blank error, which reads as the
     * framework having failed to say anything — and that is how a swallowed error looks.
     */
    public function testAnEmptySafeMessageFallsBackToTheDefault(): void
    {
        // Arrange & Act
        $exception = new QueryException('driver text', '', null, '');

        // Assert
        $this->assertSame(QueryException::SAFE_MESSAGE, $exception->getMessage());
    }

    /**
     * It is a `RuntimeException`, so an existing `catch (\Exception)` still catches it.
     *
     * The change is what the message says, not whether it is caught: an application that
     * handled the old plain `\Exception` keeps handling this, and gains the ability to
     * separate it.
     */
    public function testItIsStillCatchableAsAnException(): void
    {
        // Arrange & Act
        $exception = new QueryException('driver text');

        // Assert
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    /**
     * `getDetail()` copes with the halves that are missing.
     *
     * Not every throw site has the SQL — the insert path has the driver's message and no
     * statement, because `insertDataToTable()` builds it internally.
     *
     * @param string $driver   The driver's message
     * @param string $query    The SQL
     * @param array<string> $expected Fragments that must appear
     * @param array<string> $absent   Fragments that must not
     */
    #[DataProvider('detailCases')]
    public function testTheDetailIsBuiltFromWhateverIsThere(
        string $driver,
        string $query,
        array $expected,
        array $absent
    ): void {
        // Arrange & Act
        $detail = (new QueryException($driver, $query))->getDetail();

        // Assert
        foreach ($expected as $fragment) {
            $this->assertStringContainsString($fragment, $detail);
        }
        foreach ($absent as $fragment) {
            $this->assertStringNotContainsString($fragment, $detail);
        }
    }

    /**
     * @return array<string,array{string,string,array<string>,array<string>}>
     */
    public static function detailCases(): array
    {
        return [
            'both halves'   => ['driver said no', 'SELECT 1', ['driver said no', 'SQL: SELECT 1'], []],
            'driver only'   => ['driver said no', '', ['driver said no'], ['SQL:']],
            'query only'    => ['', 'SELECT 1', ['SQL: SELECT 1'], []],
            'neither'       => ['', '', [QueryException::SAFE_MESSAGE], ['SQL:']],
        ];
    }
}
