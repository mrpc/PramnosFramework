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
$c       = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'field' => 'input input-bordered input-sm', 'select' => 'select select-bordered select-sm', 'question' => 'rounded-2xl bg-base-200', 'banner' => 'alert alert-warning'];
echo \Pramnos\Llm\Chat\Web\ReadOnlyView::html((string) ($this->title ?? ''), is_array($this->messages ?? null) ? $this->messages : [], (string) ($this->note ?? ''), $c);
