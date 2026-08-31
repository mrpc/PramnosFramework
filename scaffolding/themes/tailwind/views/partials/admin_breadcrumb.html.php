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
// Inside the administration area, and the trail has to stay there: a bare
// `sURL` link drops the visitor onto the public copy of the same screen, with
// a different layout and no sidebar. `adminUrl()` is `sURL` for an application
// with no area configured.
$base        = adminUrl();
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

// The organization screens, whose label comes from the record the page is showing.
$org         = is_array($this->org ?? null) ? $this->org : [];
$orgId       = (int) ($org['organization_id'] ?? 0);
$orgName     = htmlspecialchars((string) ($org['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$orgLabel    = $orgName !== '' ? $orgName : ($orgId > 0 ? '#' . $orgId : 'Organization');
$orgsUrl     = $base . 'Organizations';

// Roles. `$this->role` is the model the roles screens assign, so the label is a
// property read rather than an array key like the organisation above it.
$roleId      = (int) ($this->role->roleid ?? 0);
$roleName    = trim((string) ($this->role->role_name ?? ''));
$roleLabel   = $roleName !== '' ? htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8')
    : ($roleId > 0 ? '#' . $roleId : 'Role');
$rolesUrl    = $base . 'Roles';
$roleViewUrl = $roleId > 0 ? $base . 'Roles/view/' . $roleId : $rolesUrl;
$orgViewUrl  = $orgId > 0 ? $base . 'Organizations/view/' . $orgId : $orgsUrl;

/**
 * A username for an id, for the "you came from this account" crumb.
 *
 * One lookup, shared: two screens now build that crumb — the token-action list and the mail
 * log — and a second copy of this query is a second place for it to drift.
 */
$nameOfUser = static function (int $userId): string {
    if ($userId < 1) {
        return '';
    }

    try {
        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#users')->select(['username'])->where('userid', $userId)->first();

        if ($row && ($row->numRows ?? 0) > 0) {
            return (string) ($row->fields['username'] ?? '');
        }
    } catch (\Exception $e) {
        // The numeric id is a usable crumb; a breadcrumb must not take a page down.
    }

    return '';
};

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
        $srcName  = $nameOfUser($fromUid);
        $srcLabel = htmlspecialchars($srcName !== '' ? $srcName : ('#' . $fromUid), ENT_QUOTES, 'UTF-8');
        $taTrail  = [['Users', $usersUrl], [$srcLabel, $base . 'users/view/' . $fromUid], [$tokenCrumb, '']];
    } else {
        $taTrail = [['Users', $usersUrl], ['Token Actions', $taUrl], [$tokenCrumb, '']];
    }
}

// Trail beyond Home / Dashboard, per page. Each entry: [label, url] — the last
// item's url is '' so it renders as the current (non-link) crumb.
/**
 * The mail log's trail, which depends on why you are looking at it.
 *
 * Opened from an account's own page the list is *that account's mail*, and the honest path is
 * the one the operator walked: Users → the person → their mail. Showing Dashboard → Emails
 * for a screen that is scoped to one person describes a journey nobody took, and it offers no
 * way back to the record they were reading.
 *
 * The origin travels in the link as `from=user&uid=N`, the same convention the token-action
 * list already uses.
 */
$mailAddress = trim((string) ($_GET['tomail'] ?? ''));
$emailsUrl   = $base . 'Emails';
$emailsTrail = [['Email history', '']];

if ($mailAddress !== '') {
    $addressCrumb = htmlspecialchars($mailAddress, ENT_QUOTES, 'UTF-8');

    if ($from === 'user' && $fromUid > 0) {
        $mailName  = $nameOfUser($fromUid);
        $mailLabel = htmlspecialchars($mailName !== '' ? $mailName : ('#' . $fromUid), ENT_QUOTES, 'UTF-8');
        $emailsTrail = [
            ['Users', $usersUrl],
            [$mailLabel, $base . 'users/view/' . $fromUid],
            ['Emails', ''],
        ];
    } else {
        /*
         * Filtered with no origin: work it out from the address.
         *
         * A URL is pasted, bookmarked and shared, and a trail that only reads correctly when
         * the caller remembered to append `from=user` is a trail that is usually wrong. The
         * address *is* the account, when exactly one account has it — so the same path is
         * offered without anybody having to carry it in the query string.
         */
        $ownerId = 0;

        try {
            $owner = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#users')
                ->select(['userid', 'username'])
                ->whereRaw('LOWER(email) = ?', [strtolower($mailAddress)])
                ->limit(2)
                ->getAll();
        } catch (\Exception $e) {
            $owner = [];
        }

        // Exactly one, or none: two accounts sharing an address is a data problem, and
        // guessing which one the operator meant would be worse than the generic trail.
        if (is_array($owner) && count($owner) === 1) {
            $ownerId = (int) ($owner[0]['userid'] ?? 0);
            $ownerNm = (string) ($owner[0]['username'] ?? '');
        }

        if ($ownerId > 0) {
            $emailsTrail = [
                ['Users', $usersUrl],
                [
                    htmlspecialchars($ownerNm !== '' ? $ownerNm : ('#' . $ownerId), ENT_QUOTES, 'UTF-8'),
                    $base . 'users/view/' . $ownerId,
                ],
                ['Emails', ''],
            ];
        } else {
            $emailsTrail = [['Email history', $emailsUrl], [$addressCrumb, '']];
        }
    }
}

$trails = [
    'users'             => [['Users', '']],
    'users_view'        => [['Users', $usersUrl], [$userLabel, '']],
    'users_edit'        => [['Users', $usersUrl], [$uid > 0 ? $userLabel : 'New User', '']],
    // With no account in view this is the whole site's list, and there is no person to put in
    // the trail. It read "Users / Anonymous / Sessions", linking to `users/view/1`: an
    // unloaded `User` has `username = "Anonymous"` and `userid = 1`, so the crumb named a
    // person who does not exist and pointed at somebody who might.
    //
    // Which is why the test is the controller's own flag rather than the id: a model default
    // that happens to look like real data is exactly what an `$uid > 0` check cannot see.
    'users_sessions'    => ($this->scopedToUser ?? true)
        ? [['Users', $usersUrl], [$userLabel, $userViewUrl], ['Sessions', '']]
        : [['Users', $usersUrl], ['Active sessions', '']],
    'users_tokens'      => [['Users', $usersUrl], [$userLabel, $userViewUrl], ['Tokens', '']],
    'users_activity'    => [['Users', $usersUrl], [$userLabel, $userViewUrl], ['Activity', '']],
    'users_notify'      => [['Users', $usersUrl], [$userLabel, $userViewUrl], ['Message', '']],
    'users_types'       => [['Users', $usersUrl], ['User types', '']],
    'roles'                  => [['Roles', '']],
    'roles_view'             => [['Roles', $rolesUrl], [$roleLabel, '']],
    'roles_edit'             => [['Roles', $rolesUrl], [$roleId > 0 ? $roleLabel : 'New Role', '']],
    'roles_members'          => [['Roles', $rolesUrl], [$roleLabel, $roleViewUrl], ['Holders', '']],
    'organizations'          => [['Organizations', '']],
    'organizations_view'     => [['Organizations', $orgsUrl], [$orgLabel, '']],
    'organizations_edit'     => [['Organizations', $orgsUrl], [$orgId > 0 ? $orgLabel : 'New Organization', '']],
    'organizations_members'  => [['Organizations', $orgsUrl], [$orgLabel, $orgViewUrl], ['Members', '']],
    'mailtemplates'      => [['Message templates', '']],
    'mailtemplates_view' => [['Message templates', $base . 'MailTemplates'], [htmlspecialchars((string) ($this->template['title'] ?? ''), ENT_QUOTES, 'UTF-8'), '']],
    'mailtemplates_edit' => [['Message templates', $base . 'MailTemplates'], [((int) ($this->template['templateid'] ?? 0)) > 0 ? 'Edit' : 'New template', '']],
    'emails'            => $emailsTrail,
    'emails_show'       => [['Email history', $emailsUrl], ['#' . (int) ($this->mail['id'] ?? 0), '']],
    'tokens'            => [['Tokens', '']],
    'tokens_view'       => [['Tokens', $tokensUrl], ['Token #' . (int) ($this->token['tokenid'] ?? 0), '']],
    'tokenactions'      => $taTrail,
    'tokenactions_show' => [['Users', $usersUrl], ['Token Actions', $taUrl], ['#' . $actionId, '']],
];

$bc = new \Pramnos\Html\Breadcrumb();
$bc->addItem('Home', adminUrl());
$bc->addItem('Dashboard', adminUrl('Dashboard'));
foreach ($trails[$active] ?? [] as $crumb) {
    $bc->addItem($crumb[0], $crumb[1]);
}
echo $bc->render();
