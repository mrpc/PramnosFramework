<?php
/**
 * Admin-area breadcrumb trail (tailwind theme).
 *
 * Renders Home / Dashboard / … / <current page> using the framework's
 * Pramnos\Html\Breadcrumb, driven by $this->activeNav. Include at the top of
 * every admin page so the trail reflects the real hierarchy (Token Actions and
 * a user's Sessions/Tokens are children of Users) instead of ad-hoc "Back"
 * buttons — mirrors the account area's partials/account_breadcrumb.
 *
 * Context (read from the including View when present):
 *   $this->activeNav — key of the current page (see $trails below)
 *   $this->user      — user row array (for the per-user sub-pages)
 *   $this->action    — token-action row array (for the tokenactions show page)
 *
 * The Token Actions list can be reached filtered by token_id from two places —
 * the Tokens admin list and a user's detail page. Links carry a `from` query
 * param (from=tokens | from=user&uid=N) so the trail reflects where the user
 * actually came from instead of a single hard-coded parent.
 *
 * Breadcrumb::render() does not escape labels, so every dynamic label is passed
 * through htmlspecialchars here.
 */
$base        = sURL;
$usersUrl    = $base . 'users';
$tokensUrl   = $base . 'Tokens';
$taUrl       = $base . 'TokenActions';
$active      = $this->activeNav ?? '';

$u           = $this->user ?? [];
$uid         = (int) ($u['userid'] ?? 0);
$uname       = htmlspecialchars((string) ($u['username'] ?? ''), ENT_QUOTES, 'UTF-8');
$userLabel   = $uname !== '' ? $uname : ('#' . $uid);
$userViewUrl = $uid > 0 ? $base . 'users/view/' . $uid : $usersUrl;
$actionId    = (int) ($this->action['actionid'] ?? 0);

// Context-aware trail for the Token Actions list. When filtered by a token it
// distinguishes the origin (Tokens list vs a specific user) via the `from` param.
$tokenId  = (int) ($_GET['token_id'] ?? 0);
$from     = (string) ($_GET['from'] ?? '');
$fromUid  = (int) ($_GET['uid'] ?? 0);
$taTrail  = [['Users', $usersUrl], ['Token Actions', '']];
if ($tokenId > 0) {
    $tokenCrumb = 'Token #' . $tokenId;
    if ($from === 'tokens') {
        $taTrail = [['Tokens', $tokensUrl], [$tokenCrumb, '']];
    } elseif ($from === 'user' && $fromUid > 0) {
        // Resolve the source user's name for a friendlier crumb.
        $srcName = '';
        try {
            $srcRow = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#users')->select(['username'])->where('userid', $fromUid)->first();
            if ($srcRow && ($srcRow->numRows ?? 0) > 0) {
                $srcName = (string) ($srcRow->fields['username'] ?? '');
            }
        } catch (\Exception $e) {
            // fall back to the numeric id below
        }
        $srcLabel = htmlspecialchars($srcName !== '' ? $srcName : ('#' . $fromUid), ENT_QUOTES, 'UTF-8');
        $taTrail  = [['Users', $usersUrl], [$srcLabel, $base . 'users/view/' . $fromUid], [$tokenCrumb, '']];
    } else {
        $taTrail = [['Users', $usersUrl], ['Token Actions', $taUrl], [$tokenCrumb, '']];
    }
}

// Trail beyond Home / Dashboard, per page. Each entry: [label, url] — the last
// item's url is '' so it renders as the current (non-link) crumb.
$trails = [
    'users'             => [['Users', '']],
    'users_view'        => [['Users', $usersUrl], [$userLabel, '']],
    'users_edit'        => [['Users', $usersUrl], [$uid > 0 ? $userLabel : 'New User', '']],
    'users_sessions'    => [['Users', $usersUrl], [$userLabel, $userViewUrl], ['Sessions', '']],
    'users_tokens'      => [['Users', $usersUrl], [$userLabel, $userViewUrl], ['Tokens', '']],
    'tokens'            => [['Tokens', '']],
    'tokenactions'      => $taTrail,
    'tokenactions_show' => [['Users', $usersUrl], ['Token Actions', $taUrl], ['#' . $actionId, '']],
];

$bc = new \Pramnos\Html\Breadcrumb();
$bc->addItem('Home', sURL);
$bc->addItem('Dashboard', sURL . 'Dashboard');
foreach ($trails[$active] ?? [] as $crumb) {
    $bc->addItem($crumb[0], $crumb[1]);
}
echo $bc->render();
