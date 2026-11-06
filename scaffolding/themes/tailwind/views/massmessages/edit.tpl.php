@php /**
 * Compose a mass message (Tailwind theme).
 *
 * Variables:
 *   $this->message      — the row being edited, empty for a new one
 *   $this->types        — channel number => label
 *   $this->criteria     — the stored audience criteria
 *   $this->options      — the stored send options (wrapper, list, tracking, action)
 *   $this->languages    — the languages accounts actually have
 *   $this->templates    — the mail wrappers this installation can render
 *   $this->tracking     — whether open/click tracking is switched on at all
 *   $this->audienceSize — how many accounts those criteria match, right now
 *   $this->groups       — groupid => name, for the picker
 *   $this->organizations— organization_id => name, for the picker
 *   $this->preview      — ['total' => int, 'sample' => rows, 'truncated' => int]
 *   $this->previewed    — true when this render came from pressing Preview
 *
 * The count is on the form on purpose. It is the one number that changes an operator's
 * mind, and it is the number nobody has when a send is a loop somebody wrote in a
 * controller: "this goes to 4,812 people" is a different decision from "this goes to the
 * users".
 *
 * Saving does not send. That is the other thing this form has to make obvious, because
 * every other editor in the administration area finishes the job when you press Save.
 */
$message  = is_array($this->message ?? null) ? $this->message : [];
$types    = is_array($this->types ?? null) ? $this->types : [];
$criteria  = is_array($this->criteria ?? null) ? $this->criteria : [];
$options   = is_array($this->options ?? null) ? $this->options : [];
$languages = is_array($this->languages ?? null) ? $this->languages : [];
$templates = is_array($this->templates ?? null) ? $this->templates : [];
$tracking  = (bool) ($this->tracking ?? false);
$chosenList = (string) ($criteria['mailing_list'] ?? '');
$day       = static fn ($stamp): string => (int) $stamp > 0 ? date('Y-m-d', (int) $stamp) : '';
$size     = (int) ($this->audienceSize ?? 0);
$groups        = is_array($this->groups ?? null) ? $this->groups : [];
$organizations = is_array($this->organizations ?? null) ? $this->organizations : [];
$preview       = is_array($this->preview ?? null) ? $this->preview : ['total' => $size, 'sample' => [], 'truncated' => 0];
$previewed     = (bool) ($this->previewed ?? false);
$picked        = static function (array $criteria, string $key, $id): string {
    $chosen = $criteria[$key] ?? [];

    return in_array((int) $id, array_map('intval', is_array($chosen) ? $chosen : []), true)
        ? ' selected' : '';
};
$idList        = static function (array $criteria, string $key): string {
    $ids = $criteria[$key] ?? [];

    return implode(', ', array_map('intval', is_array($ids) ? $ids : []));
};
$id       = (int) ($message['messageid'] ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$checked  = static fn ($value): string => ($value ?? true) !== false ? ' checked' : ''; @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'massmessages'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <h2 class="text-lg font-semibold mb-4">{!! $id > 0 ? 'Edit mass message' : 'New mass message' !!}</h2>

    <form method="post" action="{!! adminUrl('MassMessages/save') !!}" data-pf-composer data-pf-composer-url="{{ adminUrl('Composer') }}" data-pf-composer-scope="mass-message" data-pf-editor-fields='{!! \Pramnos\Html\MessageComposer::fieldsJson() !!}' class="space-y-4 max-w-3xl">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        <input type="hidden" name="messageid" value="{!! $id !!}">

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-4">
            {!! \Pramnos\Html\MessageComposer::toolbar() !!}
            <div>
                <label class="block text-sm font-medium mb-1" for="subject">Subject</label>
                <input type="text" name="subject" id="subject" data-pf-composer-subject class="input input-sm w-full"
                       value="{{ $message['subject'] ?? '' }}" required>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="type">Channel</label>
                <select name="type" id="type" class="select select-sm w-full">
                    @foreach ($types as $value => $label)
                        <option value="{!! (int) $value !!}"
                            {!! (int) ($message['type'] ?? 0) === (int) $value ? 'selected' : '' !!}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-base-content/60 mt-1">
                    Email goes out through this installation's wrapper, in each recipient's own
                    language. An internal message lands in the account's inbox. Push reaches
                    every browser that subscribed — and an account with none is recorded as
                    <em>failed</em>, because most accounts have never granted permission and
                    counting those as delivered would report a send of thousands about a
                    message that reached dozens.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="message">Body</label>
                <textarea name="message" id="message" data-pf-composer-body data-pf-editor="{{ \Pramnos\Html\MessageEditor::mode() }}" rows="12" class="textarea textarea-sm w-full font-mono text-xs">{{ $message['message'] ?? '' }}</textarea>
                <p class="text-xs text-base-content/60 mt-1">
                    Markup, kept as written — it is the body of a message. It is escaped
                    wherever this screen displays it. For push the field is plain: a notification
                    carries text, and gets the text of any markup left in it.
                </p>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-4">
            <div class="flex items-baseline gap-3">
                <h3 class="font-medium">Audience</h3>
                <span class="badge badge-primary badge-sm">{!! number_format($size) !!} account(s)</span>
            </div>

            <details class="mm-accounts-only mm-picker"{!! isset($criteria['audience']) ? ' open' : '' !!}{!! $chosenList !== '' ? ' hidden' : '' !!}>
                <summary>{{ t('Choose by segments, groups and a filter') }}</summary>
                <p style="font-size:.8rem;opacity:.75">{{ t('When anything is picked here, it is the audience, and the criteria below are not used.') }}</p>
                @php $channel = [\Pramnos\Messaging\MassMessage::TYPE_MESSAGE => 'site', \Pramnos\Messaging\MassMessage::TYPE_PUSH => 'push'][(int) ($message['type'] ?? 0)] ?? 'email';
                echo \Pramnos\Html\AudienceBuilder::picker('audience', $criteria['audience'] ?? null, ['channel' => $channel]); @endphp
            </details>

            <div class="mm-accounts-only"{!! $chosenList !== '' ? ' hidden' : '' !!}>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="usertype_min">Usertype floor</label>
                    <input type="number" name="usertype_min" id="usertype_min" min="0" max="99"
                           class="input input-sm w-full"
                           value="{!! (int) ($criteria['usertype_min'] ?? 0) !!}">
                    <p class="text-xs text-base-content/60 mt-1">
                        A usertype is a threshold, so 0 means every account and 90 means
                        administrators and above.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="usertype_max">Usertype ceiling</label>
                    <input type="number" name="usertype_max" id="usertype_max" min="0" max="99"
                           class="input input-sm w-full"
                           value="{!! (int) ($criteria['usertype_max'] ?? 0) !!}">
                    <p class="text-xs text-base-content/60 mt-1">
                        0 for no ceiling. With a floor alone, "everybody below staff" can only
                        be written as "everybody" — which also reaches the operators.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="language">Language</label>
                    <select name="language" id="language" class="select select-sm w-full">
                        <option value="">Any</option>
                        @foreach ($languages as $language)
                            <option value="{{ $language }}"
                                {!! ($criteria['language'] ?? '') === $language ? 'selected' : '' !!}>
                                {{ $language }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-base-content/60 mt-1">
                        The account's language, not yours. One message per language, each to its
                        own audience, is the only honest way to write this.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="twofactor">Second factor</label>
                    <select name="twofactor" id="twofactor" class="select select-sm w-full">
                        <option value="">Any</option>
                        <option value="with" {!! ($criteria['twofactor'] ?? '') === 'with' ? 'selected' : '' !!}>
                            Holds one
                        </option>
                        <option value="without" {!! ($criteria['twofactor'] ?? '') === 'without' ? 'selected' : '' !!}>
                            Does not
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="last_login_after">Signed in after</label>
                    <input type="date" name="last_login_after" id="last_login_after" class="input input-sm w-full"
                           value="{{ $day($criteria['last_login_after'] ?? 0) }}">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="last_login_before">Not signed in since</label>
                    <input type="date" name="last_login_before" id="last_login_before" class="input input-sm w-full"
                           value="{{ $day($criteria['last_login_before'] ?? 0) }}">
                    <p class="text-xs text-base-content/60 mt-1">
                        The dormant audience. An account that never signed in at all is in it —
                        that is the most dormant an account can be.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($groups !== [])
                <div>
                    <label class="block text-sm font-medium mb-1" for="groups">Groups</label>
                    <select name="groups[]" id="groups" multiple size="5" class="select select-sm w-full h-auto">
                        @foreach ($groups as $groupId => $name)
                            <option value="{!! (int) $groupId !!}"{!! $picked($criteria, 'groups', $groupId) !!}>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-base-content/60 mt-1">
                        Accounts in <em>any</em> of the chosen groups. Nothing chosen means
                        the filter is not applied at all.
                    </p>
                </div>
                @endif

                @if ($organizations !== [])
                <div>
                    <label class="block text-sm font-medium mb-1" for="organizations">Organizations</label>
                    <select name="organizations[]" id="organizations" multiple size="5" class="select select-sm w-full h-auto">
                        @foreach ($organizations as $orgId => $name)
                            <option value="{!! (int) $orgId !!}"{!! $picked($criteria, 'organizations', $orgId) !!}>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-base-content/60 mt-1">
                        Accounts belonging to <em>any</em> of them.
                    </p>
                </div>
                @endif

                <div>
                    <label class="block text-sm font-medium mb-1" for="only_ids">Only these accounts</label>
                    <textarea name="only_ids" id="only_ids" rows="2" class="textarea textarea-sm w-full font-mono"
                              placeholder="42, 108, 1904">{{ $idList($criteria, 'only_ids') }}</textarea>
                    <p class="text-xs text-base-content/60 mt-1">
                        User ids, however you have them — commas, spaces or one per line. Filled
                        in, nobody else is reached. The filters above <em>still</em> apply, so an
                        account named here that is inactive or unsubscribed is not sent to, and
                        the preview shows you that.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="exclude_ids">Except these accounts</label>
                    <textarea name="exclude_ids" id="exclude_ids" rows="2" class="textarea textarea-sm w-full font-mono"
                              placeholder="7, 19">{{ $idList($criteria, 'exclude_ids') }}</textarea>
                    <p class="text-xs text-base-content/60 mt-1">
                        Removed from whatever the rest of this matched.
                    </p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="validated_only" value="1" class="checkbox checkbox-sm"
                    {!! $checked($criteria['validated_only'] ?? null) !!}>
                Validated accounts only
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="active_only" value="1" class="checkbox checkbox-sm"
                    {!! $checked($criteria['active_only'] ?? null) !!}>
                Active accounts only
            </label>

            </div>

            @php $optInLists = array_filter(\Pramnos\Email\MailTypes::all(), static fn ($type) => $type->optIn);
if ($optInLists !== []): @endphp
            <div>
                <label class="block text-sm font-medium mb-1" for="mailing_list">Or send to a mailing list</label>
                <select name="mailing_list" id="mailing_list" class="select select-sm w-full">
                    <option value="">— the accounts matched above —</option>
                    @foreach ($optInLists as $type)
                        <option value="{{ $type->list }}" {!! (($criteria['mailing_list'] ?? '') === $type->list) ? 'selected' : '' !!}>{{ $type->label }}</option>
                    @endforeach
                </select>
                <p class="text-xs opacity-70 mt-1">
                    Its confirmed subscribers, with or without an account, each in their own language and with the list's
                    unsubscribe link. The account criteria above are then ignored. Email only.
                </p>
            </div>
            @endif

            <div class="mm-accounts-only"{!! $chosenList !== '' ? ' hidden' : '' !!}>
                <label class="block text-sm font-medium mb-1" for="exclude_optouts">Exclude anyone who unsubscribed from</label>
                <input type="text" name="exclude_optouts" id="exclude_optouts" class="input input-sm w-full"
                       placeholder="massmessages"
                       value="{{ (string) ($criteria['exclude_optouts'] ?? '') }}">
                <p class="text-xs text-base-content/60 mt-1">
                    They are skipped at delivery either way. Naming the list here is what makes
                    the <strong>count</strong> honest — and the count is the number that decides
                    whether the send happens at all.
                </p>
            </div>

            <p class="text-xs text-base-content/60">
                The count above is what these criteria match now. The audience is resolved
                once, when the message is queued — so it cannot quietly grow to include
                accounts created after somebody approved the send.
            </p>

            <div class="pt-2 border-t border-base-300">
                <button type="submit" formaction="{!! adminUrl('MassMessages/preview') !!}" formnovalidate
                        class="btn btn-sm btn-outline">
                    Preview this audience
                </button>
                <span class="text-xs text-base-content/60 ml-2">
                    Nothing is saved and nothing is sent — it resolves the filters and shows you
                    who they mean.
                </span>
            </div>

            @if ($previewed)
                <div class="rounded border border-base-300 bg-base-200/40 p-3">
                    @if ((int) $preview['total'] === 0)
                        <p class="text-sm">
                            <strong>Nobody.</strong> These filters match no account — narrowed
                            past the point where anybody is left, which is the useful thing to
                            find out here rather than after pressing send.
                        </p>
                    @else
                        <p class="text-sm mb-2">
                            <strong>{!! number_format((int) $preview['total']) !!}</strong>
                            account(s).
                            @if ((int) $preview['truncated'] > 0)
                                The first {!! count($preview['sample']) !!} are listed;
                                {!! number_format((int) $preview['truncated']) !!} more are not.
                            @endif
                        </p>
                        <div class="overflow-x-auto">
                            <table class="table table-xs">
                                <thead>
                                    <tr>
                                        <th>ID</th><th>Username</th><th>Email</th>
                                        <th>Usertype</th><th>Language</th><th>Last signed in</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['sample'] as $row)
                                        <tr>
                                            <td>{!! (int) $row['userid'] !!}</td>
                                            <td>{{ $row['username'] }}</td>
                                            <td class="font-mono text-xs">{{ $row['email'] }}</td>
                                            <td>{!! (int) $row['usertype'] !!}</td>
                                            <td>{{ $row['language'] }}</td>
                                            <td>{!! (int) $row['lastlogin'] > 0 ? date('d/m/Y', (int) $row['lastlogin']) : '—' !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4 space-y-4">
            <h3 class="font-medium">Message options</h3>

            <div>
                <label class="block text-sm font-medium mb-1" for="preheader">Preheader</label>
                <input type="text" name="preheader" id="preheader" class="input input-sm w-full" maxlength="120"
                       value="{{ (string) ($options['preheader'] ?? '') }}">
                <p class="text-xs text-base-content/60 mt-1">
                    The line the inbox shows beside the subject. Left empty it is taken from the
                    message's own opening — which beats the wrapper's, but is not a sentence
                    written for the inbox.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="link">Link</label>
                <input type="url" name="link" id="link" class="input input-sm w-full" placeholder="https://"
                       value="{{ (string) ($options['link'] ?? '') }}">
                <p class="text-xs text-base-content/60 mt-1">Where a push notification opens.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="template">Wrapper</label>
                    @php $chosen = array_key_exists('template', $options) ? (string) $options['template'] : '__default__'; @endphp
                    <select name="template" id="template" class="select select-sm w-full">
                        <option value="__default__" {!! $chosen === '__default__' ? 'selected' : '' !!}>
                            The installation's default
                        </option>
                        <option value="" {!! $chosen === '' ? 'selected' : '' !!}>
                            None — send the body bare
                        </option>
                        @foreach ($templates as $template)
                            <option value="{{ $template }}"
                                {!! $chosen === $template ? 'selected' : '' !!}>
                                {{ $template }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="list">Unsubscribe list</label>
                    <input type="text" name="list" id="list" class="input input-sm w-full" placeholder="massmessages"
                           value="{{ $chosenList !== '' ? $chosenList : (string) ($options['list'] ?? '') }}"{!! $chosenList !== '' ? ' readonly' : '' !!}>
                    <p class="text-xs text-base-content/60 mt-1">
                        What a reader is unsubscribing <em>from</em>. Empty means the shared
                        `massmessages` list — one button, no more announcements.
                    </p>
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm {!! $tracking ? '' : 'opacity-60' !!}">
                <input type="checkbox" name="tracking" value="1" class="checkbox checkbox-sm mt-0.5"
                       {!! $tracking ? '' : 'disabled' !!}
                       {!! (!empty($options['tracking']) || ($tracking && $id === 0)) ? 'checked' : '' !!}>
                <span>
                    Track opens and clicks
                    <span class="block text-xs text-base-content/60">
                        @if ($tracking)
                            One tracking id per recipient, so the numbers mean something. Opens
                            are inflated by mail clients that prefetch images; clicks are people.
                        @else
                            Switched off for this installation.
                        @endif
                    </span>
                </span>
            </label>

            <div>
                <div class="text-sm font-medium mb-1">Gmail action</div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <select name="action_type" class="select select-sm">
                        @foreach (['' => 'No action', 'view' => 'View', 'confirm' => 'Confirm', 'save' => 'Save'] as $value => $label)
                            <option value="{{ $value }}"
                                {!! (string) ($options['action_type'] ?? '') === $value ? 'selected' : '' !!}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    <input type="text" name="action_name" class="input input-sm" placeholder="Button text" maxlength="60"
                           value="{{ (string) ($options['action_name'] ?? '') }}">
                    <input type="url" name="action_url" class="input input-sm" placeholder="https://"
                           value="{{ (string) ($options['action_url'] ?? '') }}">
                </div>
                <p class="text-xs text-base-content/60 mt-1">
                    The same URL for everybody, so this is for a page rather than for anything
                    carrying a per-recipient token. <strong>Confirm</strong> must act on the
                    first request, with no page and no login — Gmail's server follows it.
                </p>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300 shadow-xs p-4">
            <label class="block text-sm font-medium mb-1" for="scheduled">Send at</label>
            <input type="datetime-local" name="scheduled" id="scheduled" class="input input-sm"
                   value="@php $scheduled = (int) ($message['scheduled'] ?? 0);
                   echo $scheduled > 0 ? date('Y-m-d\TH:i', $scheduled) : ''; @endphp">
            <p class="text-xs text-base-content/60 mt-1">
                Leave empty to send as soon as it is queued. A time in the future holds the
                message until then; nothing else about the two paths differs.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="{!! adminUrl('MassMessages') !!}" class="btn btn-ghost btn-sm">Cancel</a>
            <span class="text-xs text-base-content/60">
                Saving does not send anything. Queueing is a separate, deliberate step on the
                message's own page.
            </span>
        </div>
    </form>
{!! \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode()) !!}

<script>
/*
 * A mailing list is reached by email only, and its subscribers unsubscribe from that list: when
 * one is chosen, the account criteria go, the channel is email, and the unsubscribe list is the
 * list itself. The server applies the same rule on save; this only keeps the form honest.
 */
(function () {
    var list = document.getElementById('mailing_list');
    var type = document.getElementById('type');
    var unsub = document.getElementById('list');
    if (!list || !type) { return; }
    var email = '{!! (int) \Pramnos\Messaging\MassMessage::TYPE_EMAIL !!}';
    var typed = unsub ? unsub.value : '';
    function apply() {
        var chosen = list.value !== '';
        document.querySelectorAll('.mm-accounts-only').forEach(function (el) { el.hidden = chosen; });
        if (chosen) { type.value = email; }
        Array.prototype.forEach.call(type.options, function (o) { o.disabled = chosen && o.value !== email; });
        if (unsub) {
            unsub.value = chosen ? list.value : typed;
            unsub.readOnly = chosen;
        }
    }
    list.addEventListener('change', apply);
    apply();
})();
</script>
</div>
<script>
/*
 * A push notification is text: with the channel set to push the body is the bare field, and the
 * editor comes back for email or an internal message, which both keep markup. Run once the
 * editor has enhanced the field (its own DOMContentLoaded listener is registered first).
 */
(function () {
    var type = document.getElementById('type');
    var body = document.getElementById('message');
    if (!type || !body) { return; }
    var push = '{!! (int) \Pramnos\Messaging\MassMessage::TYPE_PUSH !!}';
    function channel() {
        var plain = type.value === push;
        if (window.PfEditor) { window.PfEditor.plain(body, plain); }
        if (window.pfRichEditor) { window.pfRichEditor.plain(body, plain); }
        var tiny = window.tinymce ? window.tinymce.get('message') : null;
        if (tiny) { plain ? tiny.hide() : tiny.show(); }
    }
    type.addEventListener('change', channel);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', channel);
    } else {
        channel();
    }
})();
</script>
