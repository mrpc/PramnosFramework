<?php

declare(strict_types=1);

namespace Pramnos\Http;

/**
 * Who this request is, when the answer must not come from a cookie.
 *
 * A website knows its visitor from a session. An API knows its caller from the
 * credential presented on the call, and from nothing else — that is what makes
 * it an API rather than a website with JSON. In an application serving both from
 * one origin the two share a session cookie, and the difference stops being
 * academic:
 *
 *  - a browser signed in to the website makes an API call with no token. If the
 *    API reads the session, the call is authenticated by a cookie the caller
 *    never meant as a credential — and `logout` cannot work, because destroying
 *    the token leaves the cookie answering for it;
 *  - the same in reverse: an API call authenticated as one user must not change
 *    who the browser's next page belongs to.
 *
 * So an API request **seals** its identity: it says who is calling — possibly
 * nobody — and that answer stands. {@see \Pramnos\User\User::getCurrentUser()}
 * consults this first and stops here when it is sealed, instead of falling
 * through to the session.
 *
 * Request-scoped by construction: PHP starts a new process state for every
 * request, so nothing here outlives the call it describes. {@see reset()} exists
 * for tests and for any worker that serves more than one request in a lifetime.
 */
final class RequestIdentity
{
    /**
     * The user this request authenticated as, if any.
     */
    private static ?object $user = null;

    /**
     * Has this request settled its identity?
     *
     * Sealed means "the question has been answered for this request" — including
     * when the answer is nobody. The distinction between sealed-and-anonymous
     * and never-asked is the whole point: only the first one must stop the
     * session being consulted.
     */
    private static bool $sealed = false;

    /**
     * What convinced the server, in the words a developer would use.
     *
     * `accessToken`, `password`, `session`, `userAuth` — or an empty string when
     * nothing did. It exists because "who" and "how" are different questions and
     * the second one is the one that explains a surprise: a call that worked
     * yesterday and 401s today usually changed how, not who.
     */
    private static string $via = '';

    /**
     * A credential this request *issued*, if it issued one.
     *
     * A login hands out a token and is itself authenticated by a password, so
     * the token it just minted is not in any header and would otherwise be
     * invisible until the next call. Held here so the debug toolbar can describe
     * it — its claims and its expiry, never its value — at the moment it is
     * created, which is the moment somebody wants to know how long they have.
     *
     * Nothing else reads this: it is not a credential store, and the value never
     * leaves the process. The response already carries the token to the client
     * that asked for it.
     */
    private static ?string $issuedToken = null;

    /**
     * Declare who this request is, and that nothing else may answer.
     *
     * @param object|null $user The authenticated user, or null for anonymous
     */
    public static function seal(?object $user, string $via = '', ?string $issuedToken = null): void
    {
        self::$user   = $user;
        self::$sealed = true;

        // "How" normally belongs to a user, and an anonymous request has no
        // credential to describe — except when it has just discarded one.
        // `signed-out` is a real answer to "what happened here", and dropping it
        // would make a logout indistinguishable from a call that never carried
        // anything.
        self::$via         = ($user === null && $via !== 'signed-out') ? '' : $via;
        self::$issuedToken = $user === null ? null : $issuedToken;
    }

    /**
     * The token this request issued, if any.
     *
     * Only a login has one. Read by the debug toolbar to describe what was just
     * handed out; never published as a value.
     */
    public static function issuedToken(): ?string
    {
        return self::$issuedToken;
    }

    /**
     * How this request authenticated, or an empty string.
     */
    public static function via(): string
    {
        return self::$via;
    }

    /**
     * Has an identity been settled for this request?
     */
    public static function isSealed(): bool
    {
        return self::$sealed;
    }

    /**
     * The user this request authenticated as, or null.
     *
     * Null means anonymous when {@see isSealed()} is true, and "nobody has
     * asked" when it is false.
     */
    public static function user(): ?object
    {
        return self::$user;
    }

    /**
     * Forget everything.
     *
     * For tests, and for a long-running process that handles more than one
     * request in a single PHP lifetime — the second request must not inherit the
     * first one's caller.
     */
    public static function reset(): void
    {
        self::$user        = null;
        self::$sealed      = false;
        self::$via         = '';
        self::$issuedToken = null;
    }
}
