<?php

declare(strict_types=1);

namespace Pramnos\Llm\Admin;

/**
 * An application's own part of `/admin/Ai`.
 *
 * The screen is the one place an installation's language-model settings live, so what an
 * application adds to them — a per-organisation choice of provider, the limits of its plans —
 * goes on it rather than on a second screen beside it. Declared in `app.php`:
 *
 * ```php
 * 'llm' => ['admin_sections' => [App\Llm\PlanLimitsSection::class]],
 * ```
 *
 * Each section is a card with its own form. The screen renders {@see render()} inside a form
 * that posts back with the session's token and `section={key()}`, and calls {@see save()} with
 * the posted fields; the controller checks the administrator's ability before either. A card
 * that only shows something, with no form and no button, is a {@see Card}.
 */
interface Section extends Card
{
    /**
     * Store what was posted.
     *
     * @param array<string, mixed> $input The POST fields
     * @return string|null What is wrong with it, shown to the administrator; null when saved
     */
    public function save(array $input): ?string;
}
