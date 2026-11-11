@php /**
 * Conversations with a model (Bootstrap theme).
 *
 * Variables:
 *   $this->conversations — this person's, latest first: conversation_id, title, updated_at
 *   $this->conversation  — the open one, or null: title, messages (role, content, tools, …)
 *   $this->ask           — a question being answered, or null: status (waiting, answered, failed), says
 *   $this->memories      — what it remembers: memory_id, kind, content, source (`learned` when picked up after an answer)
 *   $this->connections   — optional: the MCP servers the person connected (Mcp\Connections::all()), or null where not offered
 *   $this->limits        — provider, model, turns, rounds, memory
 *   $this->consent       — provider, label, place, required, given
 *   $this->urls          — optional: page, ask, asked, consent, delete, forget, scheduled, all (the analyst's), share, unshare, shared
 *   $this->sharedWithMe  — optional: what others shared with the reader (Shares::withMe())
 *   $this->peopleUrl     — optional: where a name field asks who matches what is typed (`people`)
 *   $this->shares        — optional: who the open conversation is shared with (Shares::sharesOf())
 *   $this->bulkActions   — optional: by a list's kind of record, the bulk actions it offers (BulkActions::available())
 *   $this->pinned        — optional: the ids of the artifacts the reader pinned to their dashboard
 *   $this->heading       — optional: the title when no conversation is open
 *   $this->headingAvatar — optional: HTML of the picture beside that title (the analyst's, Assist\AvatarSets::render())
 *   $this->suggestions   — optional: example questions, shown while no conversation is open
 *   $this->assistantName — optional: the name over an answer, "Assistant" by default
 *   $this->greeting      — optional: the line over the empty page's composer (ChatLook::greeting())
 *   $this->placeholder   — optional: the composer's placeholder
 *   $this->accent        — optional: the page's accent colour, over the theme's primary
 *   $this->commands      — optional: the application's own `/` commands (ChatLook::commands())
 *
 * Answers are the model's words, drawn by Markdown::toHtml(), which escapes everything first.
 * A question is answered by the queue worker; pf-chat.js waits for it in place, and without
 * JavaScript the page refreshes itself until it is answered.
 */
