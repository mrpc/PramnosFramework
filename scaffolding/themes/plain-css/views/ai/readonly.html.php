<?php
/**
 * A report or a share for somebody who cannot open Ask — read only.
 *
 * Variables:
 *   $this->title    — what it is called
 *   $this->messages — role, content, artifacts
 *   $this->note     — when it ran, or who shared it
 *
 * The body is Web\ReadOnlyView's, the same in every theme; this file gives it the theme's classes.
 */
$c       = ['card' => 'pf-card', 'muted' => 'pf-muted', 'btn' => 'btn', 'btnGhost' => 'btn', 'field' => '', 'select' => '', 'question' => 'pf-card', 'banner' => 'pf-card'];
echo \Pramnos\Llm\Chat\Web\ReadOnlyView::html((string) ($this->title ?? ''), is_array($this->messages ?? null) ? $this->messages : [], (string) ($this->note ?? ''), $c);
