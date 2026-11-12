<?php

declare(strict_types=1);

namespace Pramnos\Notification;

/**
 * What a person chose about their notifications: a plain on/off per type, and whether the bell
 * reads what stays in sight. No matrix of channels: one switch per kind of notification, worded
 * for whoever reads it.
 *
 * ```php
 * // app.php — the types a person may turn off, each in their words:
 * 'notifications' => [
 *     'types'        => [\App\Notifications\TaskAssigned::class => 'A task given to me'],
 *     'read_on_view' => 500,   // the delay a person's "read what I see" waits; 500 by default
 * ],
 * Preferences::set($user, \App\Notifications\TaskAssigned::class, false);
 * Preferences::allows($user, $notification);   // false: not sent to them at all
 * ```
 *
 * Only a declared type can be turned off; any other is always sent. Kept on the person, as the
 * user detail {@see OFF} (the types turned off) and {@see READ_ON_VIEW}.
 */
final class Preferences
{
    /** The user detail the types a person turned off are kept in, as JSON. */
    public const OFF = 'notifyoff';

    /** The user detail whether the bell reads what stays in sight is kept in: '1' or '0'. */
    public const READ_ON_VIEW = 'notifyonview';

    /** How long an item stays in sight before it is read, where the application does not say. */
    public const READ_ON_VIEW_MS = 500;

    /**
     * The types a person may turn off, by class: their label.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        $types = self::config()['types'] ?? [];

        return is_array($types) ? array_map('strval', array_filter($types, 'is_string')) : [];
    }

    /**
     * The types a person turned off.
     *
     * @return list<string>
     */
    public static function off(object $user): array
    {
        $off = json_decode((string) ($user->{self::OFF} ?? ''), true);

        return is_array($off) ? array_values(array_intersect(array_map('strval', $off), array_keys(self::types()))) : [];
    }

    /** Whether a notification is to be sent to a person: a declared type they turned off is not. */
    public static function allows(mixed $notifiable, object $notification): bool
    {
        if (!is_object($notifiable) || (int) ($notifiable->userid ?? 0) < 1) {
            return true;
        }

        return !in_array($notification::class, self::off($notifiable), true);
    }

    /** Turn a declared type on or off for a person. False for a type not declared. */
    public static function set(object $user, string $type, bool $on): bool
    {
        if (!isset(self::types()[$type])) {
            return false;
        }
        $off = array_values(array_diff(self::off($user), [$type]));
        if (!$on) {
            $off[] = $type;
        }
        $user->{self::OFF} = (string) json_encode($off);
        if (method_exists($user, 'save')) {
            $user->save();
        }

        return true;
    }

    /**
     * How many milliseconds an unread item stays in sight of the open bell before it is read, for
     * a person: their choice, on with the application's delay ({@see READ_ON_VIEW_MS} by
     * default); 0 where they chose off, or never chose.
     */
    public static function readOnView(?object $user): int
    {
        if ($user === null || (string) ($user->{self::READ_ON_VIEW} ?? '') !== '1') {
            return 0;
        }

        return max(1, (int) (self::config()['read_on_view'] ?? self::READ_ON_VIEW_MS));
    }

    /** A person's choice about reading what stays in sight. */
    public static function setReadOnView(object $user, bool $on): void
    {
        $user->{self::READ_ON_VIEW} = $on ? '1' : '0';
        if (method_exists($user, 'save')) {
            $user->save();
        }
    }

    /**
     * What a preferences screen shows: each declared type with its label and whether it is on,
     * and the reading choice.
     *
     * @return array{types: list<array{type: string, label: string, on: bool}>, readOnView: bool}
     */
    public static function of(object $user): array
    {
        $off = self::off($user);
        $out = [];
        foreach (self::types() as $type => $label) {
            $out[] = ['type' => $type, 'label' => function_exists('t') ? (string) t($label) : $label, 'on' => !in_array($type, $off, true)];
        }

        return ['types' => $out, 'readOnView' => self::readOnView($user) > 0];
    }

    /** @return array<string, mixed> The `notifications` settings */
    private static function config(): array
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $all = is_object($app) ? ($app->applicationInfo['notifications'] ?? []) : [];

        return is_array($all) ? $all : [];
    }
}
