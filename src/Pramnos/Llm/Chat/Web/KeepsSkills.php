<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\StoredSkills;

/**
 * The skills page — a person's own skills, the drafts Ask proposed, and for those who manage them
 * the roles' skills and the offers waiting for approval — and its POST actions.
 *
 * {@see ChatPageController} serves it at `chat/skills` and
 * {@see \Pramnos\Application\Controllers\AiController} at `Ai/skills`. It reads the hooks the
 * scheduled page already asks of a controller — `taskScope()`, `taskDenied()`, `taskChatUrl()`,
 * `taskSignInUrl()`, `taskChatName()` — and one of its own:
 *
 * | hook | |
 * |---|---|
 * | `skillsUrl($action)` | the page (`''`), or one of its actions (`skillsave`, …) |
 *
 * Its actions are `skills`, `skillsave`, `skillturn`, `skilldelete`, `skillshare`, `skillapprove`
 * and `skillreject`; the controller declares them as auth actions, and all but the first as write
 * actions. Off — nothing but a message — unless the application turned stored skills on
 * ({@see StoredSkills::enabled()}).
 */
trait KeepsSkills
{
    /** `chat/skills` — this person's skills, and with `?skill=` one of them open to edit. */
    public function skills(): mixed
    {
        $user = $this->skillsUser();
        if ($user === null) {
            return null;
        }
        $store  = new StoredSkills();
        $manage = StoredSkills::canManageRoles($user);
        $open   = (int) ($_GET['skill'] ?? 0);
        $skill  = $open > 0 ? $store->find($open) : null;
        if ($skill !== null && !($skill['level'] === StoredSkills::ROLE ? $manage : $skill['userid'] === (int) $user->userid)) {
            $skill = null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('My skills');
        $app        = $this->application ?? \Pramnos\Application\Application::currentInstance();
        if ($app instanceof \Pramnos\Application\Application) {
            $app->addbreadcrumb($this->taskChatName(), $this->taskChatUrl());
            $app->addbreadcrumb(t('My skills'), '');
        }

        $view          = $this->getView('chat');
        $view->enabled = StoredSkills::enabled();
        $view->mine    = $view->enabled ? array_values(array_filter($store->mine((int) $user->userid), static fn (array $s): bool => $s['level'] === StoredSkills::USER)) : [];
        $view->offered = $view->enabled ? array_values(array_filter($store->mine((int) $user->userid), static fn (array $s): bool => $s['level'] === StoredSkills::ROLE)) : [];
        $view->roles   = $view->enabled && $manage ? $store->roles() : [];
        $view->manage  = $manage;
        $view->skill   = $skill;
        $view->roleChoices = \Pramnos\User\UserTypes::labels();
        $view->urls    = ['page' => $this->skillsUrl(), 'action' => $this->skillsUrl('%s'), 'chat' => $this->taskChatUrl()];

        return $view->display('skills');
    }

    /** Keep a skill from the form: a new one, or with `skill_id` one being edited. */
    public function skillsave(): mixed
    {
        $user = $this->skillsUser();
        if ($user === null) {
            return null;
        }
        $id    = (int) ($_POST['skill_id'] ?? 0);
        $input = array_intersect_key($_POST, array_flip(['name', 'description', 'tools', 'body']));
        // "For": `user`, the person's own, or `role:<usertype>`, a role's from that one up.
        $for   = (string) ($_POST['for'] ?? '');
        if (str_starts_with($for, 'role:')) {
            $input += ['level' => StoredSkills::ROLE, 'role' => substr($for, 5)];
        } elseif ($for === 'user') {
            $input['level'] = StoredSkills::USER;
        }
        $saved = (new StoredSkills())->save($user, $input, $id > 0 ? $id : null);
        is_string($saved) ? $this->addError(t($saved)) : $this->addMessage(t('Saved.'));
        $this->redirect($this->skillsUrl() . (is_string($saved) && $id > 0 ? '?skill=' . $id : ''));

        return null;
    }

    /** Turn a skill on or off — `on` in the form. A draft turned on is kept. */
    public function skillturn(mixed $id = null): mixed
    {
        return $this->skillDone(fn (object $user, int $skill): bool => (new StoredSkills())->turn($user, $skill, !empty($_POST['on'])), $id, 'Saved.');
    }

    /** Delete a skill. */
    public function skilldelete(mixed $id = null): mixed
    {
        return $this->skillDone(fn (object $user, int $skill): bool => (new StoredSkills())->delete($user, $skill), $id, 'Deleted.');
    }

    /** Offer one of this person's skills to a role — `role` in the form — for an administrator to approve. */
    public function skillshare(mixed $id = null): mixed
    {
        $user = $this->skillsUser();
        if ($user === null) {
            return null;
        }
        $made = (new StoredSkills())->share($user, (int) ($id ?: ($_POST['skill_id'] ?? 0)), (string) ($_POST['role'] ?? ''));
        is_string($made) ? $this->addError(t($made)) : $this->addMessage(t('Offered. An administrator approves it before the role has it.'));
        $this->redirect($this->skillsUrl());

        return null;
    }

    /** Approve a skill offered to a role. */
    public function skillapprove(mixed $id = null): mixed
    {
        return $this->skillDone(fn (object $user, int $skill): bool => (new StoredSkills())->approve($user, $skill), $id, 'Approved.');
    }

    /** Turn down a skill offered to a role. */
    public function skillreject(mixed $id = null): mixed
    {
        return $this->skillDone(fn (object $user, int $skill): bool => (new StoredSkills())->reject($user, $skill), $id, 'Turned down.');
    }

    /** `chat/skills`, or `chat/<action>`. */
    protected function skillsUrl(string $action = ''): string
    {
        return sURL . 'chat/' . ($action === '' ? 'skills' : $action);
    }

    /**
     * Run one of the page's actions on a skill — by id in the address or `skill_id` in the form —
     * say how it went, and go back to the page.
     *
     * @param callable(object, int): bool $do
     */
    private function skillDone(callable $do, mixed $id, string $done): mixed
    {
        $user = $this->skillsUser();
        if ($user === null) {
            return null;
        }
        $do($user, (int) ($id ?: ($_POST['skill_id'] ?? 0))) ? $this->addMessage(t($done)) : $this->addError(t('There is no such skill.'));
        $this->redirect($this->skillsUrl());

        return null;
    }

    /** The signed-in person, or null when the page has answered already: denied, or sent to sign in. */
    private function skillsUser(): ?object
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $user  = $scope !== null ? \Pramnos\User\User::getUser($scope->userId) : null;
        if (!is_object($user) || (int) ($user->userid ?? 0) < 2) {
            $this->redirect($this->taskSignInUrl());

            return null;
        }

        return $user;
    }
}
