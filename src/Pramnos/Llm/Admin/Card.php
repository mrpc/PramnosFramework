<?php

declare(strict_types=1);

namespace Pramnos\Llm\Admin;

/**
 * A card of an application's own on `/admin/Ai` that only shows something — what the plans cost
 * this month, the spend per organisation — with no form and no Save button.
 *
 * Declared beside the sections, in `'llm' => ['admin_sections' => [...]]`. A card that also saves
 * what is posted is a {@see Section}.
 */
interface Card
{
    /** A short, unique name: it travels in the form, and is the card's anchor. */
    public function key(): string;

    /** The card's heading. */
    public function title(): string;

    /**
     * What the card shows — HTML, already escaped. For a {@see Section}, its fields: no `<form>`
     * tag and no submit button, the screen adds both.
     */
    public function render(): string;
}