$c       = ['card' => 'pf-chat-card card pf-l-card', 'muted' => 'text-muted small', 'bad' => 'text-danger', 'btn' => 'pf-chat-btn btn btn-sm btn-primary', 'btnGhost' => 'pf-chat-btn-ghost btn btn-sm btn-link', 'input' => 'pf-chat-input form-control', 'question' => 'bg-body-tertiary rounded-4', 'answer' => 'pf-chat-answer'];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$list    = is_array($this->conversations ?? null) ? $this->conversations : [];
$open    = is_array($this->conversation ?? null) ? $this->conversation : null;
$ask     = is_array($this->ask ?? null) ? $this->ask : null;
$limits  = is_array($this->limits ?? null) ? $this->limits : [];
$consent = is_array($this->consent ?? null) ? $this->consent : ['required' => false, 'given' => true];
$memory  = is_array($this->memories ?? null) ? $this->memories : [];
$openId  = (int) ($open['conversation_id'] ?? 0);
$token   = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
$url     = static fn (string $path): string => sURL . $path;
$waiting = $ask !== null && ($ask['status'] ?? '') === 'waiting';
// What the script says, in the page's language.
$say     = [
    'thinking' => t('Thinking'), 'failed' => t('The AI could not answer.'),
    'long' => t('Still working on a long answer — it will appear here.'), 'remove' => t('Remove'),
    'unsupported' => t('Attach a picture, or a Word (.docx), OpenDocument (.odt), text, Markdown or saved email (.eml) file.'),
    'too-many' => t('Up to 10 files with one question.'), 'too-large' => t('That is too large — up to 5 MB, all the files together.'),
    'you' => t('You'), 'today' => t('today'), 'copied' => t('Copied'), 'copy-table' => t('Copy table'),
    'stop' => t('Stop'), 'stopped' => t('Stopped.'),
    // Said after the opening of a long answer read aloud.
    'spoken-report' => t('See the report I prepared for you.'), 'spoken-rest' => t('The rest is written on the screen.'),
];
$name    = (string) ($this->assistantName ?? '') !== '' ? (string) $this->assistantName : t('Assistant');
$sayAttr = '';
foreach ($say as $key => $words) {
    $sayAttr .= ' data-say-' . $key . '="' . $e($words) . '"';
}
$lines   = is_array($this->waitingLines ?? null) ? array_values($this->waitingLines) : [];
// How full the model's view of this conversation was on the last answer.
$context = 0;
foreach ($open['messages'] ?? [] as $m) {
    if ($m['role'] === 'assistant' && !empty($m['context'])) {
        $context = (int) $m['context'];
    }
}
$window  = (int) ($limits['context_window'] ?? 0);
// Where each form goes: /chat by default, the analyst's own under /admin/Ai.
$u       = (is_array($this->urls ?? null) ? $this->urls : []) + [
    'page' => $url('chat'), 'ask' => $url('chat/ask'), 'asked' => $url('chat/asked/'), 'consent' => $url('chat/consent'),
    'delete' => $url('chat/delete/'), 'forget' => $url('chat/forget/'),
    // The scheduled tasks, for the conversations page itself; a page that names its own addresses
    // (the analyst's) names this one too, or has none.
    'scheduled' => is_array($this->urls ?? null) ? '' : $url('chat/scheduled'),
    // Their skills (Pramnos\Llm\Chat\StoredSkills), where the application turned them on.
    'skills' => is_array($this->urls ?? null) || !\Pramnos\Llm\Chat\StoredSkills::enabled() ? '' : $url('chat/skills'),
    // The application's skills from the catalogues: the analyst's page names them, for whoever manages skills.
    'skillcatalog' => '',
    // Everybody's memory: the analyst's page names it, for whoever reads everybody's conversations.
    'memories' => '',
    // Other people's Ask conversations, read only — the analyst's, for whoever reads them all.
    'all' => '',
    // Sharing a conversation or an artifact; a page that names its own addresses names these too, or has none.
    'share' => is_array($this->urls ?? null) ? '' : $url('chat/share/'),
    'unshare' => is_array($this->urls ?? null) ? '' : $url('chat/unshare/'),
    'shared' => is_array($this->urls ?? null) ? '' : $url('chat/shared/'),
    // Bulk actions on a list's records (Pramnos\Bulk\BulkActions).
    'act' => is_array($this->urls ?? null) ? '' : $url('chat/act/'),
    // A change the model proposed, approved or rejected by the reader (Llm\Chat\Proposals).
    'approve' => is_array($this->urls ?? null) ? '' : $url('chat/approve/'),
    'reject' => is_array($this->urls ?? null) ? '' : $url('chat/reject/'),
    // Good or bad, under each answer; and the queue of bad ones, for whoever reads everybody's.
    'rate' => is_array($this->urls ?? null) ? '' : $url('chat/rate/'),
    'reviews' => '',
    // An artifact's CSV, its page to print or save as PDF, and pinning it to the dashboard.
    'download' => is_array($this->urls ?? null) ? '' : $url('chat/download/'),
    'print' => is_array($this->urls ?? null) ? '' : $url('chat/printable/'),
    'pin' => is_array($this->urls ?? null) ? '' : $url('chat/pin/'),
    // Answers read aloud by the speech server, where there is one; the browser's voice where not.
    'speak' => is_array($this->urls ?? null) || !\Pramnos\Llm\Chat\Web\ChatPageController::readsAloudOnServer() ? '' : $url('chat/speak'),
    // Keeping whether a question asked aloud is answered aloud.
    'voicereply' => is_array($this->urls ?? null) ? '' : $url('chat/voicereply'),
    // Keeping the model the person chose for their questions (Automatic by default).
    'modelchoice' => is_array($this->urls ?? null) ? '' : $url('chat/modelchoice'),
];
// The models this person may choose, cheapest first, and the one they chose ('' is Automatic).
$modelUser    = \Pramnos\User\User::getCurrentUser() ?: null;
$modelChoices = \Pramnos\Llm\Chat\ModelChoices::allowed($modelUser);
$modelChosen  = (string) (\Pramnos\Llm\Chat\ModelChoices::chosen($modelUser)['key'] ?? '');
$pinned  = is_array($this->pinned ?? null) ? $this->pinned : [];
$bulk    = is_array($this->bulkActions ?? null) ? $this->bulkActions : [];
// Empty: a greeting over one large composer, the examples under it.
$empty   = $open === null && !$waiting;
$greeting = (string) ($this->greeting ?? '') !== '' ? (string) $this->greeting : t('How can I help?');
$accent  = \Pramnos\Llm\Chat\Web\ChatLook::accent((string) ($this->accent ?? ''));
$commands = \Pramnos\Llm\Chat\Web\ChatLook::commands($u, is_array($this->commands ?? null) ? $this->commands : []);
// Dictation + read-aloud (pf-voice.js), with the speech server where one is set up — behind this setting (Pramnos_LLM_Analyst_Guide.md "## Voice").
$voiceOn = (bool) \Pramnos\Llm\Llm::config('voice', false); @endphp
@if ($waiting)<noscript><meta http-equiv="refresh" content="4"></noscript>@endif
{!! \Pramnos\Theme\NeutralLayout::style() !!}
<div class="pf-chat{!! $empty ? ' pf-chat-empty' : '' !!} container-fluid pf-l-pad"{!! $accent !== '' ? ' style="--pf-chat-accent:' . $e($accent) . '"' : '' !!} data-commands="{{ json_encode($commands, JSON_UNESCAPED_UNICODE) }}"{!! (string) ($this->peopleUrl ?? '') !== '' ? ' data-pf-people-url="' . $e($this->peopleUrl) . '"' : '' !!} data-pf-chat data-page="{{ $u['page'] }}" data-asked="{{ $u['asked'] }}"{!! $sayAttr !!} data-waiting-lines="{{ json_encode($lines, JSON_UNESCAPED_UNICODE) }}" data-question-class="{{ $c['question'] }}" data-question-style="background-color:rgba(128,128,128,.12);border-radius:1rem"{!! $voiceOn ? ' data-pf-chat-voice' : '' !!}{!! $voiceOn && $u['voicereply'] !== '' ? ' data-pf-chat-voice-reply-url="' . $e($u['voicereply']) . '"' : '' !!}{!! $voiceOn && \Pramnos\Llm\Speech\Speech::pronunciations() !== [] ? ' data-pf-chat-pronounce="' . $e(json_encode(\Pramnos\Llm\Speech\Speech::pronunciations(), JSON_UNESCAPED_UNICODE)) . '"' : '' !!}{!! $voiceOn && $u['speak'] !== '' ? ' data-pf-chat-speak-server="' . $e($u['speak']) . '" data-pf-chat-csrf="' . $e(\Pramnos\Http\Session::getInstance()->getCsrfToken()) . '"' : '' !!}>
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <nav class="{!! $c['card'] !!}" style="flex:0 0 240px;padding:12px" aria-label="{{ t('Conversations') }}">
            <a href="{{ $u['page'] }}" class="{!! $c['btn'] !!}">{{ t('New conversation') }}</a>
            @if ($u['scheduled'] !== '')<a href="{{ $u['scheduled'] }}" class="{!! $c['btnGhost'] !!}">{{ t('Scheduled') }}</a>@endif
            <ul style="list-style:none;padding:0;margin:12px 0 0">
                @if ($list === [])
                    <li class="{!! $c['muted'] !!}">{{ t('Nothing asked yet.') }}</li>
                @endif
                @foreach ($list as $one)
                    <li style="display:flex;gap:4px;align-items:center">
                        <a style="flex:1;min-width:0" href="{{ $u['page'] . '?c=' . (int) $one['conversation_id'] }}"{!! (int) $one['conversation_id'] === $openId ? ' aria-current="page" style="font-weight:600"' : '' !!}>{{ $one['title'] }}</a>
                        <form method="post" action="{{ $u['delete'] . (int) $one['conversation_id'] }}" style="display:inline">
                            {!! $token() !!}
                            <button type="submit" class="{!! $c['btnGhost'] !!}" aria-label="{{ t('Delete') . ' ' . $one['title'] }}" data-confirm="{{ t('Delete this conversation?') . ' ' . t('Every question and answer in it goes. This cannot be undone.') }}">✕</button>
                        </form>
                    </li>
                @endforeach
            </ul>
            @if ($u['shared'] !== ''){!! \Pramnos\Llm\Chat\Web\ShareView::withMe(is_array($this->sharedWithMe ?? null) ? $this->sharedWithMe : [], $u['shared'], $c) !!}@endif
        </nav>

        <main class="pf-chat-main" style="flex:1 1 420px;min-width:0">
            @if ($empty)
                <div class="pf-chat-hero" data-pf-chat-hero>
                    @if (!empty($this->headingAvatar)){!! $this->headingAvatar !!}@endif
                    <h1>{{ $greeting }}</h1>
                </div>
            @else
                <h1 class="h3">@if (empty($open['title']) && !empty($this->headingAvatar)){!! $this->headingAvatar !!} @endif{{ $open['title'] ?? ($this->heading ?? t('Ask AI')) }}</h1>
            @endif
            @php if ($open !== null && $u['share'] !== ''): /* share it as it stands now */; @endphp
                {!! \Pramnos\Llm\Chat\Web\ShareView::form($u['share'], 'conversation', $openId, $openId, $token(), $c) !!}
                {!! \Pramnos\Llm\Chat\Web\ShareView::recipients(is_array($this->shares ?? null) ? $this->shares : [], $u['unshare'], $openId, $token(), $c) !!}
            @endif
            @php // The page's own tools, out of the way: a gear with whichever of them the reader has.; @endphp
            @if (!empty($this->settingsUrl) || $u['all'] !== '' || $u['reviews'] !== '' || $u['skills'] !== '' || $u['skillcatalog'] !== '' || $u['memories'] !== '')
                <details class="pf-ask-gear">
                    <summary aria-label="{{ t('Ask page tools') }}" title="{{ t('Ask page tools') }}" style="list-style:none;cursor:pointer"><span class="pf-chat-gear">⚙</span></summary>
                    <ul class="{!! $c['card'] !!}">
                        @if (!empty($this->settingsUrl))<li><a href="{{ $this->settingsUrl }}">{{ t('Settings') }}</a></li>@endif
                        @if ($u['all'] !== '')<li><a href="{{ $u['all'] }}">{{ t('Other people\'s Ask conversations') }}</a></li>@endif
                        @if ($u['reviews'] !== '')<li><a href="{{ $u['reviews'] }}">{{ t('Answers to review') }}</a></li>@endif
                        @if ($u['skills'] !== '')<li><a href="{{ $u['skills'] }}">{{ t('My skills') }}</a></li>@endif
                        @if ($u['skillcatalog'] !== '')<li><a href="{{ $u['skillcatalog'] }}">{{ t('The application\'s skills') }}</a></li>@endif
                        @if ($u['memories'] !== '')<li><a href="{{ $u['memories'] }}">{{ t('Everybody\'s memory') }}</a></li>@endif
                    </ul>
                </details>
            @endif

            @if (!empty($consent['required']) && empty($consent['given']))
                <section class="{!! $c['card'] !!}" style="padding:12px;margin-bottom:12px">
                    <p>{{ sprintf(t('The AI features use %s%s. Nothing is sent until you agree.'), $consent['label'], ($consent['place'] ?? '') !== '' ? ', ' . t('established in') . ' ' . $consent['place'] : '') }}</p>
                    <form method="post" action="{{ $u['consent'] }}">
                        {!! $token() !!}
                        <input type="hidden" name="give" value="1">
                        <input type="hidden" name="conversation_id" value="{!! $openId !!}">
                        <button type="submit" class="{!! $c['btn'] !!}">{{ t('Agree') }}</button>
                    </form>
                </section>
            @endif

            <div class="pf-chat-messages" data-pf-chat-messages>
                @foreach ($open['messages'] ?? [] as $m)
                    @php $mine = $m['role'] === 'user'; $at = \Pramnos\Llm\Chat\Web\Stamp::iso((string) ($m['created_at'] ?? '')); @endphp
                    <article class="pf-chat-message" data-pf-chat-role="{!! $mine ? 'user' : 'assistant' !!}" style="display:flex;flex-direction:column;align-items:{!! $mine ? 'flex-end' : 'flex-start' !!};margin-bottom:16px">
                        <header class="{!! $c['muted'] !!}" style="margin-bottom:4px"><strong>{{ $mine ? t('You') : $name }}</strong>@if ($at !== '') · <time datetime="{{ $at }}" data-pf-chat-time>{{ \Pramnos\Llm\Chat\Web\Stamp::text($at) }}</time>@endif</header>
                        <div class="{!! $mine ? $c['question'] : $c['answer'] !!}" style="{!! $mine ? 'padding:10px 14px;max-width:80%;background-color:rgba(128,128,128,.12);border-radius:1rem' : 'width:100%' !!}">
                        @if ($mine)
                            @php $doc = preg_match('/^(.*?)\n*---\n📎 ([^\n]+)(?:\n\n(.*))?$/su', (string) $m['content'], $d) === 1 ? $d : null; @endphp
                            <div style="white-space:pre-wrap">{{ $doc === null ? $m['content'] : $doc[1] }}</div>
                            @if ($doc !== null)
                                <details><summary>📎 {{ $doc[2] }}</summary><div class="{!! $c['muted'] !!}" style="white-space:pre-wrap;max-height:15rem;overflow:auto">{{ $doc[3] ?? '' }}</div></details>
                            @endif
                        @else
                            <div class="pf-chat-answer">{!! \Pramnos\Llm\Chat\Markdown::toHtml((string) $m['content']) !!}</div>
                            @php foreach ((array) ($m['artifacts'] ?? []) as $artifact): /* kept results: a table, a list, a text */; @endphp
                                {!! \Pramnos\Llm\Chat\Web\ArtifactView::html($artifact, $c['card'], 'table table-sm', $c['muted']) !!}
                                @if ($u['share'] !== ''){!! \Pramnos\Llm\Chat\Web\ShareView::form($u['share'], 'artifact', (int) $artifact['artifact_id'], $openId, $token(), $c) !!}@endif
                                {!! \Pramnos\Llm\Chat\Web\ArtifactTools::buttons($artifact, ['download' => $u['download'], 'print' => $u['print'], 'pin' => $u['pin'], 'assign' => $u['assign'] ?? ''], $pinned, $openId, $token(), $c) !!}
                                @if ($u['act'] !== '' && $artifact['kind'] === 'list'){!! \Pramnos\Llm\Chat\Web\BulkView::forms($bulk[(string) $artifact['entity']] ?? [], $u['act'], (int) $artifact['artifact_id'], count((array) ($artifact['content']['ids'] ?? [])), $openId, $token(), $c) !!}@endif
                                @if ($u['approve'] !== ''){!! \Pramnos\Llm\Chat\Web\BulkView::decision($artifact, $u['approve'], $u['reject'], $openId, $token(), $c) !!}@endif
                            @endforeach
                            @php $steps = array_values(array_filter((array) (($m['extra'] ?? [])['steps'] ?? []), 'is_string')); @endphp
                            @if ($steps !== [])
                                <details class="{!! $c['muted'] !!}">
                                    <summary>{{ t('What it did on the way') }}</summary>
                                    @foreach ($steps as $step)<div class="pf-chat-step">{!! \Pramnos\Llm\Chat\Markdown::toHtml($step) !!}</div>@endforeach
                                </details>
                            @endif
                            @php $tools = (array) ($m['tools'] ?? []); @endphp
                            @if ($tools === [])
                                <p class="{!! $c['muted'] !!}">{{ t('No data was read for this answer') }}</p>
                            @else
                                <details class="{!! $c['muted'] !!}">
                                    <summary>{{ sprintf(t('Read %s times from your data'), count($tools)) }}</summary>
                                    <ul>
                                        @foreach ($tools as $tool)
                                            @php $args = []; foreach ((array) ($tool['arguments'] ?? []) as $k => $v) { $args[] = $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v)); } @endphp
                                            @php
                                                // A help call: what the help gives for it now — the section and its score.
                                                $looked = ($tool['name'] ?? '') === 'help' && \Pramnos\Assist\Help::enabled()
                                                    ? \Pramnos\Llm\Analyst\Tools\HelpTool::lookedUp((array) ($tool['arguments'] ?? [])) : null;
                                                $gave   = $looked === null || $looked['kind'] === 'map' ? ''
                                                    : ' → ' . ($looked['found'] === 0 ? t('nothing') : $looked['path'] . ($looked['kind'] === 'question' ? sprintf(' (%.2f)', $looked['score']) : ''));
                                            @endphp
                                            <li style="font-family:monospace">{{ ($tool['name'] ?? '') . ($args === [] ? '' : ' (' . implode(', ', $args) . ')') . $gave }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        @endif
                        </div>
                        @if (!$mine)
                            <button type="button" class="{!! $c['btnGhost'] !!}" data-pf-chat-copy="{{ $m['content'] }}" aria-label="{{ t('Copy the answer') }}">⧉ {{ t('Copy') }}</button>
                            @if ($voiceOn)
                                <button type="button" class="{!! $c['btnGhost'] !!}" aria-pressed="false" data-pf-chat-speak="{{ $m['content'] }}" data-pf-chat-speak-id="{{ (string) ($m['message_id'] ?? '') }}" data-pf-chat-speak-artifacts="{{ count((array) ($m['artifacts'] ?? [])) }}" aria-label="{{ t('Read the answer aloud') }}">🔊 {{ t('Listen') }}</button>
                            @endif
                            @if ($u['rate'] !== '' && !empty($m['message_id'])){!! \Pramnos\Llm\Chat\Web\RatingView::buttons($m, $u['rate'], $openId, $token(), $c) !!}@endif
                        @endif
                    </article>
                @endforeach

                @if ($waiting)
                    <p class="pf-chat-thinking pf-chat-thinking-quiet {!! $c['muted'] !!}" data-pf-chat-waiting="{!! (int) $ask['ask_id'] !!}" role="status">{{ t('Thinking') }}…</p>
                @elseif ($ask !== null && ($ask['status'] ?? '') === 'failed')
                    <p class="{!! $c['bad'] !!}" role="alert">{{ $ask['says'] ?? '' }}</p>
                @endif
            </div>

            <form method="post" action="{{ $u['ask'] }}" enctype="multipart/form-data" class="pf-chat-composer" data-pf-chat-form>
                {!! $token() !!}
                <input type="hidden" name="conversation_id" value="{!! $openId !!}">
                <label for="chat-ask" class="pf-sr-only">{{ t('Your question') }}</label>
                <textarea id="chat-ask" name="content" rows="{!! $empty ? 3 : 1 !!}" class="pf-chat-input" placeholder="{{ (string) ($this->placeholder ?? '') !== '' ? $this->placeholder : t('Ask a question…') }}" aria-describedby="chat-ask-hint">{{ (string) ($this->prefill ?? '') }}</textarea>
                <div class="pf-chat-bar">
                    @if ($voiceOn)
                        <button type="button" class="pf-chat-mic" data-pf-chat-mic aria-pressed="false" title="{{ t('Dictate your question') }}">🎤<span class="pf-sr-only">{{ t('Dictate your question') }}</span></button>
                        @if ($u['voicereply'] !== '')<label class="pf-chat-voice-reply" title="{{ t('Answer aloud a question asked aloud') }}"><input type="checkbox" data-pf-chat-voice-reply{!! \Pramnos\Llm\Chat\Web\ChatPageController::repliesAloud(\Pramnos\User\User::getCurrentUser() ?: null) ? ' checked' : '' !!}> 🔊<span class="pf-sr-only">{{ t('Answer aloud a question asked aloud') }}</span></label>@endif
                    @endif
                    @if ($modelChoices !== [] && ($u['modelchoice'] ?? '') !== '')<label class="pf-chat-model"><span class="pf-sr-only">{{ t('Model') }}</span><select data-pf-chat-model data-pf-chat-model-url="{{ $u['modelchoice'] }}" title="{{ t('The model that answers') }}"><option value="">{{ t('Automatic') }}</option>@foreach ($modelChoices as $choice)<option value="{{ $choice['key'] }}"{!! $choice['key'] === $modelChosen ? ' selected' : '' !!}>{{ $choice['name'] }}</option>@endforeach</select></label>@endif
                    <label class="pf-chat-attach" title="{{ t('Attach a document') }}">📎<span class="pf-sr-only">{{ t('Attach a document') }}</span>
                        <input type="file" name="files[]" multiple accept=".docx,.odt,.txt,.md,.eml,image/*" class="pf-sr-only" data-pf-chat-files>
                    </label>
                    <span data-pf-chat-chips style="display:flex;flex-wrap:wrap;gap:4px"></span>
                    <span class="pf-chat-hint" id="chat-ask-hint">{{ t('Enter to send · Shift+Enter for a new line · / for commands') }}</span>
                    <button type="submit" class="pf-chat-send" aria-label="{{ t('Ask') }}" title="{{ t('Ask') }}"{!! !empty($consent['required']) && empty($consent['given']) ? ' disabled' : '' !!}>↑</button>
                </div>
            </form>
            @php $examples = $empty ? array_values(array_filter(is_array($this->suggestions ?? null) ? $this->suggestions : [], 'is_string')) : []; @endphp
            @if ($examples !== [])
                <div class="pf-chat-suggestions" aria-label="{{ t('Example questions') }}">
                    @foreach ($examples as $example)
                        <button type="button" class="btn btn-sm btn-default btn-outline-secondary" data-pf-chat-suggestion="{{ $example }}">{{ $example }}</button>
                    @endforeach
                </div>
            @endif

            @if ($context > 0 && $window > 0)
                <p class="{!! $c['muted'] !!}" style="display:flex;gap:8px;align-items:center">
                    {{ t('Context') }}
                    <progress value="{!! min(100, (int) round($context / $window * 100)) !!}" max="100" aria-label="{{ t('Context') }}"></progress>
                    {!! number_format($context) . ' / ' . number_format($window) !!}
                </p>
            @endif

            @if ($open !== null)
                <form method="post" action="{{ $u['delete'] . $openId }}" style="margin-top:8px">
                    {!! $token() !!}
                    <button type="submit" class="{!! $c['btnGhost'] !!}" data-confirm="{{ t('Delete this conversation?') . ' ' . t('Every question and answer in it goes. This cannot be undone.') }}">{{ t('Delete this conversation?') }}</button>
                </form>
            @endif
        </main>

        <aside class="{!! $c['card'] !!}" style="flex:0 0 260px;padding:12px">

            <details data-pf-chat-memory>

            <summary aria-label="{{ t('Model, limits and memory') }}"><span class="pf-chat-gear">⚙</span> {{ t('Model, limits and memory') }}</summary>
            <h2 class="h6 pf-l-mt">{{ t('Model') }}</h2>
            <p class="{!! $c['muted'] !!}">{{ ($limits['provider'] ?? '') . ' · ' . ($limits['model'] ?? '') }}</p>
            <h2 class="h6 pf-l-mt">{{ t('Limits') }}</h2>
            <ul class="{!! $c['muted'] !!}">
                <li>{{ sprintf(t('The last %d messages are sent with each question'), (int) ($limits['turns'] ?? 0)) }}</li>
                <li>{{ sprintf(t('Up to %d data reads per answer'), (int) ($limits['rounds'] ?? 0)) }}</li>
                <li>{{ sprintf(t('Up to %d things remembered'), (int) ($limits['memory'] ?? 0)) }}</li>
            </ul>
            <h2 class="h6 pf-l-mt">{{ sprintf(t('What it remembers (%d)'), count($memory)) }}</h2>
            @if ($memory === [])
                <p class="{!! $c['muted'] !!}">{{ t('Nothing remembered yet.') }}</p>
            @endif
            <ul style="list-style:none;padding:0">
                @foreach ($memory as $one)
                    <li style="margin-bottom:6px">
                        {{ $one['content'] }} <span class="{!! $c['muted'] !!}">{{ t($one['kind']) }}@if (($one['source'] ?? null) === 'learned') · {{ t('learned from what you said') }}@endif</span>
                        <form method="post" action="{{ $u['forget'] . (int) $one['memory_id'] }}" style="display:inline">
                            {!! $token() !!}
                            <input type="hidden" name="conversation_id" value="{!! $openId !!}">
                            <button type="submit" class="{!! $c['btnGhost'] !!}" aria-label="{{ t('Forget') }}">✕</button>
                        </form>
                    </li>
                @endforeach
            </ul>
            @if (is_array($this->connections ?? null))
                @php // The servers the person connected themselves: an address, and a token or a sign-in.; @endphp
                <h2 class="h6 pf-l-mt">{{ sprintf(t('Your connections (%d)'), count($this->connections)) }}</h2>
                <ul style="list-style:none;padding:0">
                    @foreach ($this->connections as $one)
                        <li style="margin-bottom:6px">
                            <strong>{{ $one['name'] }}</strong> <span class="{!! $c['muted'] !!}">{{ parse_url($one['url'], PHP_URL_HOST) }}</span>
                            <form method="post" action="{{ $url('chat/disconnect/') . rawurlencode($one['name']) }}" style="display:inline">
                                {!! $token() !!}
                                <input type="hidden" name="conversation_id" value="{!! $openId !!}">
                                <button type="submit" class="{!! $c['btnGhost'] !!}" aria-label="{{ t('Disconnect') }}">✕</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                <form method="post" action="{{ $url('chat/connect') }}">
                    {!! $token() !!}
                    <input type="hidden" name="conversation_id" value="{!! $openId !!}">
                    <input class="{!! $c['input'] !!}" name="name" placeholder="{{ t('Name, e.g. github') }}" required pattern="[a-z][a-z0-9_]{0,19}" style="margin-bottom:4px">
                    <input class="{!! $c['input'] !!}" name="url" type="url" placeholder="https://…" required style="margin-bottom:4px">
                    <input class="{!! $c['input'] !!}" id="pf-connection-token" name="token" type="password" autocomplete="off" placeholder="{{ t('Token, if it takes one') }}" style="margin-bottom:4px">
                    {!! \Pramnos\Html\PasswordToggle::render('pf-connection-token', '', '') !!}
                    <button type="submit" class="{!! $c['btn'] !!}">{{ t('Connect') }}</button>
                </form>
            @endif
            @if (!empty($consent['required']) && !empty($consent['given']))
                <h2 class="h6 pf-l-mt">{{ t('Your agreement') }}</h2>
                <p class="{!! $c['muted'] !!}">{{ sprintf(t('You agreed to the AI features using %s.'), $consent['label']) }}</p>
                <form method="post" action="{{ $u['consent'] }}">
                    {!! $token() !!}
                    <button type="submit" class="{!! $c['btnGhost'] !!}">{{ t('Withdraw — send nothing more') }}</button>
                </form>
            @endif

            </details>
        </aside>
    </div>
</div>
@if ($voiceOn)
<script src="{{ assetUrl('assets/js/pf-speech-sanitizer.js') }}" defer></script>
<script src="{{ assetUrl('assets/js/pf-voice.js') }}" defer></script>
@endif
<script src="{{ assetUrl('assets/js/pf-chat.js') }}" defer></script>
