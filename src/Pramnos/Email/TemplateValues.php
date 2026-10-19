<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The values a message template is filled with for a recipient: the framework's, from the
 * account, and the application's ({@see TemplateValueProvider}), the application's winning.
 *
 * | Placeholder | Value |
 * |---|---|
 * | `{firstname}`, `{first_name}` | the first name |
 * | `{lastname}`, `{last_name}` | the last name |
 * | `{full_name}` | both |
 * | `{username}`, `{email}`, `{id}` | the account's |
 * | `{url}` | the site |
 * | `{login_url}`, `{Login_url}` | its sign-in page |
 *
 * The account's are its text, so they are escaped for HTML: a name with a `<` in it is shown,
 * never read as markup. An application's go in as it gives them.
 */
final class TemplateValues
{
    /**
     * The values for this recipient.
     *
     * @return array<string, string>
     */
    public static function forUser(?object $user): array
    {
        $site   = defined('sURL') ? (string) sURL : '';
        $values = ['url' => $site, 'login_url' => $site . 'account/login', 'Login_url' => $site . 'account/login'];
        if ($user !== null) {
            $text = static fn (mixed $value): string => htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
            $first = $text($user->firstname ?? '');
            $last  = $text($user->lastname ?? '');
            $values += [
                'firstname'  => $first,
                'first_name' => $first,
                'lastname'   => $last,
                'last_name'  => $last,
                'full_name'  => trim($first . ' ' . $last),
                'username'   => $text($user->username ?? ''),
                'email'      => $text($user->email ?? ''),
                'id'         => (string) (int) ($user->userid ?? 0),
            ];
        }

        return array_map('strval', self::provider()?->values($user) ?? []) + $values;
    }

    /**
     * A text with its `{placeholder}`s filled — every one named in `$values`, others left as they
     * are, so a mistake shows in the message rather than vanishing. Nothing is trimmed: what is
     * filled is a whole message, and filling it again gives the same bytes.
     *
     * @param array<string, scalar> $values
     */
    public static function fill(string $text, array $values): string
    {
        if ($values === [] || $text === '') {
            return $text;
        }

        return str_replace(
            array_map(static fn (string|int $name): string => '{' . $name . '}', array_keys($values)),
            array_map('strval', array_values($values)),
            $text
        );
    }

    /** The application's provider, as app.php names it; null for none. */
    private static function provider(): ?TemplateValueProvider
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $class = is_object($app) ? ($app->applicationInfo['email']['template_values'] ?? null) : null;

        return is_string($class) && is_a($class, TemplateValueProvider::class, true) ? new $class() : null;
    }
}
