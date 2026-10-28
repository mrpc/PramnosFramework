<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Api;

use Pramnos\Routing\Router;

/**
 * The conversation API's routes, mounted with one line in an application's API routes:
 *
 * ```php
 * \Pramnos\Llm\Chat\Api\Routes::register($r);                                     // the framework's controller
 * \Pramnos\Llm\Chat\Api\Routes::register($r, controller: \App\Api\Controllers\Chat::class);   // a subclass of it
 * \Pramnos\Llm\Chat\Api\Routes::register($r, paths: ['routines' => '/routines']);  // keep an older address
 * ```
 *
 * Everything sits under `/chat` by default: `/chat`, `/chat/{conversation_id}`,
 * `/chat/asks/{ask_id}`, `/chat/memory`, `/chat/routines`, `/chat/runs`, `/chat/reports` and
 * `/chat/consent`. The fixed paths
 * are registered before `/chat/{conversation_id}`, which would otherwise take "memory" for an id.
 */
final class Routes
{
    /** The default paths, by name. */
    public const PATHS = [
        'chat'     => '/chat',
        'asks'     => '/chat/asks',
        'memory'   => '/chat/memory',
        'routines' => '/chat/routines',
        'runs'     => '/chat/runs',
        'reports'  => '/chat/reports',
        'consent'  => '/chat/consent',
        'artifacts' => '/chat/artifacts',
    ];

    /**
     * @param class-string<ChatController> $controller
     * @param array<string, string>        $paths      Overrides of {@see PATHS}, by name
     */
    public static function register(Router $r, string $controller = ChatController::class, array $paths = []): void
    {
        $p    = array_replace(self::PATHS, $paths);
        $make = static fn (): ChatController => new $controller();

        $r->get($p['asks'] . '/{ask_id}', static fn ($ask_id) => $make()->asked($ask_id));
        $r->get($p['memory'], static fn () => $make()->memory());
        $r->delete($p['memory'] . '/{memory_id}', static fn ($memory_id) => $make()->forgetMemory($memory_id));

        $r->get($p['routines'], static fn () => $make()->routines());
        $r->post($p['routines'], static fn () => $make()->createRoutine());
        $r->post($p['routines'] . '/{routine_id}/run', static fn ($routine_id) => $make()->runRoutine($routine_id));
        $r->get($p['routines'] . '/{routine_id}/runs', static fn ($routine_id) => $make()->routineRuns($routine_id));
        $r->post($p['routines'] . '/{routine_id}/subscribe', static fn ($routine_id) => $make()->subscribe($routine_id));
        $r->delete($p['routines'] . '/{routine_id}/subscribe', static fn ($routine_id) => $make()->unsubscribe($routine_id));
        $r->get($p['runs'] . '/{run_id}', static fn ($run_id) => $make()->readRun($run_id));
        $r->post($p['runs'] . '/{run_id}/continue', static fn ($run_id) => $make()->continueRun($run_id));
        $r->get($p['reports'], static fn () => $make()->reports());
        $r->put($p['routines'] . '/{routine_id}', static fn ($routine_id) => $make()->updateRoutine($routine_id));
        $r->delete($p['routines'] . '/{routine_id}', static fn ($routine_id) => $make()->deleteRoutine($routine_id));

        $r->get($p['artifacts'], static fn () => $make()->artifacts());
        $r->get($p['artifacts'] . '/{artifact_id}', static fn ($artifact_id) => $make()->readArtifact($artifact_id));
        $r->delete($p['artifacts'] . '/{artifact_id}', static fn ($artifact_id) => $make()->deleteArtifact($artifact_id));

        $r->get($p['consent'], static fn () => $make()->consent());
        $r->post($p['consent'], static fn () => $make()->giveConsent());
        $r->delete($p['consent'], static fn () => $make()->withdrawConsent());

        $r->get($p['chat'], static fn () => $make()->list());
        $r->post($p['chat'], static fn () => $make()->ask());
        $r->get($p['chat'] . '/{conversation_id}', static fn ($conversation_id) => $make()->read($conversation_id));
        $r->delete($p['chat'] . '/{conversation_id}', static fn ($conversation_id) => $make()->forget($conversation_id));
    }
}
