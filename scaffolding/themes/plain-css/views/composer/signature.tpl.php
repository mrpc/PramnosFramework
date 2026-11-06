@php /**
 * My signature (plain-css theme): the HTML added under a message when the composer's "With my
 * signature" is ticked — written in the application's message editor.
 *
 * Variables:
 *   $this->signature — the signed-in account's signature, HTML
 */
$e  = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('My signature')), '']]); @endphp
<div class="page-section">
    {!! $bc !!}
    <h2>{{ t('My signature') }}</h2>
    <p>{{ t('Added under a message when "With my signature" is ticked.') }}</p>
    <form method="post" action="{{ adminUrl('Composer/signature') }}">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        <textarea name="signature" id="pf-signature" rows="6" data-pf-editor="{{ \Pramnos\Html\MessageEditor::mode() }}">{{ $this->signature ?? '' }}</textarea>
        <button type="submit">{{ t('Save') }}</button>
    </form>
</div>
{!! \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode()) !!}
