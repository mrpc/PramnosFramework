<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * Who may use the conversations, and who reads them all.
 *
 * The conversations are an ability of their own, `admin.chat`, not the administration's floor: a
 * team often talks with people who do not administer anything — sales, support. In usertype mode
 * the floor is `'conversations' => ['usertype' => 30]` in app.php (the administration's floor when
 * unset); with permissions, whoever has the ability. `admin.chat.all` reads every conversation,
 * closed and direct ones too — the top role's by default. A deactivated account has neither, at
 * once: it is asked every time. In usertype mode `admin.chat.all` is root's alone — reading other
 * people's direct conversations is more than a superuser's work. `admin.chat.files` — the files'
 * use of space, and removing them — and `admin.chat.moderate` — muting, banning and the rest in any
 * conversation — are a superuser's by default.
 */
final class Access
{
    /** The ability that opens the conversations. */
    public const ABILITY = 'admin.chat';

    /** The ability that reads every conversation. */
    public const READ_ALL = 'admin.chat.all';

    /** The ability that sees the files' use of space and removes files to free it. */
    public const FILES = 'admin.chat.files';

    /** The ability that moderates every conversation: mutes, bans, kicks, slow mode. */
    public const MODERATE = 'admin.chat.moderate';

    /** The usertype that opens them, in usertype mode. */
    public static function floor(): int
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $set = is_object($app) ? (int) ($app->applicationInfo['conversations']['usertype'] ?? 0) : 0;

        return $set > 0 ? $set : \Pramnos\Auth\AdminAccess::defaultUsertype();
    }

    /** Whether an account may use the conversations. */
    public static function canUse(?object $user): bool
    {
        return self::active($user) && \Pramnos\Auth\AdminAccess::allows($user, self::ABILITY, self::floor());
    }

    /** Whether an account reads every conversation. */
    public static function readsAll(?object $user): bool
    {
        // Root by default: reading other people's direct conversations is not the superuser's.
        return self::canUse($user) && \Pramnos\Auth\AdminAccess::allows($user, self::READ_ALL, \Pramnos\User\Roles::level(\Pramnos\User\Roles::ROOT));
    }

    /** Whether an account manages the files: their space, and removing them — a superuser's by default. */
    public static function managesFiles(?object $user): bool
    {
        return self::active($user) && \Pramnos\Auth\AdminAccess::allows($user, self::FILES, \Pramnos\Auth\AdminAccess::superuserUsertype());
    }

    /**
     * Whether an account moderates a conversation: its owner, or whoever has `admin.chat.moderate` —
     * a superuser's by default.
     */
    public static function moderates(?object $user, int $conversationId): bool
    {
        return self::canUse($user) && ((Conversations::member($conversationId, (int) $user->userid)['role'] ?? null) === 'owner'
            || \Pramnos\Auth\AdminAccess::allows($user, self::MODERATE, \Pramnos\Auth\AdminAccess::superuserUsertype()));
    }

    /** An account that is there and active. */
    private static function active(?object $user): bool
    {
        return $user !== null && (int) ($user->userid ?? 0) > 1 && (int) ($user->active ?? 1) === 1;
    }
}
