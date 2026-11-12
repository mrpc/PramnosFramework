@php /**
 * Anybody's artifact, read only, inside the administration — `/admin/Ai/artifact/{id}`, opened
 * from a person's card in the assistants' control centre.
 *
 * Variables:
 *   $this->artifact — Artifacts::shape(); $this->embed — an artifact a document holds, or null
 *   $this->person   — the owner's name; $this->urls — all, card, print
 *
 * Its print form is the same artifact on a page of its own (`?print=1`).
 */
$c = ['card' => 'card bg-base-100 border border-base-300', 'muted' => 'text-sm opacity-70', 'btn' => 'btn btn-sm btn-primary', 'btnGhost' => 'btn btn-sm btn-ghost', 'table' => 'table table-sm', 'h1' => 'text-2xl font-semibold'];
$a = is_array($this->artifact ?? null) ? $this->artifact : [];
$u = (is_array($this->urls ?? null) ? $this->urls : []) + ['all' => '', 'card' => '', 'print' => '']; @endphp
<div class="px-4 py-6">
    {!! \Pramnos\Html\Flash::render() !!}
    <p class="{!! $c['muted'] !!}"><a href="{{ $u['all'] }}">{{ t('The assistants') }}</a> › <a href="{{ $u['card'] }}">{{ (string) ($this->person ?? '') }}</a> › {{ (string) ($a['title'] ?? '') }}</p>
    <h1 class="{!! $c['h1'] !!}">{{ (string) ($a['title'] ?? '') }}</h1>
    <p><a class="{!! $c['btnGhost'] !!}" href="{{ $u['card'] }}">‹ {{ t('Back to the card') }}</a> <a class="{!! $c['btn'] !!}" href="{{ $u['print'] }}" target="_blank" rel="noopener">{{ t('Print or save as PDF') }}</a></p>
    <div class="{!! $c['card'] !!}" style="padding:12px">{!! \Pramnos\Llm\Chat\Web\ArtifactView::html($a, '', $c['table'], $c['muted'], $this->embed ?? null) !!}</div>
</div>
