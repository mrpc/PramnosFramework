<?php

declare(strict_types=1);

namespace Pramnos\Application\Api;

use Pramnos\Routing\Router;

/**
 * The framework's administration screens under the application's API, each by
 * {@see ScreenApi::mount()} — so a part of the administration can be a SPA (the audience's rules,
 * say) on the same actions, rights and checks as the page.
 *
 * ```php
 * \Pramnos\Application\Api\AdminApi::routes($r);              // /admin/rules, /admin/segments, …
 * \Pramnos\Application\Api\AdminApi::routes($r, '/admin', ['rules', 'segments']);   // only these
 * ```
 *
 * Each screen is under `{path}/{name}`: its class's name in lower case, without `Controller`.
 * The conversations and the to-dos have APIs of their own (`ConversationsApi`, `TodosApi`).
 */
class AdminApi
{
    /** @var list<class-string<\Pramnos\Application\Controller>> */
    public const SCREENS = [
        \Pramnos\Application\Controllers\AiController::class,
        \Pramnos\Application\Controllers\Audience::class,
        \Pramnos\Application\Controllers\Composer::class,
        \Pramnos\Application\Controllers\ConsentLists::class,
        \Pramnos\Application\Controllers\DashboardController::class,
        \Pramnos\Application\Controllers\EmailsController::class,
        \Pramnos\Application\Controllers\Experiments::class,
        \Pramnos\Application\Controllers\Goals::class,
        \Pramnos\Application\Controllers\Groups::class,
        \Pramnos\Application\Controllers\LogController::class,
        \Pramnos\Application\Controllers\MailingListsController::class,
        \Pramnos\Application\Controllers\MetricReports::class,
        \Pramnos\Application\Controllers\OrganizationsController::class,
        \Pramnos\Application\Controllers\PushLogController::class,
        \Pramnos\Application\Controllers\Rules::class,
        \Pramnos\Application\Controllers\Scheduler::class,
        \Pramnos\Application\Controllers\Segments::class,
        \Pramnos\Application\Controllers\ServicesController::class,
        \Pramnos\Application\Controllers\SettingsController::class,
        \Pramnos\Application\Controllers\UsersController::class,
        \Pramnos\Auth\Controllers\ApplicationsController::class,
        \Pramnos\Auth\Controllers\InvitationsController::class,
        \Pramnos\Auth\Controllers\PermissionsController::class,
        \Pramnos\Auth\Controllers\RolesController::class,
        \Pramnos\Auth\Controllers\TokenActionsController::class,
        \Pramnos\Auth\Controllers\TokensController::class,
        \Pramnos\Messaging\Controllers\MailTemplatesController::class,
        \Pramnos\Messaging\Controllers\MassMessagesController::class,
        \Pramnos\Queue\Controllers\QueueController::class,
    ];

    /**
     * Mount the screens under `$path`.
     *
     * @param list<string> $only Screens by name (`rules`, `users`); every one when empty
     */
    public static function routes(Router $r, string $path = '/admin', array $only = []): void
    {
        foreach (self::SCREENS as $screen) {
            $name = self::name($screen);
            if ($only === [] || in_array($name, array_map('strtolower', $only), true)) {
                ScreenApi::mount($r, rtrim($path, '/') . '/' . $name, $screen);
            }
        }
    }

    /** A screen's name under the path: `UsersController` is `users`. */
    public static function name(string $screen): string
    {
        return strtolower((string) preg_replace('/Controller$/', '', (new \ReflectionClass($screen))->getShortName()));
    }
}
