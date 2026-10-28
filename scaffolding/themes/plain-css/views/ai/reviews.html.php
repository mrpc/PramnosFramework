<?php
/**
 * The bad answers waiting to be looked at — `/admin/Ai/reviews`.
 *
 * Variables:
 *   $this->queue   — Ratings::queue(): each bad answer, its question, note, owner and space
 *   $this->summary — Ratings::summary(): good, bad, waiting
 *   $this->urls    — reviewed (`reviewed/<id>`), open (an analyst conversation, read only), back
 *
 * The body is Web\ReviewQueueView's, the same in every theme; this file gives it the theme's classes.
 */
$c       = ['card' => 'pf-card', 'muted' => 'pf-muted', 'btn' => 'btn', 'btnGhost' => 'btn', 'field' => '', 'select' => '', 'question' => 'pf-card', 'banner' => 'pf-card'];
echo \Pramnos\Llm\Chat\Web\ReviewQueueView::html(
    is_array($this->queue ?? null) ? $this->queue : [],
    is_array($this->summary ?? null) ? $this->summary : ['good' => 0, 'bad' => 0, 'waiting' => 0],
    is_array($this->urls ?? null) ? $this->urls : [],
    \Pramnos\Http\Session::getInstance()->getTokenField(),
    $c
);
