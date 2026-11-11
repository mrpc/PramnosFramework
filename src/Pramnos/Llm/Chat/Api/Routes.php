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
 * `/chat/asks/{ask_id}`, `/chat/memory`, `/chat/connections`, `/chat/routines`, `/chat/runs`, `/chat/reports`,
 * `/chat/skills`, `/chat/consent`, `/chat/speak`, `/chat/voices`, `/chat/voice`, `/chat/today`, `/chat/settings`, `/chat/photos`, `/chat/push`, `/chat/mood` and `/chat/style` — all but the first two follow `chat`
 * where they are not named, so a second mount at `/assistant` reads aloud at `/assistant/speak`. The fixed paths
 * are registered before `/chat/{conversation_id}`, which would otherwise take "memory" for an id.
 */
final class Routes
{
    /** The default paths, by name. */
    public const PATHS = [
        'chat'     => '/chat',
        'asks'     => '/chat/asks',
        'memory'   => '/chat/memory',
        'connections' => '/chat/connections',
        'routines' => '/chat/routines',
        'runs'     => '/chat/runs',
        'reports'  => '/chat/reports',
        'consent'  => '/chat/consent',
        'artifacts' => '/chat/artifacts',
        'shared'   => '/chat/shared',
        'shares'   => '/chat/shares',
        'people'   => '/chat/people',
        'skills'   => '/chat/skills',
    ];

