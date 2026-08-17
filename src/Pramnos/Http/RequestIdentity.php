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
     * A caller who has a continuous identity and no account.
     *
     * The two-state model — a user, or null — is right for an API, where anonymous
     * means *no identity at all*. It is not enough for an application whose
     * unauthenticated callers are people: a chat participant with a nickname and a
     * session, present in a room, mutable, bannable, addressable, and the same person
     * across requests for as long as they stay. They are not nobody. They are simply
     * not an account.
     *
     * Without this, such an application keeps a **second, parallel notion of who the
     * caller is**, because the framework's identity can only say *user* or *nobody* —
     * and then every consumer asking "who is this" has to know which of the two
     * mechanisms to ask, with a convention between them instead of a type.
     *
     * Holds an opaque application-defined id. The framework does not interpret it, and
     * deliberately does not know how it was derived — a presence row, a signed cookie,
     * a hash of a nickname and a session are all the application's business.
     *
     * @var string|null
     */
    private static ?string $guest = null;

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
        // An account and a guest are exclusive. Sealing a user over a guest is the
        // shape a silent promotion would take, so the guest is dropped rather than
        // kept alongside — see sealGuest(), which refuses the reverse outright.
        self::$guest  = null;

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
     * Declare that this request belongs to a guest — someone present, with no account.
     *
     * ```php
     * RequestIdentity::sealGuest($presenceId, 'presence');
     *
     * RequestIdentity::isGuest();   // true
     * RequestIdentity::user();      // null — an account is still an account
     * RequestIdentity::subject();   // the id
     * ```
     *
     * Three rules the framework states so an application does not have to get them
     * right on its own, because each is only wrong in a way nobody notices:
     *
     * - **A guest is never silently promoted.** Calling this when a user has already
     *   been sealed is refused and logged. The reverse — {@see seal()} over a guest —
     *   replaces the guest, because that is a real login, and *that* is why this
     *   direction must not be symmetric: an accidental `sealGuest()` late in a request
     *   would otherwise demote an authenticated caller and every permission check
     *   after it would answer for the wrong person.
     * - **{@see user()} keeps returning null.** A guest is not a `users` row and code
     *   that asks for a user must not be handed something that merely resembles one.
     *   This is the whole reason `isGuest()` exists as a separate question.
     * - **Sealing is request-scoped**, as it already was. Nothing here can disturb the
     *   browser's session, so a guest identity established for an API call cannot
     *   change who the next page belongs to.
     *
     * @param  string $id  An opaque, application-defined identifier. Stable within the
     *                     request and, for this to be worth anything, across the
     *                     requests of one visitor — how is the application's business.
     * @param  string $via What established it, in a developer's words: `presence`,
     *                     `nickname`, `guest-cookie`.
     * @return bool        False when the request already belongs to an account, in
     *                     which case nothing changed.
     */
    public static function sealGuest(string $id, string $via = 'guest'): bool
    {
        if ($id === '') {
            return false;
        }

        if (self::$user !== null) {
            // Not an exception: this is reached from middleware, and a framework that
            // fails a request over a bookkeeping mistake is worse than one that keeps
            // the stronger identity and says so.
            \Pramnos\Logs\Logger::log(
                'RequestIdentity::sealGuest() ignored: this request is already '
                . 'authenticated as a user (via ' . self::$via . '). A guest identity '
                . 'must never replace an account.',
                'auth'
            );

            return false;
        }

        self::$guest       = $id;
        self::$sealed      = true;
        self::$via         = $via;
        self::$issuedToken = null;

        return true;
    }

    /**
     * Is this request a guest — present, identified, and not an account?
     *
     * @return bool
     */
    public static function isGuest(): bool
    {
        return self::$guest !== null;
    }

    /**
     * The guest identifier, or null when this request is not a guest.
     *
     * @return string|null
     */
    public static function guestId(): ?string
    {
        return self::$guest;
    }

    /**
     * Who this request is, of whichever kind.
     *
     * The single question a consumer wants to ask. Returns the user's id for an
     * account, the opaque id for a guest, and null for a request that is genuinely
     * nobody — so one call distinguishes all three states that {@see user()} alone
     * cannot.
     *
     * @return int|string|null
     */
    public static function subject()
    {
        if (self::$user !== null) {
            return self::$user->userid ?? (self::$user->id ?? null);
        }

        return self::$guest;
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
        self::$guest       = null;
    }
}
