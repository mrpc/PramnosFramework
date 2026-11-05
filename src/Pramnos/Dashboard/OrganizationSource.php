<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * What "an organisation" is when a preset or a dashboard is shared with one — the application's
 * own grouping of people, whatever it calls it.
 *
 * ```php
 * // app/app.php — a water utility's own groups instead of the framework's organisations
 * 'dashboard' => ['organizations' => App\Dashboard\Utilities::class],
 * // or none at all: the choice is not offered
 * 'dashboard' => ['organizations' => false],
 * ```
 *
 * Unsaid, the framework's organisations are used ({@see FrameworkOrganizations}), and the choice
 * is offered only when there are any.
 */
interface OrganizationSource
{
    /** What one is called, for the dialog — `Organisation`, or the application's own word. Translated by the view. */
    public function label(): string;

    /**
     * The ones a preset or a board may be shared with, id => name.
     *
     * @return array<int|string, string>
     */
    public function choices(): array;

    /** Whether a person belongs to one — who sees what is shared with it. */
    public function isMember(object $user, string $id): bool;
}
