<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * One kind of mail an application sends.
 *
 * Not a template and not a message — a *kind*: «password reset», «weekly digest», «new sign-in
 * alert». The thing a person means when they say they get too many emails from you, and the
 * thing every feature around mail needs a name for.
 *
 * ### The two facts, and why they are the only two
 *
 * **A `list`, or none.** A type with a list is one somebody can turn off; a type without one is
 * transactional and cannot be. That single field decides the unsubscribe link, the two headers
 * a mailbox provider reads, whether tracking is allowed, and whether an opted-out address is
 * skipped — all of which were previously decided at each call site, separately, and therefore
 * inconsistently.
 *
 * **A `label` and a `description`.** Because the point of registering a type is that a person
 * eventually reads it on a preferences page, and `weekly_digest_v2` is not something to show
 * them. The description is one sentence answering *what will I stop getting* — the question
 * somebody is actually asking when they are looking at a list of checkboxes.
 *
 * ### What it deliberately does not carry
 *
 * No template, no subject, no sender. A type is what a message *is*, not how it looks; two
 * templates can be the same kind of mail, and the same template can be sent as two kinds. Tying
 * them together would mean an application could not change one without the other.
 *
 * ```php
 * new MailType('newsignin', 'Sign-in alerts', 'An email when your account is used from a '
 *     . 'browser you have not used before.', 'newsignin');
 *
 * new MailType('password-reset', 'Password resets', 'The link that lets you set a new '
 *     . 'password.');   // no list: transactional, and not offered as a choice
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class MailType
{
    /**
     * @param string $name        The identifier recorded on every send — short, stable, kebab-case
     * @param string $label       What a person sees on a preferences page
     * @param string $description One sentence: what stops arriving if this is turned off
     * @param string $list        The unsubscribe list this belongs to, or '' for transactional
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description = '',
        public readonly string $list = ''
    ) {
    }

    /**
     * Is this mail nobody can turn off?
     *
     * A password reset, a second-factor code, a receipt. Not a judgement about importance — it
     * is whether the message is a *consequence of something the person just did*. Those must
     * arrive for somebody who unsubscribed from everything, mailbox providers do not ask you to
     * offer an opt-out on them, and offering one anyway teaches people that the link does
     * nothing.
     */
    public function transactional(): bool
    {
        return trim($this->list) === '';
    }

    /** @return array{name: string, label: string, description: string, list: string, transactional: bool} */
    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'label'         => $this->label,
            'description'   => $this->description,
            'list'          => $this->list,
            'transactional' => $this->transactional(),
        ];
    }
}
