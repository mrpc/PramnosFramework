@php /**
 * The consent lists: what each send and rule asks before it reaches somebody — its channel, where
 * it reads from, and how many are in it now. The same markup in every theme.
 *
 * Variables:
 *   $this->lists — ConsentLists::all(), each with count (null when it cannot be counted), problem
 *                  (why not), and sourceLabel
 */
use Pramnos\Html\Icon;

$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$channels = \Pramnos\Application\Controllers\ConsentLists::channelWords();
$tones    = ['email' => 'pf-tone-accent', 'sms' => 'pf-tone-ok', 'push' => 'pf-tone-info']; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'consentlists'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ t('Consent lists') }}</h1>
            <p class="pf-page-sub">{{ t('Every send and rule names one of these lists, never a field.') }}</p>
        </div>
        <div class="pf-page-actions">
            <a href="{{ adminUrl('ConsentLists/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New list') }}</a>
        </div>
    </header>
    <div class="pf-card pf-card-flush">
        @if (empty($this->lists))
            <div class="pf-empty">
                <span class="pf-empty-icon">{!! Icon::svg('consent', '') !!}</span>
                <p class="pf-empty-title">{{ t('No consent lists yet') }}</p>
                <p class="pf-empty-text">{{ t('A list says who agreed to a kind of message on a channel. A send or a rule then reaches only them, and an email list also leaves out everybody who unsubscribed from everything.') }}</p>
                <a href="{{ adminUrl('ConsentLists/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New list') }}</a>
            </div>
        @else
            <table class="pf-table">
                <thead>
                    <tr><th>{{ t('Name') }}</th><th>{{ t('Channel') }}</th><th>{{ t('Read from') }}</th><th>{{ t('New accounts') }}</th><th class="pf-num">{{ t('In it now') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($this->lists as $list)
                    <tr>
                        <td>
                            <a class="pf-row-link" href="{{ adminUrl('ConsentLists/edit/') . (int) $list['id'] }}">{{ $list['name'] }}</a>
                            @if ($list['description'] !== '')<span class="pf-row-desc">{{ $list['description'] }}</span>@endif
                        </td>
                        <td><span class="pf-badge {!! $tones[$list['channel']] ?? 'pf-tone-muted' !!}">{{ $channels[$list['channel']] ?? $list['channel'] }}</span></td>
                        <td>{{ $list['sourceLabel'] }}@if ($list['optOutList'] !== null)<span class="pf-row-desc">{{ t('and the opt-outs of %s', $list['optOutList']) }}</span>@endif</td>
                        <td><span class="pf-badge pf-status {!! $list['defaultOn'] ? 'pf-tone-ok' : 'pf-tone-muted' !!}">{{ $list['defaultOn'] ? t('in') : t('out') }}</span></td>
                        <td class="pf-num">{!! $list['count'] === null ? '<span class="pf-badge pf-tone-bad" title="' . $e($list['problem']) . '">' . $e(t('cannot count')) . '</span>' : $e(number_format($list['count'])) !!}</td>
                        <td class="pf-num">
                            <form method="post" action="{{ adminUrl('ConsentLists/delete/') . (int) $list['id'] }}" class="pf-inline-form" data-confirm="{{ t('Delete this consent list?') }}">
                                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-sm pf-btn-icon pf-btn-danger" aria-label="{{ t('Delete') }}" title="{{ t('Delete') }}">{!! Icon::svg('delete', '') !!}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
