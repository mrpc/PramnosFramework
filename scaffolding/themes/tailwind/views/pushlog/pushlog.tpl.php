@php /**
 * What was pushed, and what came of it (Tailwind theme).
 *
 * Variables:
 *   $this->rows    — the recent attempts, newest first
 *   $this->stats   — the week's shape: total, delivered, gone, refused, failed
 *   $this->userId  — the account being filtered to, or 0
 *   $this->only    — 'failed' when only the problems are shown
 *   $this->names   — account id => a name for it (PushLogController::names())
 *   $this->summary — by device (PushLogController::deviceSummary()): userid, endpoint_hash,
 *                    device, delivered, opened, dismissed, open_rate — FW-495, rows with a
 *                    token only
 *
 * The refusals are on the same screen as the deliveries, not on their own. "No browser on this
 * account is subscribed" is the commonest answer to *why did they not get it*, and it is only
 * useful beside the sends that did work — a screen listing only successes makes an installation
 * with no key pair look identical to one where everything is arriving.
 *
 * The endpoint is not shown because it is not stored: whoever holds it can push to that browser.
 */
$rows    = is_array($this->rows ?? null) ? $this->rows : [];
$stats   = is_array($this->stats ?? null) ? $this->stats : [];
$userId  = (int) ($this->userId ?? 0);
$only    = (string) ($this->only ?? '');
$names   = is_array($this->names ?? null) ? $this->names : [];
$summary = is_array($this->summary ?? null) ? $this->summary : [];
$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** A linked name for an account id, or an em dash for none. */
$account = static function (int $id) use ($names, $e): string {
    if ($id < 1) {
        return '—';
    }

    return '<a class="link link-hover" href="' . adminUrl('Users/view/') . $id . '">'
        . $e($names[$id] ?? ('#' . $id)) . '</a>';
};

/*
 * One reader for the timestamp.
 *
 * `sent` is a `timestamptz`, so PostgreSQL hands back `2026-08-29 14:49:32.517335+00` and MySQL
 * `2026-08-29 14:49:32`. `Log::sentAt()` is the one place that knows, so no view parses a date
 * itself — which is how `d/m/Y` ended up being sorted as a string elsewhere in this framework.
 */
$when   = static function (array $row): string {
    $time = \Pramnos\Push\Log::sentAt($row);

    return $time > 0 ? localDateTime($time) : '—';
};
// opened_at/dismissed_at are the same kind of value, just not sentAt()'s column.
$at = static function (mixed $value): string {
    $time = $value ? strtotime((string) $value) : false;

    return $time ? localDateTime($time) : '—';
};

/*
 * What a status means, in words.
 *
 * `410` is not a number an operator should have to look up: it is the browser telling us the
 * subscription is gone, which is a different problem from a busy service and from a request
 * that never reached one.
 */
