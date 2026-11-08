<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\CatalogSkills;

/**
 * The application's skills, in the AI settings — `Ai/skillcatalog`: the skill files (read only)
 * and those installed from the catalogues ({@see CatalogSkills}); a search of the catalogues, a
 * skill read before it is installed, a newer version and what it changes; and its POST actions.
 *
 * {@see \Pramnos\Application\Controllers\AiController} serves it, for those who manage skills
 * (`llm.chat.skills_floor`). Its actions are `skillcatalog`, `skillinstall`, `skillcatalogturn`,
 * `skillcatalogrole`, `skillcatalogupdate` and `skillcatalogdelete`; the controller declares them
 * as auth actions, and all but the first as write actions. It reads `skillTools()` and
 * `skillRoleChoices()` of {@see KeepsSkills}, and one hook of its own:
 *
 * | hook | |
 * |---|---|
 * | `skillCatalogUrl($action)` | the page (`''`), or one of its actions |
 * | `catalogSkills()` | the store, on the catalogues the application names |
 * | `catalogSkillsAsker()` | who is asking: the signed-in account |
 */
trait KeepsCatalogSkills
{
    /**
     * The page: what is installed; with `?q=` a search of the catalogues, `?source=` one read before
     * it is installed, `?skill=` an installed one with what a newer version would change.
     */
    public function skillcatalog(): mixed
    {
        $user = $this->catalogSkillsUser();
        if ($user === null) {
            return null;
        }
        $store   = $this->catalogSkills();
        $q       = trim((string) ($_GET['q'] ?? ''));
        $source  = trim((string) ($_GET['source'] ?? ''));
        $open    = (int) ($_GET['skill'] ?? 0);
        $preview = null;
        $error   = null;
        if ($source !== '') {
            try {
                $preview = $store->preview($source);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $upstream = $open > 0 ? $store->upstream($open) : null;

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Skills');
        $view              = $this->getView('ai');
        $view->files       = \Pramnos\Llm\Chat\Skills::load(null, true);
        $view->installed   = $store->all();
        $view->search      = $q !== '' ? $store->search($q) : null;
        $view->q           = $q;
        $view->preview     = $preview;
        $view->error       = $error ?? (is_string($upstream) ? $upstream : null);
        $view->open        = $open > 0 ? $store->find($open) : null;
        $view->upstream    = is_array($upstream) ? $upstream : null;
        $view->tools       = $this->skillTools();
        $view->roleChoices = $this->skillRoleChoices();
        $view->catalogues  = CatalogSkills::catalogues();
        $view->urls        = ['page' => $this->skillCatalogUrl(), 'action' => $this->skillCatalogUrl('%s'), 'settings' => $this->skillCatalogUrl('settings')];

        return $view->display('skillcatalog');
    }

    /** Install a source — read at the commit its preview named — for the whole application, off. */
    public function skillinstall(): mixed
    {
        $user = $this->catalogSkillsUser();
        if ($user === null) {
            return null;
        }
        $made = $this->catalogSkills()->install($user, (string) ($_POST['source'] ?? ''), (string) ($_POST['role'] ?? ''), (string) ($_POST['as'] ?? ''));
        is_string($made) ? $this->addError(t($made)) : $this->addMessage(t('Installed. It is off until it is read and turned on.'));
        $this->redirect($this->skillCatalogUrl() . (is_string($made) ? '' : '?skill=' . $made));

        return null;
    }

    /** Turn an installed skill on or off — `on` in the form. */
    public function skillcatalogturn(mixed $id = null): mixed
    {
        return $this->catalogSkillDone(fn (object $user, int $skill): bool => $this->catalogSkills()->turn($user, $skill, !empty($_POST['on'])), $id, 'Saved.');
    }

    /** Keep an installed skill to a role and up — `role` in the form, empty for everybody. */
    public function skillcatalogrole(mixed $id = null): mixed
    {
        return $this->catalogSkillDone(fn (object $user, int $skill): bool => $this->catalogSkills()->forRole($user, $skill, (string) ($_POST['role'] ?? '')), $id, 'Saved.');
    }

    /** Take the catalogue's newer version of an installed skill: off again until it is read. */
    public function skillcatalogupdate(mixed $id = null): mixed
    {
        $user = $this->catalogSkillsUser();
        if ($user === null) {
            return null;
        }
        $skill = (int) ($id ?: ($_POST['skill_id'] ?? 0));
        $done  = $this->catalogSkills()->update($user, $skill);
        $done === true ? $this->addMessage(t('Updated. It is off until it is read and turned on again.')) : $this->addError(t($done));
        $this->redirect($this->skillCatalogUrl() . '?skill=' . $skill);

        return null;
    }

    /** Delete an installed skill. */
    public function skillcatalogdelete(mixed $id = null): mixed
    {
        return $this->catalogSkillDone(fn (object $user, int $skill): bool => $this->catalogSkills()->delete($user, $skill), $id, 'Deleted.');
    }

    /** `Ai/skillcatalog`, or `Ai/<action>`. */
    protected function skillCatalogUrl(string $action = ''): string
    {
        return adminUrl('Ai/' . ($action === '' ? 'skillcatalog' : $action));
    }

    /**
     * Run one of the page's actions on an installed skill — by id in the address or `skill_id` in
     * the form — say how it went, and go back to the page.
     *
     * @param callable(object, int): bool $do
     */
    private function catalogSkillDone(callable $do, mixed $id, string $done): mixed
    {
        $user = $this->catalogSkillsUser();
        if ($user === null) {
            return null;
        }
        $do($user, (int) ($id ?: ($_POST['skill_id'] ?? 0))) ? $this->addMessage(t($done)) : $this->addError(t('There is no such skill.'));
        $this->redirect($this->skillCatalogUrl());

        return null;
    }

    /** The store the page reads and writes. */
    protected function catalogSkills(): CatalogSkills
    {
        return new CatalogSkills();
    }

    /** Who is asking: the signed-in account, or null. */
    protected function catalogSkillsAsker(): ?object
    {
        return \Pramnos\User\User::current();
    }

    /** Somebody who manages skills, or null when the page has answered already: sent away. */
    private function catalogSkillsUser(): ?object
    {
        $user = $this->catalogSkillsAsker();
        if (!CatalogSkills::canManage($user)) {
            $this->addError(t('You do not have access to that screen.'));
            $this->redirect(adminUrl('Ai/settings'));

            return null;
        }

        return $user;
    }
}
