<?php

declare(strict_types=1);

namespace Pramnos\Application\Api;

/**
 * What a screen answered while it answered the API ({@see \Pramnos\Application\Controller::answerTheApi()}):
 * the data its view was given, where it would have sent the browser, or a refusal.
 *
 * Thrown by the screen and caught by {@see ScreenApi}, so the action's own code runs unchanged up
 * to the point where it would draw a page or redirect — and stops there.
 */
final class ScreenAnswer extends \RuntimeException
{
    /** The view's data: what the page would have drawn. */
    public const DATA = 'data';

    /** A redirect: the action did its work and would show another page. */
    public const REDIRECT = 'redirect';

    /** Refused: not signed in, or below the screen's floor. */
    public const REFUSED = 'refused';

    /**
     * @param string $kind    One of the constants
     * @param mixed  $payload The view's data, the address, or the refusal's words
     * @param int    $status  The HTTP status it answers with
     */
    public function __construct(public readonly string $kind, public readonly mixed $payload, int $status = 200)
    {
        parent::__construct($kind, $status);
    }
}
