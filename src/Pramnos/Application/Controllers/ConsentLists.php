<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Audience\ConsentLists as Lists;

/**
 * `/admin/ConsentLists` — the consent every send and rule asks for: each list's channel, where it
 * is read from, the default for new accounts, and how many are in it now.
 *
 * Where a list is read from is one of the sources the application declares in code
 * (`ConsentLists::source()`); this screen names lists over them, so a list can be renamed, split
 * or pointed elsewhere without touching a rule. Each change is in the audit log.
 *
 * Actions:
 *   - display()     — every list with its count
 *   - edit($id)     — create (no id) or edit
 *   - save()        — POST: create or update
 *   - delete($id)   — POST: refused while a send or a rule uses it
 */
class ConsentLists extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.consent';

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'edit', 'save', 'delete']);
        $this->addWriteAction(['save', 'delete']);
        parent::__construct($application);
    }

    /** Every list, with how many are in it now — or why that cannot be counted. */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Consent lists');
        $sources = Lists::sources();
        $rows    = [];
        foreach (Lists::all() as $list) {
            try {
                $count = Lists::narrow([], $list['id'])->count();
                $problem = '';
            } catch (\InvalidArgumentException $e) {
                $count   = null;
                $problem = $e->getMessage();
            }
            $rows[] = $list + ['count' => $count, 'problem' => $problem, 'sourceLabel' => $sources[$list['source']]['label'] ?? $list['source']];
        }

        $view        = $this->getView('consentlists');
        $view->lists = $rows;

        return $view->display();
    }

    /** The form: name, channel, source, what it means to the person, default, opt-out list. */
    public function edit(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $id   = (int) \Pramnos\Http\Request::staticGetOption();
        $list = $id > 0 ? Lists::get($id) : null;
        if ($id > 0 && $list === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $list === null ? t('New consent list') : t('Edit consent list');

        $view           = $this->getView('consentlists');
        $view->list     = $list;
        $view->sources  = array_map(static fn (array $s): string => $s['label'], Lists::sources());
        $view->channels = self::channelWords();

        return $view->display('edit');
    }

    /** Create or change a list. */
    public function save(): void
    {
        if ($this->refused()) {
            return;
        }
        $id     = (int) ($_POST['listid'] ?? 0);
        $before = $id > 0 ? Lists::get($id) : null;
        if ($id > 0 && $before === null) {
            $this->gone();

            return;
        }
        $fields = [
            'name'        => (string) ($_POST['name'] ?? ''),
            'channel'     => (string) ($_POST['channel'] ?? ''),
            'source'      => (string) ($_POST['source'] ?? ''),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'defaultOn'   => !empty($_POST['defaulton']),
            'optOutList'  => (string) ($_POST['optoutlist'] ?? ''),
        ];
        try {
            if ($before === null) {
                $id = Lists::create($fields['name'], $fields['channel'], $fields['source'], $fields['description'], $fields['defaultOn'], $fields['optOutList']);
            } else {
                Lists::update($id, $fields);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('ConsentLists/edit/') . ($before === null ? '' : $id));

            return;
        }
        \Pramnos\Auth\AuditLog::change($before === null ? 'consentlist.created' : 'consentlist.updated', $this->me() ?: null, 'consentlist', $id, $before ?? [], Lists::get($id) ?? []);
        $this->addMessage(t('Saved.'));
        $this->redirect(adminUrl('ConsentLists'));
    }

    /** Delete a list — refused, with what uses it, while anything does. */
    public function delete(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }
        $id     = (int) \Pramnos\Http\Request::staticGetOption();
        $before = Lists::get($id);
        if ($before === null) {
            $this->gone();

            return;
        }
        try {
            Lists::delete($id);
        } catch (\RuntimeException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('ConsentLists'));

            return;
        }
        \Pramnos\Auth\AuditLog::change('consentlist.deleted', $this->me() ?: null, 'consentlist', $id, $before, []);
        $this->addMessage(t('Consent list deleted.'));
        $this->redirect(adminUrl('ConsentLists'));
    }

    /**
     * The channels in the page's language.
     *
     * @return array<string, string>
     */
    public static function channelWords(): array
    {
        return ['email' => t('Email'), 'sms' => t('SMS'), 'push' => t('Push'), 'phone' => t('Phone'), 'site' => t('On the site')];
    }

    /**
     * Whether this request stops here: below the administration floor, or before the migrations
     * have made the tables — an application may hold them back — where the screen says so.
     */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }
        if (!\Pramnos\Audience\Installed::consentLists()) {
            $this->addError(t('Consent lists are not installed here yet: run migrate.'));
            $this->redirect(adminUrl(''));

            return true;
        }

        return false;
    }

    /** The signed-in account's id, or 0. */
    protected function me(): int
    {
        $user = \Pramnos\User\User::current();

        return $user ? (int) $user->userid : 0;
    }

    /** A list that is not there: back to the lists. */
    protected function gone(): mixed
    {
        $this->addError(t('That consent list no longer exists.'));
        $this->redirect(adminUrl('ConsentLists'));

        return null;
    }
}
