<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Llm\Connection;

/**
 * The analyst's assistant: the installation's connection, read-only tools, figures over people.
 *
 * Extend it to tell the model about the business — {@see about()} — or to change the tools:
 *
 * ```php
 * final class Analyst extends \Pramnos\Llm\Analyst\AnalystAssistant
 * {
 *     protected function about(): string
 *     {
 *         return 'GlideDay publishes social posts for newsrooms; an organisation is a newsroom.';
 *     }
 * }
 * ```
 */
class AnalystAssistant extends BaseAssistant
{
    /**
     * The `analyst` feature's connection when the application declares one, otherwise the
     * installation's — not `chat`'s, which an organisation may have routed elsewhere.
     */
    public function connection(Scope $scope): Connection
    {
        return new Connection(null, isset(Connection::features()['analyst']) ? 'analyst' : null);
    }

    public function systemPrompt(Turn $turn): string
    {
        $about  = trim($this->about());
        $zone   = Analyst::timezone();
        $people = trim($this->peoplePolicy());

        return 'You are the analyst for the administrators of this installation. Answer questions about '
            . 'how it is used and how it is running from what its tools read: accounts, sessions, '
            . 'organisations, mailing lists, email, push, the queue, logs, health and model spend. '
            . 'Today is ' . (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('l j F Y')
            . '; dates and days are in ' . $zone . '.' . "\n\n"
            . ($people !== '' ? $people . ' ' : '')
            . 'Masked values ([phone], [ip], a***@…) were removed on purpose; never try to recover them.' . "\n\n"
            . 'Say which window a figure covers. If a tool says the installation keeps no such data, say '
            . 'so rather than guessing. You cannot change anything: no tool writes.'
            . (($charts = trim($this->charts())) !== '' ? "\n\n" . $charts : '')
            . ($about !== '' ? "\n\nAbout this application: " . $about : '')
            . "\n\nAnswer in the language the question was asked in.";
    }

    public function tools(Turn $turn): array
    {
        return Analyst::tools((int) $turn->scope->userId);
    }

    /**
     * The rule about people — whether and when to look one up — in place of the framework's,
     * which keeps to figures. An installation whose administrators find people as ordinary work
     * says so here rather than contradicting the rule in {@see about()}:
     *
     * ```php
     * protected function peoplePolicy(): string
     * {
     *     return 'Finding and counting accounts by name is ordinary administration here; do it when asked.';
     * }
     * ```
     */
    protected function peoplePolicy(): string
    {
        return 'Use figures, not people: every tool answers aggregates. Look up one account with the user '
            . 'tool only when the administrator names that person, and ask it for an email address only '
            . 'when the administrator asked for it.';
    }

    /**
     * When and how to chart — {@see \Pramnos\Llm\Chat\Markdown::CHARTS}, which the conversation
     * page draws. '' for an analyst that never charts.
     */
    protected function charts(): string
    {
        return \Pramnos\Llm\Chat\Markdown::CHARTS;
    }

    /** What the application is, for the model — a sentence or two. */
    protected function about(): string
    {
        return '';
    }

    public function writes(): array
    {
        return ['remember', 'forget'];
    }

    /** The analyst schedules nothing. */
    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return 'The analyst does not run scheduled reports.';
    }

    public function conversationUrl(?int $conversationId): string
    {
        return \Pramnos\Http\SiteUrl::to('admin/Ai/analyst' . ($conversationId ? '?c=' . $conversationId : ''));
    }
}
