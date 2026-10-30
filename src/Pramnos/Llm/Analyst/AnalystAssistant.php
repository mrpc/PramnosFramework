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

        // Its name, so it answers as what the administration calls it.
        return 'You are ' . Analyst::name() . ', the analyst for the administrators of this installation; '
            . 'that is your name, and you answer to it. Answer questions about '
            . 'how it is used and how it is running from what its tools read: accounts, sessions, '
            . 'organisations, mailing lists, email, push, the queue, logs, health and model spend. '
            // Today's date comes with each question, not here: this prompt stays the same for the cache.
            . 'Dates and days are in ' . $zone . '.' . "\n\n"
            . ($people !== '' ? $people . ' ' : '')
            . 'Masked values ([phone], [ip], a***@…) were removed on purpose; never try to recover them.' . "\n\n"
            . 'Say which window a figure covers. If a tool says the installation keeps no such data, say '
            . 'so rather than guessing. '
            // An application that lets the analyst schedule says so to the model, or it would
            // refuse the very tasks its tools can make.
            . ($this->routineRefusal($turn->scope) === null
                ? 'You change nothing in the installation; the only tools that write are the ones that schedule your tasks and reports, whose times are in ' . $zone . '.'
                : 'You cannot change anything: no tool writes.')
            . (($charts = trim($this->charts())) !== '' ? "\n\n" . $charts : '')
            . ($about !== '' ? "\n\nAbout this application: " . $about : '')
            . $this->sharedRule($turn)
            . "\n\nAnswer in the language the question was asked in, and write anything you say while you call tools in that language too.";
    }

    /** The page the administrator asked from: it changes with every question, so it comes with it. */
    public function volatileContext(Turn $turn): string
    {
        return \Pramnos\Assist\Page::prompt(is_array($turn->extra['page'] ?? null) ? $turn->extra['page'] : null);
    }

    /**
     * With the rights of whoever reads the answer — {@see Turn::rightsOf()}: the asker, or the least
     * privileged reader of a conversation others read too, where no file is made for the asker.
     */
    public function tools(Turn $turn): array
    {
        // Links to files and help under the area as the question's request saw it: the worker
        // answering it knows neither its host nor, often, its prefix.
        $tools = Analyst::tools($turn->rightsOf(), is_string($turn->extra['area'] ?? null) ? $turn->extra['area'] : '');

        return $turn->shared() ? array_diff_key($tools, ['export_csv' => true]) : $tools;
    }

    /**
     * For an answer others read too: what it may not show, and where the asker gets the rest — the
     * tools the asker has that this answer has not.
     */
    private function sharedRule(Turn $turn): string
    {
        if (!$turn->shared()) {
            return '';
        }
        $alone    = new Turn($turn->scope, $turn->conversationId, $turn->scheduled, $turn->messages, $turn->documents,
            array_diff_key($turn->extra, ['rights_of' => true]), $turn->conversations);
        $withheld = array_keys(array_diff_key($this->tools($alone), $this->tools($turn)));

        return "\n\nEverybody in this conversation reads your answer, so you answer only with what its least privileged reader may see."
            . ($withheld === [] ? '' : ' The person asking has these tools, which you may not use here: ' . implode(', ', $withheld)
                . '. If the question needs one of them, say that you cannot show that here and suggest asking you in a direct message.');
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

    /**
     * What an MCP client's model is told about this application — the same things this analyst
     * knows, so a question asked through an assistant elsewhere is answered as it would be here:
     * what the application is, its clock, which tool to reach for, the rule about people, and
     * that masked values stay masked.
     */
    public function mcpInstructions(): string
    {
        $about = trim($this->about());
        $guide = trim($this->toolGuidance());

        return implode("\n\n", array_values(array_filter([
            $about !== '' ? 'About this application: ' . $about : '',
            'Dates and days are in ' . Analyst::timezone() . '.',
            $guide !== '' ? $guide : 'Prefer the tool made for a question over a general one: schema and sql are the last resort, for what no other tool answers.',
            trim($this->peoplePolicy()),
            'Masked values ([phone], [ip], a***@…) were removed on purpose; never try to recover them.',
        ], static fn (string $part): bool => $part !== '')));
    }

    /**
     * Which tool answers what, for a model reaching this application over MCP — in place of the
     * framework's one line, "schema and sql are the last resort":
     *
     * ```php
     * protected function toolGuidance(): string
     * {
     *     return 'Visits, bookings and their counts: visits_report. Reach for sql only when no tool answers.';
     * }
     * ```
     */
    protected function toolGuidance(): string
    {
        return '';
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

    /** The analyst's clock — {@see Analyst::timezone()}: its days, and its tasks' times. */
    public function timezone(Scope $scope): string
    {
        return Analyst::timezone();
    }

    /** The analyst schedules nothing. */
    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return 'The analyst does not run scheduled reports.';
    }

    /** An analyst conversation — {@see areaUrl()}. */
    public function conversationUrl(?int $conversationId): string
    {
        return self::areaUrl('Ai/analyst' . ($conversationId ? '?c=' . $conversationId : ''));
    }

    /**
     * An address on the analyst's screen, the same from a page and from a cron run: the area's,
     * where this application has one; otherwise `admin/` under the site's address — a site's cron
     * has no area of its own, and its links still lead to the administration's — or what
     * `analyst.area` says, `''` for a site that is the administration throughout.
     */
    public static function areaUrl(string $path): string
    {
        if (\Pramnos\Http\AdminArea::prefix() !== '') {
            return \Pramnos\Http\AdminArea::url($path);
        }
        // Where the analyst's screen is when there is no area to ask: `admin/` by default, and
        // `'llm' => ['analyst' => ['area' => '']]` for an application whose whole site is the
        // administration — its screens at `/Ai`, `/Users`.
        $analyst = \Pramnos\Llm\Llm::config('analyst', []);
        $area    = is_array($analyst) && is_string($analyst['area'] ?? null) ? trim($analyst['area'], '/') : 'admin';

        return \Pramnos\Http\SiteUrl::to(($area === '' ? '' : $area . '/') . ltrim($path, '/'));
    }
}
