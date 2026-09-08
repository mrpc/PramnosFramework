<?php

namespace Pramnos\Database;

/**
 * Thrown when the database refuses a query.
 *
 * The framework's historical default is fail-soft: a failing query is logged and returns
 * false, so callers that do not inspect the return value proceed as if it succeeded. Strict
 * mode (`throwOnError`) turns those failures into exceptions so bugs surface instead of
 * hiding, and {@see \Pramnos\Application\Model::_save()} raises one unconditionally,
 * because a save that did not save has no fail-soft reading.
 *
 * ## `getMessage()` is safe to print. The driver's text is not.
 *
 * This is the whole point of the class, and it was the other way round.
 *
 * The driver's message names the table, every column in the statement, the index that was
 * violated and the value that violated it. On PostgreSQL a duplicate key reads:
 *
 * ```
 * 0:ERROR:  duplicate key value violates unique constraint "idx_usertokens_token_lookup"
 * DETAIL:  Key (token_lookup)=(35153ba8…) already exists.
 * ::: SQL QUERY:
 * INSERT INTO public.usertokens ("userid", "tokentype", … ) VALUES ($1, … $21) RETURNING tokenid
 * ```
 *
 * That was `getMessage()`, and an authorization server answered it to an API client as an
 * OAuth `error_description` — because the same catch is where that endpoint raises its own
 * refusals, the ones whose message *is* the response. The customer read their own schema out
 * of an HTTP 400 and sent it back: «μάλιστα, μου γυρνάς και την SQL σου».
 *
 * A consumer could not fix that cleanly. `Model::_save()` threw a plain `\Exception`, which
 * is also what a hand-written refusal throws, so `catch` could not tell them apart; masking
 * the catch-all turned *Unsupported grant type* into *something went wrong*. What shipped
 * there was a heuristic on the message text — a guess about text in front of a decision
 * about trust.
 *
 * So: a short `getMessage()` that may be surfaced, and {@see getDriverMessage()} and
 * {@see getQuery()} for whoever is writing to a log. The detail is not discarded; it is put
 * where printing it has to be deliberate.
 *
 * ```php
 * catch (\Pramnos\Database\QueryException $exception) {
 *     Logger::log($exception->getDetail(), 'database');   // the whole thing
 *     return Response::json(['error' => $exception->getMessage()], 500);  // safe
 * }
 * ```
 */
class QueryException extends \RuntimeException
{
    /**
     * What `getMessage()` says when the caller supplies nothing better.
     *
     * Deliberately without a cause: "duplicate key" is itself information about the schema,
     * and the caller that wants to distinguish conditions has the driver text.
     */
    public const SAFE_MESSAGE = 'The database refused the query.';

    /** The SQL that failed. Never safe to print — it carries the values. */
    protected string $query;

    /** The driver's own text. Never safe to print — it carries the schema. */
    protected string $driverMessage;

    /**
     * @param string      $error    The driver's message, or whatever detail there is
     * @param string      $query    The SQL that failed
     * @param \Throwable  $previous The driver-level exception, when there was one
     * @param string      $safe     A short message that may be surfaced; the default names
     *                              nothing. Pass one when the call site can say something
     *                              useful without saying what — «Could not save Foo», say.
     */
    public function __construct(
        string $error,
        string $query = '',
        ?\Throwable $previous = null,
        string $safe = self::SAFE_MESSAGE
    ) {
        $this->query         = $query;
        $this->driverMessage = $error;

        parent::__construct($safe === '' ? self::SAFE_MESSAGE : $safe, 0, $previous);
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * The driver's own message — for a log, never for a response.
     */
    public function getDriverMessage(): string
    {
        return $this->driverMessage;
    }

    /**
     * Everything there is, in one string, for a log line.
     *
     * Its own method because the alternative is every caller concatenating three accessors
     * and one of them forgetting the query — which is the half that says *which* row.
     */
    public function getDetail(): string
    {
        $detail = $this->getMessage();

        if ($this->driverMessage !== '') {
            $detail .= ' ' . $this->driverMessage;
        }
        if ($this->query !== '') {
            $detail .= ' | SQL: ' . $this->query;
        }

        return $detail;
    }
}
