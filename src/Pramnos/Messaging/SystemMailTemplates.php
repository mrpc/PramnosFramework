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
     * Every category the framework itself sends, and what it offers.
     *
     * `description` is what the screen shows above the editor: a sentence saying when this
     * mail goes out, because "auth.new_signin" does not tell an operator whether editing it
     * is safe.
     *
     * @return array<string, array{title: string, description: string, placeholders: list<string>}>
     */
    public static function all(): array
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
        ];
    }

    /**
     * The placeholders a category offers, or an empty list for one the framework does not
     * declare — an application's own category, which it documents itself.
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
