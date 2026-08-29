<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * What kinds of mail this application sends.
 *
 * Every feature built around mail needs this list and none of them had it. The unsubscribe
 * list was a string typed at each call site; the mass-send screen asked for one in a free-text
 * box; the audit log's `module` column was whatever the sender happened to write; and there was
 * no way at all to show somebody the mail they can turn off, because nothing knew what it was.
 *
 * ```php
 * MailTypes::register(new MailType(
 *     'digest', 'Weekly digest', 'A summary of what happened, every Monday.', 'digest'
 * ));
 *
 * $email->type('digest')->setTo($address)->send();   // list, headers, link, suppression
 * ```
 *
 * ### What registering buys
 *
 * - **{@see Email::type()}** applies the type: the unsubscribe list, the `List-Unsubscribe`
 *   headers and the visible link, and the check that skips an address which has opted out.
 *   One call instead of four that have to agree.
 * - **A preferences page** rather than an all-or-nothing unsubscribe. `/unsubscribe` shows the
 *   optional types with what each one is, so somebody who wants fewer emails has a way to say
 *   that other than «none, ever».
 * - **The mass-send screen** offers real types instead of a text box, so a typo cannot invent a
 *   list that nothing suppresses against.
 * - **The audit log** records a name that means something, and one that is the same on every
 *   send of the same kind.
 *
 * ### Registration is optional, and nothing breaks without it
 *
 * An application that registers nothing keeps working exactly as before: `offerUnsubscribe()`
 * still takes a list, and mail without a declared type is transactional. This adds a way to
 * say what you send; it does not require you to.
 *
 * Register from a ServiceProvider or `Application.php`, once, at boot.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailTypes
{
    /** @var array<string, MailType> keyed by name, in registration order */
    private static array $types = [];

    /** Have the framework's own types been seeded? */
    private static bool $seeded = false;

    /**
     * The kinds of mail the framework itself sends.
     *
     * Seeded on first access rather than from a ServiceProvider's `boot()`, and that is a
     * deliberate trade. A provider would be the tidier home, but these types have to be there
     * for the *first* thing that asks — and what asks is a preferences page, a send, or an
     * administration screen, any of which can run in a context where the provider that would
     * have registered them was never booted. A list of mail types that is sometimes missing
     * three entries is worse than a lazy static, because the way it fails is a checkbox
     * silently absent from somebody's preferences.
     *
     * An application overrides any of these by registering the same name.
     *
     * @return list<MailType>
     */
    protected static function defaults(): array
    {
        return [
            new MailType(
                'newsignin',
                'Sign-in alerts',
                'An email when your account is signed in to from a browser or device you have '
                . 'not used before.',
                'newsignin'
            ),
            new MailType(
                'second-factor-code',
                'Sign-in codes',
                'The code you are asked for when signing in.'
            ),
            new MailType(
                'device-auth-link',
                'Sign-in confirmation links',
                'The link that finishes signing in on a new device.'
            ),
            new MailType(
                'security-change',
                'Security notices',
                'An email when your password, email address or second factor changes.'
            ),
        ];
    }

    private static function seed(): void
    {
        if (static::$seeded) {
            return;
        }

        // Set first: `register()` does not re-enter, but an application registering during its
        // own boot must not have its type overwritten by a later seed.
        static::$seeded = true;

        foreach (static::defaults() as $type) {
            if (!isset(static::$types[$type->name])) {
                static::$types[$type->name] = $type;
            }
        }
    }

    /**
     * Declare a kind of mail.
     *
     * Registering the same name twice replaces the first, deliberately: an application that
     * wants to change the label or the list of a type the framework declared should be able to,
     * and the alternative is a special case for "unregister" that exists only for that.
     */
    public static function register(MailType $type): void
    {
        static::seed();

        static::$types[$type->name] = $type;
    }

    /**
     * Everything registered, in the order it was registered.
     *
     * @return array<string, MailType>
     */
    public static function all(): array
    {
        static::seed();

        return static::$types;
    }

    /**
     * The types somebody can turn off — the ones a preferences page is a page about.
     *
     * @return array<string, MailType>
     */
    public static function optional(): array
    {
        return array_filter(
            static::all(),
            static fn (MailType $type): bool => !$type->transactional()
        );
    }

    public static function get(string $name): ?MailType
    {
        return static::all()[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return isset(static::all()[$name]);
    }

    /**
     * The unsubscribe list a type belongs to, or '' when it is transactional or unknown.
     *
     * An **unknown** name answers the same as a transactional one on purpose. The alternative
     * is to throw, and the thing that would throw is a send — so a typo in a type name would
     * stop a password reset rather than logging a mistake. Treating it as transactional means
     * the message goes out, without an unsubscribe link it should not have carried anyway.
     */
    public static function listFor(string $name): string
    {
        return trim((string) (static::get($name)?->list ?? ''));
    }

    /**
     * May this address be sent this kind of mail?
     *
     * True for transactional mail whatever the address has asked for: a password reset must
     * arrive for somebody who unsubscribed from everything. False only when the type belongs to
     * a list and the address has opted out of it — and {@see Unsubscribe::isOptedOut()} answers
     * *true* when it cannot tell, so a database outage suppresses optional mail rather than
     * sending to somebody who asked us to stop.
     */
    public static function allows(string $name, string $email): bool
    {
        $list = static::listFor($name);

        if ($list === '' || trim($email) === '') {
            return true;
        }

        return !Unsubscribe::isOptedOut($email, $list);
    }

    /** Forget everything. For tests, and for an application rebuilding the list at runtime. */
    public static function reset(): void
    {
        static::$types  = [];
        static::$seeded = false;
    }
}
