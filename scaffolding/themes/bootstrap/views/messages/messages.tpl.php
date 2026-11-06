@php /**
 * The account's own messages (Bootstrap theme). See the Tailwind copy for the notes.
 */
$messages = is_array($this->messages ?? null) ? $this->messages : [];
$unread   = (int) ($this->unread ?? 0);
$e        = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when     = static fn ($v): string => ((int) $v) > 0 ? localDateTime( (int) $v) : '—';
$excerpt  = static function ($text): string {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');

    return mb_strlen($plain) > 140 ? mb_substr($plain, 0, 140) . '…' : $plain;
}; @endphp
<div class="container py-4" style="max-width:760px">
    <div class="d-flex align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">{{ t('Messages') }}</h1>
        @if ($unread > 0)
        <span class="badge text-bg-primary">{!! $unread !!}</span>
        <form method="post" action="{!! sURL !!}messages/readall" class="ms-auto">
            <button class="btn btn-sm btn-link">{{ t('Mark all as read') }}</button>
        </form>
        @endif
    </div>

    @if ($messages === [])
    <div class="card"><div class="card-body text-muted">{{ t('You have no messages.') }}</div></div>
    @else
    <div class="list-group">
        @foreach ($messages as $message)
        <a class="list-group-item list-group-item-action d-flex gap-3"
           href="{!! sURL !!}messages/show/{!! (int) ($message['messageid'] ?? 0) !!}">
            <span class="mt-2 rounded-circle {!! !empty($message['isUnread']) ? 'bg-primary' : 'bg-secondary-subtle' !!}"
                  style="width:8px;height:8px;flex:0 0 8px"></span>
            <span class="flex-grow-1 min-width-0">
                <span class="d-block {!! !empty($message['isUnread']) ? 'fw-semibold' : '' !!}">
                    {{ $message['subject'] ?? '' }}
                </span>
                <span class="d-block small text-muted text-truncate">
                    {{ $excerpt($message['text'] ?? '') }}
                </span>
            </span>
            <span class="small text-muted text-nowrap">{{ $when($message['date'] ?? 0) }}</span>
        </a>
        @endforeach
    </div>
    @endif
</div>
