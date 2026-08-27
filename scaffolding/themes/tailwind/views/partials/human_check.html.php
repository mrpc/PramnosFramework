<?php
/**
 * The proof-of-work human check's two hidden fields, and the attribute that wires them.
 *
 * Inserted inside a `<form>` on the public auth screens. Renders **nothing** when the
 * application has not switched the check on for that form, so the same insert is safe on
 * every one of them.
 *
 * Context:
 *   $this->humanCheck — the challenge array from HumanCheck::challenge(), or null
 *
 * The `data-pf-humancheck` attribute has to sit on the form, which a partial inserted
 * *inside* the form cannot reach — so it is set from here with a one-line script rather
 * than by asking every view to remember it. That is also why the script carries the
 * nonce: a project with a strict CSP would otherwise drop it silently and the check would
 * fail closed for every visitor.
 */
$challenge = $this->humanCheck ?? null;
if (!is_array($challenge) || empty($challenge['challenge'])) {
    return;
}

$json  = json_encode($challenge, JSON_UNESCAPED_SLASHES);
$nonce = \Pramnos\Application\Application::currentInstance()?->cspNonce ?? '';
$id    = 'pf-hc-' . substr(hash('sha256', (string) $challenge['challenge']), 0, 8);
?>
<input type="hidden" name="human_challenge" value="<?php echo htmlspecialchars((string) $challenge['challenge'], ENT_QUOTES, 'UTF-8'); ?>">
<input type="hidden" name="human_solution" value="" id="<?php echo $id; ?>">
<script<?php echo $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : ''; ?>>
(function () {
    var field = document.getElementById('<?php echo $id; ?>');
    if (field && field.form) {
        field.form.setAttribute('data-pf-humancheck', <?php echo json_encode($json, JSON_UNESCAPED_SLASHES); ?>);
    }
})();
</script>
<script<?php echo $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : ''; ?> src="<?php echo sURL; ?>assets/js/pf-humancheck.js"></script>
