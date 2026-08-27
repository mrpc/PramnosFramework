<?php

declare(strict_types=1);

namespace Pramnos\Messaging\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Framework\Factory;
use Pramnos\Html\Icon;
use Pramnos\Messaging\MailTemplate;

/**
 * The administration screens for reusable message templates.
 *
 * The `messaging` feature has shipped `mailtemplates` — the table, the model, the lookup
 * by `(category, language, type)` — with **no screen**. So the templates an application's
 * own mail goes through were editable only by a database client, which means in practice
 * they were not edited: a project that wanted to change the wording of a password-reset
 * email changed the code that composes it and left the template unused.
 *
 * Three things a template screen has to do, and each is here because leaving it out makes
 * the screen decorative:
 *
 *   - **Show the placeholders.** A template body is `{name}`, `{link}`, `{code}`, and an
 *     editor that does not list them is a form where a typo silently produces a mail with
 *     a literal brace in it.
 *   - **Group the language variants.** One notification is several rows — the same
 *     category and type, one per language — and a flat list of eighty rows hides which
 *     languages a notification is actually translated into.
 *   - **Send a test.** The only way to know a template renders is to render it.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class MailTemplatesController extends Controller
{
    /** Minimum usertype for any of this. Templates are the words the system says. */
    protected int $requiredUserType = 80;

    /**
     * The channels a template can be written for, as the model numbers them.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        MailTemplate::TYPE_EMAIL => 'Email',
        MailTemplate::TYPE_SMS   => 'SMS',
        MailTemplate::TYPE_PUSH  => 'Push',
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'data', 'view', 'edit', 'save', 'delete', 'test']);
        parent::__construct($application);
    }

    /**
     * The list, grouped so the language variants of one notification stay together.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = Factory::getDocument();
        $doc->title = 'Message templates';

        $template  = new MailTemplate($this);
        $templates = [];
        try {
            foreach ((array) $template->getList(null, 'category, type, language') as $row) {
                $templates[] = (array) $row;
            }
        } catch (\Throwable $ex) {
            $this->addError('Could not read the templates: ' . $ex->getMessage());
        }

        // Grouped by (category, type): one notification, its languages together. A flat
        // list of eighty rows cannot answer "is the reset email translated into Greek",
        // which is the question somebody opens this screen with.
        $groups = [];
        foreach ($templates as $row) {
            $key = ($row['category'] ?? '') . '|' . (int) ($row['type'] ?? 0);
            $groups[$key][] = $row;
        }

        $view            = $this->getView('mailtemplates');
        $view->groups    = $groups;
        $view->types     = self::TYPES;
        $view->templates = $templates;

        return $view->display();
    }

    /**
     * One template, read-only, with its placeholders listed.
     */
    public function view(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $template = $this->loadOr404();
        if ($template === null) {
            return null;
        }

        $doc        = Factory::getDocument();
        $doc->title = (string) $template->title;

        $view               = $this->getView('mailtemplates');
        $view->template     = $template->getData();
        $view->types        = self::TYPES;
        $view->placeholders = self::placeholders($template);

        return $view->display('view');
    }

    /**
     * The editor.
     */
    public function edit(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id       = (int) \Pramnos\Http\Request::staticGetOption();
        $template = new MailTemplate($this);
        if ($id > 0) {
            $template->load($id);
            if ((int) $template->templateid !== $id) {
                $this->addError('That template no longer exists.');
                $this->redirect(adminUrl('MailTemplates'));

                return null;
            }
        }

        $doc        = Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit template' : 'New template';

        $view               = $this->getView('mailtemplates');
        $view->template     = $template->getData();
        $view->isNew        = $id === 0;
        $view->types        = self::TYPES;
        $view->placeholders = self::placeholders($template);

        return $view->display('edit');
    }

    /**
     * Create or update one template.
     *
     * The body is **not** stripped of markup: an email template is markup, and a screen
     * that sanitised it would make the feature useless. It is stored as written and
     * escaped wherever it is *displayed* — see the views, which print it into a
     * `<textarea>` and a `<pre>`.
     */
    public function save(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $request = new \Pramnos\Http\Request();
        $id      = (int) $request->get('templateid', 0, 'post', 'int');

        $template = new MailTemplate($this);
        if ($id > 0) {
            $template->load($id);
        }

        $category = trim(strip_tags((string) $request->get('category', '', 'post')));
        $title    = trim(strip_tags((string) $request->get('title', '', 'post')));

        if ($category === '' || $title === '') {
            $this->addError('A template needs a category and a title.');
            $this->redirect(adminUrl('MailTemplates/edit/') . ($id ?: ''));

            return;
        }

        $template->title          = $title;
        $template->category       = $category;
        $template->language       = trim(strip_tags((string) $request->get('language', 'en', 'post'))) ?: 'en';
        $template->type           = (int) $request->get('type', 0, 'post', 'int');
        $template->defaultsubject = trim((string) $request->get('defaultsubject', '', 'post'));
        // Markup on purpose: this is the body of an email.
        $template->defaulttext    = (string) $request->get('defaulttext', '', 'post');
        $template->emailtemplate  = trim(strip_tags((string) $request->get('emailtemplate', '', 'post')));
        $template->sendmethod     = (int) $request->get('sendmethod', 0, 'post', 'int');
        $template->sound          = trim(strip_tags((string) $request->get('sound', '', 'post')));

        try {
            $template->save();
            $this->addMessage('Template saved.');
        } catch (\Throwable $ex) {
            $this->addError('Could not save: ' . $ex->getMessage());
        }

        $this->redirect(adminUrl('MailTemplates'));
    }

    /**
     * Delete one template.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id > 0) {
            try {
                (new MailTemplate($this))->delete($id);
                $this->addMessage('Template deleted.');
            } catch (\Throwable $ex) {
                $this->addError('Could not delete: ' . $ex->getMessage());
            }
        }

        $this->redirect(adminUrl('MailTemplates'));
    }

    /**
     * Send this template to one address, as a test.
     *
     * The only way to know a template renders is to render it. Sent to an address the
     * operator types rather than to a user: a test that goes to a real account is a
     * message somebody has to explain.
     *
     * Placeholders are filled with their own names — `{name}` arrives as `{name}` in
     * square brackets — so the test shows *where* each one lands without inventing data
     * that would hide a missing one.
     */
    public function test(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $address = trim((string) \Pramnos\Http\Request::staticGet('address', '', 'post'));

        $template = new MailTemplate($this);
        if ($id > 0) {
            $template->load($id);
        }

        if ((int) $template->templateid !== $id || $id < 1) {
            $this->addError('That template no longer exists.');
            $this->redirect(adminUrl('MailTemplates'));

            return;
        }

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('That is not an email address.');
            $this->redirect(adminUrl('MailTemplates/view/') . $id);

            return;
        }

        $body = (string) $template->defaulttext;
        foreach (self::placeholders($template) as $placeholder) {
            $body = str_replace('{' . $placeholder . '}', '[' . $placeholder . ']', $body);
        }

        try {
            $sent = \Pramnos\Email\Email::sendMail(
                '[test] ' . ($template->defaultsubject !== '' ? $template->defaultsubject : $template->title),
                $body,
                $address
            );
            $sent
                ? $this->addMessage('Test sent to ' . $address . '.')
                : $this->addError('The mailer refused the message — check the mail settings.');
        } catch (\Throwable $ex) {
            $this->addError('Could not send: ' . $ex->getMessage());
        }

        $this->redirect(adminUrl('MailTemplates/view/') . $id);
    }

    /**
     * The placeholder names a template's body and subject use.
     *
     * Read from the template rather than from a list somebody maintains: the placeholders
     * a template *has* are the ones in it, and a documented list is a list that goes
     * stale the first time an application adds one.
     *
     * @return list<string>
     */
    public static function placeholders(MailTemplate $template): array
    {
        $found = [];
        foreach ([(string) $template->defaulttext, (string) $template->defaultsubject] as $source) {
            if (preg_match_all('/\{([a-zA-Z0-9_.]+)\}/', $source, $matches)) {
                foreach ($matches[1] as $name) {
                    $found[$name] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** Load the template named in the path, or redirect and return null. */
    private function loadOr404(): ?MailTemplate
    {
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 1) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('MailTemplates'));

            return null;
        }

        $template = new MailTemplate($this);
        $template->load($id);

        if ((int) $template->templateid !== $id) {
            $this->addError('That template no longer exists.');
            $this->redirect(adminUrl('MailTemplates'));

            return null;
        }

        return $template;
    }
}