$outcome = static function (array $row): array {
    // The words come from Log::outcome(), so this list and the detail page cannot disagree.
    $what = \Pramnos\Push\Log::outcome($row);
    $css  = [
        'delivered' => 'badge-success',
        'gone' => 'badge-warning',
        'not_sent' => 'badge-ghost',
        'busy' => 'badge-warning',
        'failed' => 'badge-error',
    ];

    return [$css[$what['kind']] ?? '', $what['label']];
}; @endphp
<div class="px-4 py-6">
    @php $this->activeNav = 'pushlog'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h2 class="text-lg font-semibold">{{ t('Push notifications') }}</h2>
        <span class="badge badge-neutral badge-sm">{!! count($rows) !!}</span>
    </div>

    <div class="stats stats-horizontal shadow-xs border border-base-300 mb-4 w-full overflow-x-auto">
        <div class="stat">
            <div class="stat-title">{{ t('Delivered') }}</div>
            <div class="stat-value text-2xl text-success">{!! (int) ($stats['delivered'] ?? 0) !!}</div>
            <div class="stat-desc">{{ t('last 7 days') }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">{{ t('Not sent') }}</div>
            <div class="stat-value text-2xl">{!! (int) ($stats['refused'] ?? 0) !!}</div>
            <div class="stat-desc">{{ t('nothing subscribed, no keys, no library') }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">{{ t('Subscription gone') }}</div>
            <div class="stat-value text-2xl text-warning">{!! (int) ($stats['gone'] ?? 0) !!}</div>
            <div class="stat-desc">{{ t('the row was deleted') }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">{{ t('Failed') }}</div>
            <div class="stat-value text-2xl text-error">{!! (int) ($stats['failed'] ?? 0) !!}</div>
            <div class="stat-desc">{{ t('busy, or never reached a server') }}</div>
        </div>
    </div>

    @if ($summary !== [])
        <div class="card bg-base-100 border border-base-300 shadow-xs overflow-x-auto mb-4">
            <div class="p-3 pb-0 text-sm font-semibold">{{ t('By device') }}</div>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ t('Person') }}</th>
                        <th>{{ t('Device') }}</th>
                        <th>{{ t('Delivered') }}</th>
                        <th>{{ t('Opened') }}</th>
                        <th>{{ t('Dismissed') }}</th>
                        <th>{{ t('Open rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary as $device)
                        <tr>
                            <td>{!! $account((int) ($device['userid'] ?? 0)) !!}</td>
                            <td class="text-xs">{{ $device['device'] ?? '' }}</td>
                            <td>{!! (int) ($device['delivered'] ?? 0) !!}</td>
                            <td>{!! (int) ($device['opened'] ?? 0) !!}</td>
                            <td>{!! (int) ($device['dismissed'] ?? 0) !!}</td>
                            <td>{{ $device['open_rate'] ?? 0 }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="{!! adminUrl('PushLog') !!}"
           class="btn btn-xs {!! $only === '' && $userId === 0 ? 'btn-primary' : 'btn-ghost' !!}">
            {{ t('Everything') }}
        </a>
        <a href="{!! adminUrl('PushLog') !!}?show=failed"
           class="btn btn-xs {!! $only === 'failed' ? 'btn-primary' : 'btn-ghost' !!}">
            {{ t('Only what did not arrive') }}
        </a>
        @if ($userId > 0)
            <span class="badge badge-outline badge-sm self-center">
                {!! $account($userId) !!}
            </span>
        @endif
    </div>

    @if ($rows === [])
        <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-sm text-base-content/70">
            {{ t('Nothing here yet. A row is written for every notification this application pushes') }} —
            <strong>{{ t('including the ones it decides not to send') }}</strong>, {{ t('which is usually the row somebody is looking for.') }}
        </div>
    @else
        <div class="card bg-base-100 border border-base-300 shadow-xs overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ t('When') }}</th>
                        <th>{{ t('Account') }}</th>
                        <th>{{ t('Notification') }}</th>
                        <th>{{ t('Title') }}</th>
                        <th>{{ t('Outcome') }}</th>
                        <th>{{ t('Opened') }}</th>
                        <th>{{ t('Dismissed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php [$class, $label] = $outcome($row); @endphp
                        <tr>
                            <td class="whitespace-nowrap"><a href="{{ adminUrl('PushLog/view/') . (int) ($row['pushid'] ?? 0) }}" title="{{ t('Details') }}">{{ $when($row) }}</a></td>
                            <td>{!! $account((int) ($row['userid'] ?? 0)) !!}</td>
                            <td class="text-xs">
                                @php $name  = (string) ($row['notification'] ?? '');
                                $short = $name === '' ? '—' : substr((string) strrchr('\\' . $name, '\\'), 1);
                                echo $e($short); @endphp
                            </td>
                            <td>
                                {{ $row['title'] ?? '' }}
                                @if (($row['body'] ?? '') !== '')
                                    <span class="block text-xs text-base-content/60">
                                        {{ mb_substr((string) $row['body'], 0, 120) }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm {!! $class !!}">{{ $label }}</span>
                                @if (($row['error'] ?? '') !== '')
                                    <span class="block text-xs text-base-content/60">
                                        {{ $row['error'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ $at($row['opened_at'] ?? null) }}</td>
                            <td class="whitespace-nowrap">{{ (empty($row['opened_at']) ? $at($row['dismissed_at'] ?? null) : '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
