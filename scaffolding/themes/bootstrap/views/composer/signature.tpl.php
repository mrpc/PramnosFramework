@php /**
 * My signature (bootstrap theme): the HTML added under a message when the composer's "With my
 * signature" is ticked — written in the application's message editor.
 *
 * Variables:
 *   $this->signature — the signed-in account's signature, HTML
 */
$e  = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('My signature')), '']]); @endphp
<div class="container py-4">
    {!! $bc !!}
    <h2 class="mb-2">{{ t('My signature') }}</h2>
    <p class="text-muted small mb-3">{{ t('Added under a message when "With my signature" is ticked.') }}</p>
    <form method="post" action="{{ adminUrl('Composer/signature') }}">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        <textarea name="signature" id="pf-signature" rows="6" class="form-control" data-pf-editor="{{ \Pramnos\Html\MessageEditor::mode() }}">{{ $this->signature ?? '' }}</textarea>
        <button type="submit" class="btn btn-primary btn-sm mt-3">{{ t('Save') }}</button>
    </form>
</div>
{!! \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode()) !!}
