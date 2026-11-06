@php /**
 * A question to the AI from anywhere on the site (Bootstrap theme): include it in the layout.
 *
 *   <?php $this->insert('../partials/chat_widget'); ?>
 *
 * A floating button that opens a small form; the question goes to /chat, which answers it and
 * opens the conversation. Shown only to somebody signed in, once there is a key to answer with.
 */
$c = ['card' => 'card', 'muted' => 'text-muted small', 'bad' => 'text-danger', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-link', 'input' => 'form-control', 'question' => 'card bg-light', 'answer' => 'card'];
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Signed in, and a key to answer with: a question that can only fail is not invited.
if (\Pramnos\Llm\Chat\Scope::current() === null || !\Pramnos\Llm\Chat\Chat::available()) {
    return;
} @endphp
<details class="pf-chat-widget" style="position:fixed;right:16px;bottom:16px;z-index:50">
    <summary class="{!! $c['btn'] !!}">{{ t('Ask AI') }}</summary>
    <form method="post" action="{{ sURL . 'chat/ask' }}" class="{!! $c['card'] !!}" style="padding:12px;margin-top:8px;width:min(360px,calc(100vw - 32px))">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        <label for="chat-widget-ask" class="pf-sr-only">{{ t('Your question') }}</label>
        <textarea id="chat-widget-ask" name="content" rows="3" class="{!! $c['input'] !!}" style="width:100%" placeholder="{{ t('Ask a question…') }}" required></textarea>
        <div style="display:flex;gap:8px;margin-top:8px">
            <a href="{{ sURL . 'chat' }}" class="{!! $c['btnGhost'] !!}">{{ t('Ask AI') }}</a>
            <button type="submit" class="{!! $c['btn'] !!}" style="margin-left:auto">{{ t('Ask') }}</button>
        </div>
    </form>
</details>