    /**
     * @param class-string<ChatController> $controller
     * @param array<string, string>        $paths      Overrides of {@see PATHS}, by name
     */
    public static function register(Router $r, string $controller = ChatController::class, array $paths = []): void
    {
        $p    = array_replace(self::PATHS, $paths);
        $p['speak'] ??= $p['chat'] . '/speak';
        $p['listen'] ??= $p['chat'] . '/listen';
        $p['push']  ??= $p['chat'] . '/push';
        $p['mood']  ??= $p['chat'] . '/mood';
        $p['style'] ??= $p['chat'] . '/style';
        $p['personas'] ??= $p['chat'] . '/personas';
        $p['diary']    ??= $p['chat'] . '/diary';
        $p['me']       ??= $p['chat'] . '/me';
        $p['persona']  ??= $p['chat'] . '/persona';
        $p['voices']   ??= $p['chat'] . '/voices';
        $p['voice']    ??= $p['chat'] . '/voice';
        $p['today']    ??= $p['chat'] . '/today';
        $p['settings'] ??= $p['chat'] . '/settings';
        $p['photos']   ??= $p['chat'] . '/photos';
        $make = static fn (): ChatController => new $controller();

        $r->post($p['speak'], static fn () => $make()->speak())->describeFrom([$controller, 'speak']);
        $r->post($p['listen'], static fn () => $make()->listen())->describeFrom([$controller, 'listen']);
        // Ahead of {chat}/{conversation_id}, which would take "push" or "mood" for an id.
        $r->get($p['mood'], static fn () => $make()->mood())->describeFrom([$controller, 'mood']);
        $r->get($p['style'], static fn () => $make()->style())->describeFrom([$controller, 'style']);
        $r->put($p['style'], static fn () => $make()->style())->describeFrom([$controller, 'style']);
        $r->get($p['photos'], static fn () => $make()->photos())->describeFrom([$controller, 'photos']);
        $r->post($p['photos'], static fn () => $make()->photos())->describeFrom([$controller, 'photos']);
        $r->get($p['photos'] . '/{id}/file', static fn ($id) => $make()->photoFile($id))->describeFrom([$controller, 'photoFile']);
        $r->patch($p['photos'] . '/{id}', static fn ($id) => $make()->photo($id))->describeFrom([$controller, 'photo']);
        $r->delete($p['photos'] . '/{id}', static fn ($id) => $make()->photo($id))->describeFrom([$controller, 'photo']);
        $r->get($p['settings'], static fn () => $make()->settings())->describeFrom([$controller, 'settings']);
        $r->put($p['settings'], static fn () => $make()->settings())->describeFrom([$controller, 'settings']);
        $r->get($p['today'], static fn () => $make()->today())->describeFrom([$controller, 'today']);
        $r->get($p['voices'], static fn () => $make()->voices())->describeFrom([$controller, 'voices']);
        $r->put($p['voice'], static fn () => $make()->voices())->describeFrom([$controller, 'voices']);
        $r->get($p['personas'], static fn () => $make()->personas())->describeFrom([$controller, 'personas']);
        $r->get($p['diary'], static fn () => $make()->diary())->describeFrom([$controller, 'diary']);
        $r->get($p['me'], static fn () => $make()->me())->describeFrom([$controller, 'me']);
        $r->get($p['diary'] . '/{id}', static fn ($id) => $make()->diary($id))->describeFrom([$controller, 'diary']);
        $r->put($p['persona'], static fn () => $make()->persona())->describeFrom([$controller, 'persona']);
        $r->get($p['push'] . '/key', static fn () => $make()->pushKey())->describeFrom([$controller, 'pushKey']);
        $r->post($p['push'], static fn () => $make()->pushSubscribe())->describeFrom([$controller, 'pushSubscribe']);
        $r->delete($p['push'], static fn () => $make()->pushUnsubscribe())->describeFrom([$controller, 'pushUnsubscribe']);
        $r->get($p['asks'] . '/{ask_id}/stream', static fn ($ask_id) => $make()->askStream($ask_id))->describeFrom([$controller, 'askStream']);
        $r->get($p['asks'] . '/{ask_id}', static fn ($ask_id) => $make()->asked($ask_id))->describeFrom([$controller, 'asked']);
        $r->post($p['asks'] . '/{ask_id}/cancel', static fn ($ask_id) => $make()->cancelAsk($ask_id))->describeFrom([$controller, 'cancelAsk']);
        $r->get($p['skills'], static fn () => $make()->skillList())->describeFrom([$controller, 'skillList']);
        $r->post($p['skills'], static fn () => $make()->skillSave())->describeFrom([$controller, 'skillSave']);
        $r->put($p['skills'] . '/{skill_id}', static fn ($skill_id) => $make()->skillSave($skill_id))->describeFrom([$controller, 'skillSave']);
        $r->delete($p['skills'] . '/{skill_id}', static fn ($skill_id) => $make()->skillDelete($skill_id))->describeFrom([$controller, 'skillDelete']);
        $r->post($p['skills'] . '/{skill_id}/turn', static fn ($skill_id) => $make()->skillTurn($skill_id))->describeFrom([$controller, 'skillTurn']);
        $r->post($p['skills'] . '/{skill_id}/share', static fn ($skill_id) => $make()->skillShare($skill_id))->describeFrom([$controller, 'skillShare']);
        $r->post($p['skills'] . '/{skill_id}/approve', static fn ($skill_id) => $make()->skillApprove($skill_id))->describeFrom([$controller, 'skillApprove']);
        $r->post($p['skills'] . '/{skill_id}/reject', static fn ($skill_id) => $make()->skillReject($skill_id))->describeFrom([$controller, 'skillReject']);
        $r->get($p['memory'], static fn () => $make()->memory())->describeFrom([$controller, 'memory']);
        $r->delete($p['memory'] . '/{memory_id}', static fn ($memory_id) => $make()->forgetMemory($memory_id))->describeFrom([$controller, 'forgetMemory']);
        $r->patch($p['memory'] . '/{memory_id}', static fn ($memory_id) => $make()->amendMemory($memory_id))->describeFrom([$controller, 'amendMemory']);
        $r->get($p['connections'], static fn () => $make()->connections())->describeFrom([$controller, 'connections']);
        $r->post($p['connections'], static fn () => $make()->connect())->describeFrom([$controller, 'connect']);
        $r->delete($p['connections'] . '/{name}', static fn ($name) => $make()->disconnect($name))->describeFrom([$controller, 'disconnect']);

        $r->get($p['routines'], static fn () => $make()->routines())->describeFrom([$controller, 'routines']);
        $r->post($p['routines'], static fn () => $make()->createRoutine())->describeFrom([$controller, 'createRoutine']);
        $r->post($p['routines'] . '/{routine_id}/run', static fn ($routine_id) => $make()->runRoutine($routine_id))->describeFrom([$controller, 'runRoutine']);
        $r->post($p['routines'] . '/{routine_id}/report', static fn ($routine_id) => $make()->routineAsReport($routine_id))->describeFrom([$controller, 'routineAsReport']);
        $r->get($p['routines'] . '/{routine_id}/runs', static fn ($routine_id) => $make()->routineRuns($routine_id))->describeFrom([$controller, 'routineRuns']);
        $r->post($p['routines'] . '/{routine_id}/subscribe', static fn ($routine_id) => $make()->subscribe($routine_id))->describeFrom([$controller, 'subscribe']);
        $r->delete($p['routines'] . '/{routine_id}/subscribe', static fn ($routine_id) => $make()->unsubscribe($routine_id))->describeFrom([$controller, 'unsubscribe']);
        $r->get($p['runs'] . '/{run_id}', static fn ($run_id) => $make()->readRun($run_id))->describeFrom([$controller, 'readRun']);
        $r->post($p['runs'] . '/{run_id}/continue', static fn ($run_id) => $make()->continueRun($run_id))->describeFrom([$controller, 'continueRun']);
        $r->get($p['reports'], static fn () => $make()->reports())->describeFrom([$controller, 'reports']);
        $r->put($p['routines'] . '/{routine_id}', static fn ($routine_id) => $make()->updateRoutine($routine_id))->describeFrom([$controller, 'updateRoutine']);
        $r->delete($p['routines'] . '/{routine_id}', static fn ($routine_id) => $make()->deleteRoutine($routine_id))->describeFrom([$controller, 'deleteRoutine']);

        $r->get($p['artifacts'], static fn () => $make()->artifacts())->describeFrom([$controller, 'artifacts']);
        $r->get($p['artifacts'] . '/{artifact_id}', static fn ($artifact_id) => $make()->readArtifact($artifact_id))->describeFrom([$controller, 'readArtifact']);
        $r->delete($p['artifacts'] . '/{artifact_id}', static fn ($artifact_id) => $make()->deleteArtifact($artifact_id))->describeFrom([$controller, 'deleteArtifact']);
        $r->post($p['artifacts'] . '/{artifact_id}/share', static fn ($artifact_id) => $make()->shareArtifact($artifact_id))->describeFrom([$controller, 'shareArtifact']);
        $r->get($p['artifacts'] . '/{artifact_id}/shares', static fn ($artifact_id) => $make()->artifactShares($artifact_id))->describeFrom([$controller, 'artifactShares']);
        $r->get($p['artifacts'] . '/{artifact_id}/actions', static fn ($artifact_id) => $make()->artifactActions($artifact_id))->describeFrom([$controller, 'artifactActions']);
        $r->post($p['artifacts'] . '/{artifact_id}/actions/{action}', static fn ($artifact_id, $action) => $make()->runArtifactAction($artifact_id, $action))->describeFrom([$controller, 'runArtifactAction']);
        $r->post($p['artifacts'] . '/{artifact_id}/approve', static fn ($artifact_id) => $make()->approveProposal($artifact_id))->describeFrom([$controller, 'approveProposal']);
        $r->post($p['artifacts'] . '/{artifact_id}/reject', static fn ($artifact_id) => $make()->rejectProposal($artifact_id))->describeFrom([$controller, 'rejectProposal']);
        $r->post($p['chat'] . '/messages/{message_id}/rating', static fn ($message_id) => $make()->rate($message_id))->describeFrom([$controller, 'rate']);
        $r->get($p['artifacts'] . '/{artifact_id}/csv', static fn ($artifact_id) => $make()->artifactCsv($artifact_id))->describeFrom([$controller, 'artifactCsv']);
        $r->post($p['artifacts'] . '/{artifact_id}/pin', static fn ($artifact_id) => $make()->pinArtifact($artifact_id))->describeFrom([$controller, 'pinArtifact']);
        $r->get($p['shared'], static fn () => $make()->sharedWithMe())->describeFrom([$controller, 'sharedWithMe']);
        $r->get($p['people'], static fn () => $make()->people())->describeFrom([$controller, 'people']);
        $r->post($p['shared'] . '/{share_id}/open', static fn ($share_id) => $make()->openShared($share_id))->describeFrom([$controller, 'openShared']);
        $r->delete($p['shares'] . '/{share_id}', static fn ($share_id) => $make()->revokeShare($share_id))->describeFrom([$controller, 'revokeShare']);

        $r->get($p['consent'], static fn () => $make()->consent())->describeFrom([$controller, 'consent']);
        $r->post($p['consent'], static fn () => $make()->giveConsent())->describeFrom([$controller, 'giveConsent']);
        $r->delete($p['consent'], static fn () => $make()->withdrawConsent())->describeFrom([$controller, 'withdrawConsent']);

        $r->get($p['chat'], static fn () => $make()->list())->describeFrom([$controller, 'list']);
        $r->post($p['chat'], static fn () => $make()->ask())->describeFrom([$controller, 'ask']);
        $r->get($p['chat'] . '/{conversation_id}', static fn ($conversation_id) => $make()->read($conversation_id))->describeFrom([$controller, 'read']);
        $r->delete($p['chat'] . '/{conversation_id}', static fn ($conversation_id) => $make()->forget($conversation_id))->describeFrom([$controller, 'forget']);
        $r->post($p['chat'] . '/{conversation_id}/share', static fn ($conversation_id) => $make()->shareConversation($conversation_id))->describeFrom([$controller, 'shareConversation']);
        $r->get($p['chat'] . '/{conversation_id}/shares', static fn ($conversation_id) => $make()->conversationShares($conversation_id))->describeFrom([$controller, 'conversationShares']);
    }
}
