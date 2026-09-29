<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

/**
 * The mail an operator is allowed to rewrite, and what they may write in it.
 *
 * ## Why a registry rather than four strings in four classes
 *
 * `MailChannel` lets a stored template replace a notification's text, and a notification
 * declares which category it answers to. That is enough for the *sending* half and nothing
 * for the *editing* half: an operator opening Administration → Mail templates saw an empty
 * list and had no way to learn that `auth.twofactor_code` exists, let alone that `{code}`
 * and `{minutes}` are the things it may say.
 *
 * A feature reachable only by reading the source is a feature nobody uses. So the
 * categories are declared here, the screen lists them whether or not anybody has written a
 * row, and the editor can show what each one offers.
 *
 * ## The rows are seeded **empty**
 *
 * A migration creates one row per category with a title and a blank subject and body. That
 * is deliberate and it is the whole reason this can exist at all: **blank means "use the
 * built-in text"**, so a seeded installation behaves exactly as an unseeded one until
 * somebody types something.
 *
 * Seeding the built-in wording instead would look friendlier and be a trap. The row would
 * fork from the class the moment the framework improved a sentence, fixed a translation or
 * added a security note — and the installation would keep sending the old one for ever,
 * with nothing to say why.
 *
 * ## Drift
 *
 * The placeholders below are what the editor advertises; the variables a notification
 * actually supplies are in its own `storedMailTemplate()`. Two lists that must agree, so
 * `SystemMailTemplatesMatchTheNotificationsTest` asserts they do — a category advertised
 * with a placeholder nothing supplies renders `{like_this}` in somebody's email.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class SystemMailTemplates
{
    /**
     * Categories an application registered. {@see register()}
     *
     * @var array<string, array{title: string, description: string, placeholders: list<string>}>
     */
    private static array $registered = [];

    /**
     * Tell the editor about a category this application sends.
     *
     * ## Why this exists
     *
     * The registry was written for the framework's own four, and `placeholdersFor()` said
     * an application's category is "one the framework does not declare — which it documents
     * itself". **There was nowhere to do that documenting.** An application sending its own
     * mail through `MailChannel` got what the framework's categories got before the registry
     * existed: a category name, an empty body and nothing else.
     *
     * That is the sentence this class was written to make false, left true for everybody
     * except the framework. Worse, it read as finished from the outside — the doc-block
     * invited an application to document its own, so the absence looked like a decision.
     *
     * Call it from a service provider's `boot()` or from the application's `Application.php`:
     *
     * ```php
     * SystemMailTemplates::register([
     *     'shop.order_shipped' => [
     *         'title'        => 'Order shipped',
     *         'description'  => 'Sent when an order leaves the warehouse.',
     *         'placeholders' => ['ordernumber', 'trackingurl', 'sitename'],
     *     ],
     * ]);
     * ```
     *
     * **The framework's own win a collision.** An application cannot redefine what
     * `auth.twofactor_code` advertises, because the notification supplying those variables
     * is the framework's and the two lists have to agree — a registry entry that disagreed
     * would put `{like_this}` in somebody's email with the screen's blessing.
     *
     * Registering is about what the **editor shows**. Whether a row exists for the category
     * is a separate question, answered by a seeding migration; the framework seeds its own
     * four and an application seeds its own, in its own migration, for the same reason and
     * in the same shape.
     *
     * @param array<string, array{title: string, description: string, placeholders: list<string>}> $entries
     * @return void
     */
    public static function register(array $entries): void
    {
        foreach ($entries as $category => $entry) {
            self::$registered[(string) $category] = [
                'title'        => (string) ($entry['title'] ?? $category),
                'description'  => (string) ($entry['description'] ?? ''),
                'placeholders' => array_values(array_map('strval', (array) ($entry['placeholders'] ?? []))),
            ];
        }
    }

    /**
     * Forget every registration.
     *
     * Registrations are process-wide, and a test run is one process: a category registered
     * by one test would answer for the next.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$registered = [];
    }

    /**
     * Every category this installation sends — the framework's, plus registered ones.
     *
     * The framework's own are merged **last**, so they cannot be redefined. {@see register()}
     *
     * @return array<string, array{title: string, description: string, placeholders: list<string>}>
     */
    public static function all(): array
    {
        return array_merge(self::$registered, self::builtIn());
    }

    /**
     * Only the categories the framework itself sends.
     *
     * Separate from {@see all()} because two callers need exactly this and would be wrong
     * with the other: the seeding migration, which must not invent rows for an application's
     * mail, and the test comparing this registry against the framework's own notifications,
     * which would fail on any category no framework notification answers to.
     *
     * `description` is what the screen shows above the editor: a sentence saying when this
     * mail goes out, because "auth.new_signin" does not tell an operator whether editing it
     * is safe.
     *
     * @return array<string, array{title: string, description: string, placeholders: list<string>}>
     */
    public static function builtIn(): array
    {
        return [
            'auth.twofactor_code' => [
                'title'        => 'Sign-in code',
                'description'  => 'Sent when an account signs in and a code is the second '
                    . 'factor. Somebody is looking at a screen waiting for it.',
                'placeholders' => ['code', 'minutes', 'sitename'],
            ],
            'auth.new_device_link' => [
                'title'        => 'Sign-in link for a new device',
                'description'  => 'Sent when a sign-in link is issued for a device this '
                    . 'account has not used. Without {url} the message is unusable.',
                'placeholders' => ['url', 'minutes', 'device', 'sitename'],
            ],
            'auth.new_signin' => [
                'title'        => 'New sign-in notice',
                'description'  => 'Sent to an account after a sign-in from somewhere it has '
                    . 'not been seen before. Nobody is waiting for it.',
                'placeholders' => ['when', 'timestamp', 'sitename'],
            ],
            'auth.security_change' => [
                'title'        => 'Security change notice',
                'description'  => 'Sent when a password, an address or a second factor '
                    . 'changes. {what} names the change and {detail} describes it.',
                'placeholders' => ['what', 'detail', 'when', 'timestamp', 'sitename'],
            ],
            'auth.invitation' => [
                'title'        => 'Invitation to register',
                'description'  => 'Sent when somebody is invited to create an account. The '
                    . 'link opens registration for this address only; without {link} it cannot be used.',
                'placeholders' => ['link', 'hours', 'inviter', 'note', 'sitename'],
            ],
            'auth.verify_email' => [
                'title'        => 'Confirm your email address',
                'description'  => 'Sent after self-registration when the address has to be '
                    . 'confirmed before the account can sign in. Without {link} it cannot be.',
                'placeholders' => ['link', 'hours', 'username', 'sitename'],
            ],
        ];
    }

    /**
     * The placeholders a category offers, or an empty list for one nobody declared.
     *
     * An application's own category answers here once it has been registered.
     * {@see register()}
     *
     * @return list<string>
     */
    public static function placeholdersFor(string $category): array
    {
        return self::all()[$category]['placeholders'] ?? [];
    }

    /** The sentence the editor shows above a known category, or an empty string. */
    public static function describe(string $category): string
    {
        return (string) (self::all()[$category]['description'] ?? '');
    }
}
