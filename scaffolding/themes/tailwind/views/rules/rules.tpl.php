@php /**
 * The rules: each with its status, its trigger, and how it is doing over the week. The same markup
 * in every theme.
 *
 * Variables:
 *   $this->rules — Rules::all(), each with trigger (in words), entered (seven days), running, goals
 */
use Pramnos\Html\Icon;

$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$tones = ['draft' => 'pf-tone-muted', 'shadow' => 'pf-tone-info', 'active' => 'pf-tone-ok', 'paused' => 'pf-tone-warn']; @endphp
<div class="pf-ui">
    @php $this->activeNav = 'rules'; $this->insert('../partials/admin_breadcrumb'); @endphp
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title">{{ t('Rules') }}</h1>
        </div>
        <div class="pf-page-actions">
            <a href="{{ adminUrl('Rules/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New rule') }}</a>
        </div>
    </header>
    @if (!empty($this->rules))
        <div class="pf-toolbar">
            <span></span>
            <label class="pf-search">
                {!! Icon::svg('search', '') !!}
                <input type="search" class="pf-input" data-pf-filter-rows="#pf-rules-table" placeholder="{{ t('Search by name') }}" aria-label="{{ t('Search by name') }}">
            </label>
        </div>
    @endif
    <div class="pf-card pf-card-flush">
        @if (empty($this->rules))
            <div class="pf-empty">
                <span class="pf-empty-icon">{!! Icon::svg('rule', '') !!}</span>
                <p class="pf-empty-title">{{ t('No rules yet') }}</p>
                <p class="pf-empty-text">{{ t('A rule is when something happens, who it is for, what to do, and until when: a welcome series, a reminder, a follow-up.') }}</p>
                <a href="{{ adminUrl('Rules/edit') }}" class="pf-btn pf-btn-primary">{!! Icon::svg('plus', '') !!}{{ t('New rule') }}</a>
            </div>
        @else
            <table class="pf-table" id="pf-rules-table">
                <thead>
                    <tr><th>{{ t('Name') }}</th><th>{{ t('Rule status') }}</th><th>{{ t('When') }}</th><th class="pf-num">{{ t('Entered, 7 days') }}</th><th class="pf-num">{{ t('Under way') }}</th><th class="pf-num">{{ t('Goal reached') }}</th></tr>
                </thead>
                <tbody>
                @foreach ($this->rules as $rule)
                    <tr data-pf-row-text="{{ mb_strtolower($rule['name']) }}">
                        <td>
                            <a class="pf-row-link" href="{{ adminUrl('Rules/view/') . $rule['id'] }}">{{ $rule['name'] }}</a>
                            <span class="pf-row-desc">{{ t('version %s', (string) $rule['version']) }}</span>
                        </td>
                        <td><span class="pf-badge pf-status {!! $tones[$rule['status']] ?? 'pf-tone-muted' !!}">{{ t($rule['status']) }}</span></td>
                        <td>{{ $rule['trigger'] }}</td>
                        <td class="pf-num">{{ number_format($rule['entered']) }}</td>
                        <td class="pf-num">{{ number_format($rule['running']) }}</td>
                        <td class="pf-num">{{ number_format($rule['goals']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
