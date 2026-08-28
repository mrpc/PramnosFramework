<?php

namespace Pramnos\Auth;

/**
 * Notifies a user, if they asked to be, when an account sign-in comes from a
 * browser/platform combination that account has not used before.
 *
 * ## Opt-in, and stored without a migration
 *
 * The preference lives in `userdetails` — the framework's per-user key/value store,
 * the same table password-reset tokens use. That is deliberate: it needs no schema
 * change, so the feature works on every installation the moment it is upgraded,
 * including the ones whose `migration_cutoff` skips baseline migrations. It also
 * inherits the cascade on user deletion that table already has, which a GDPR-relevant
 * preference needs anyway.
 *
 * Absent means off. A security notification nobody asked for, describing a sign-in
 * they just performed, arriving from an address they may not recognise, is how a
 * feature like this teaches people to ignore it.
 *
 * ## What counts as "new"
 *
 * A {@see SignInFingerprint} — browser family and platform family, nothing else.
 * **Not the IP address**, which is dynamic on most consumer connections and would
 * fire on a router reboot; and not the browser version, which changes every four
 * weeks and would fire monthly. See that class for why the coarseness is the feature.
 *
 * ## Where the history comes from, and why it matters on day one
 *
 * From `authserver.user_activity_log`, which has recorded `user_agent` against every
 * `login` since the auth feature shipped.
 *
 * That choice is the difference between a feature and an incident. The obvious
 * alternative — start recording device fingerprints now and compare against those —
 * has no history on the day it is switched on, so **every user's next sign-in looks
 * new** and everyone who opted in is notified at once. Reading an audit trail that
 * already holds months of user agents means the first sign-in after upgrading is
 * recognised as familiar, which is what it is.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class NewSignInAlert
{
    /**
     * The site-wide policy setting: `optin` (default), `always` or `off`.
     *
     * Read through `Settings`, so it is editable on the settings screen and overridable
     * per environment like everything else there.
     */
    public const POLICY_SETTING = 'auth_newsignin_policy';

    /**
     * What a sign-in from an unrecognised device has to *do*, beyond being reported.
     *
     * Telling somebody after the fact is the weakest useful response: by the time the mail
     * arrives, whoever had the password is already inside. This setting is the other half
     * — what the login must satisfy before it completes:
     *
     *   - `notify`         — mail the account and let the login through (default; what
     *                        every installation had before this existed)
     *   - `require_2fa`    — demand a second factor for this sign-in, even from an account
     *                        that would not otherwise be asked
     *   - `require_passkey`— demand a passkey, which is the only factor that cannot be
     *                        phished or read out of a mailbox
     *   - `authlink`       — do not complete the login at all until a single-use link
     *                        mailed to the account is opened
     *
     * **Every one of them has to be satisfiable, or it is a lockout.** A demand the
     * account cannot meet — "use a passkey" to somebody who has none — would turn the
     * setting into a way to lose every user on deploy, so each falls back to the
     * strongest factor the account actually has, and to a mailed code last, because a
     * mailbox is the one thing every account has. What is never a fallback is *nothing*:
     * see {@see requiredFor()}.
     */
    public const ACTION_SETTING = 'auth_newsignin_action';

    /** The actions this setting accepts, strongest demand last. */
    public const ACTIONS = ['notify', 'authlink', 'require_2fa', 'require_passkey'];

    /**
     * *When* the action applies — which is a different question from what it is.
     *
     *   - `new_device`  — any browser this account has not been used from (default)
     *   - `suspicious`  — only when something harder to explain fires: a country the
     *                     account has never used, two places at once, a different country
     *                     too soon to have travelled, or a success straight after a run of
     *                     failed attempts
     *
     * The distinction matters more than it looks. "A device this account has not used"
     * fires constantly on a real user base — people buy phones, clear cookies, borrow a
     * laptop — so a demand attached to it is a step everybody pays on a regular basis, and
     * the usual result is that it gets switched off. `suspicious` spends the friction where
     * there is something to be suspicious about.
     *
     * The default is `new_device` because that is what this feature did before the signals
     * existed, and because it is the conservative reading: it asks more often, not less.
     *
     * @see SignInRisk for what each signal can and cannot see.
     */
    public const TRIGGER_SETTING = 'auth_newsignin_trigger';

    /** The triggers this setting accepts. */
    public const TRIGGERS = ['new_device', 'suspicious'];

    /**
     * The connection to use, or the framework's when none is given.
     *
     * Every method here takes one. That is not ceremony: a class that resolves its
     * own connection cannot be pointed at a test database, and cannot be pointed at
     * a second one by an application that has two. This framework has already
     * documented what the alternative costs — see `Service::database()`, where a
     * defaulted connection in a converted class would have silently repointed 59 call
     * sites.
     *
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return \Pramnos\Database\Database
     */
    private static function db(?\Pramnos\Database\Database $database = null): \Pramnos\Database\Database
    {
        return $database ?? \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * The `userdetails.fieldname` holding the opt-in.
     *
     * @var string
     */
    public const PREFERENCE = 'notify_new_signin';

    /**
     * How many previous sign-ins to consider.
     *
     * Bounded so a long-lived account does not read its entire history at every
     * login. A hundred sign-ins is far past the point where a person has stopped
     * acquiring new browsers, and the query is served by `idx_user_activity_userid`.
     *
     * @var int
     */
    private const HISTORY_LIMIT = 100;

    /**
     * Whether this user asked to be told.
     *
     * @param  int                             $userId   The user
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return bool
     */
    public static function isEnabledFor(int $userId, ?\Pramnos\Database\Database $database = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        /**
         * The site's own policy, which the per-user preference sits inside.
         *
         * The feature was per-user opt-in and nothing else: an operator could not turn it
         * on for everybody, and could not turn it off during an incident that was
         * generating thousands of sign-ins. Four values, and the default is the old
         * behaviour, so no installation changes by upgrading:
         *
         *   - `optin`  — the user's own preference decides, and silence means no (default)
         *   - `optout` — the same, except silence means **yes**: on for everybody who has
         *                not turned it off
         *   - `always` — every account is notified, whatever they chose
         *   - `off`    — nobody is, whatever they chose
         *
         * `always` is not hypothetical politeness: for a service where the account *is*
         * the product — an authentication server — telling somebody their credentials were
         * used from a new device is closer to an obligation than a setting.
         *
         * `optout` is that argument without taking the choice away. Under `optin` the
         * people who most need the mail are the ones who will never find the checkbox, so
         * a security feature ends up protecting the users who were already careful. The
         * account still owns the decision; it just starts from protected. (Nothing about
         * that is a data-protection problem: the mail goes to the address the account
         * signed up with, about that account's own sign-in, and it can be turned off in
         * one click.)
         */
        $policy = (string) (\Pramnos\Application\Settings::getSetting(self::POLICY_SETTING) ?: 'optin');
        if ($policy === 'off') {
            return false;
        }
        if ($policy === 'always') {
            return true;
        }

        try {
            $value = self::db($database)->queryBuilder()
                ->table('#PREFIX#userdetails')
                ->where('userid', $userId)
                ->where('fieldname', self::PREFERENCE)
                ->value('value');
        } catch (\Throwable) {
            // A preference that cannot be read is not a reason to fail a login, and
            // the safe answer for "should we send mail" is no — under `optout` too. A
            // failed query is not consent, and a login storm caused by a broken
            // `userdetails` would otherwise mail the entire user base.
            return false;
        }

        if ($policy === 'optout') {
            // Absent means on. `setEnabledFor()` writes '0' rather than deleting the row,
            // so a user who turned it off stays off — the two cases are distinguishable,
            // which is what makes this policy possible at all.
            return (string) $value !== '0';
        }

        return (string) $value === '1';
    }

    /**
     * Turn the notification on or off for a user.
     *
     * @param  int                             $userId   The user
     * @param  bool                            $enabled  Whether to notify
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return void
     */
    public static function setEnabledFor(int $userId, bool $enabled, ?\Pramnos\Database\Database $database = null): void
    {
        if ($userId <= 0) {
            return;
        }

        self::db($database)->queryBuilder()
            ->table('#PREFIX#userdetails')
            ->upsert(
                array(
                    'userid'    => $userId,
                    'fieldname' => self::PREFERENCE,
                    'value'     => $enabled ? '1' : '0',
                ),
                array('userid', 'fieldname'),
                array('value')
            );
    }

    /**
     * The fingerprints this account has signed in with before.
     *
     * @param  int    $userId  The user
     * @param  string $exclude A fingerprint to leave out — the current sign-in, whose
     *                         own log row has usually been written by the time this
     *                         runs, and which would otherwise always match itself
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return array<string, true> Fingerprint => true
     */
    public static function knownFingerprints(int $userId, string $exclude = '', ?\Pramnos\Database\Database $database = null): array
    {
        $known = array();

        try {
            $rows = self::db($database)->queryBuilder()
                ->table('authserver.user_activity_log')
                ->select('user_agent')
                ->where('userid', $userId)
                ->where('action', 'login')
                ->orderBy('created_at', 'desc')
                ->limit(self::HISTORY_LIMIT)
                ->getAll();
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'NewSignInAlert could not read sign-in history: ' . $ex->getMessage(),
                'auth'
            );

            // No history is not the same as no matches. Returning an empty set here
            // would mean "everything is new", and the caller would notify — turning a
            // database hiccup into mail to the user. The caller treats a failure as
            // "say nothing", which is why this is distinguishable from a real empty.
            return array();
        }

        foreach ((array) $rows as $row) {
            $fingerprint = SignInFingerprint::fromUserAgent($row['user_agent'] ?? null);
            if ($fingerprint !== $exclude) {
                $known[$fingerprint] = true;
            }
        }

        return $known;
    }

    /**
     * Check the sign-in that just happened, and notify if it warrants it.
     *
     * Called from the login lifecycle **after** the activity log has recorded this
     * sign-in, which is why {@see isNew()} excludes the fingerprint it is testing —
     * otherwise every sign-in matches its own row and this silently never fires.
     *
     * Best-effort throughout. A notification is not worth failing a login for, and
     * the failure modes here are all external: an unreachable mail server, a log
     * table that does not exist, a user record that cannot be loaded.
     *
     * @param  int                             $userId   Who just signed in
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return bool Whether a notification was sent — for callers that want to log it
     */
    public static function checkAndNotify(int $userId, ?\Pramnos\Database\Database $database = null): bool
    {
        try {
            if (!self::isEnabledFor($userId, $database)) {
                return false;
            }

            $fingerprint = SignInFingerprint::current();
            if (!self::isNew($userId, $fingerprint, $database)) {
                return false;
            }

            $user = new \Pramnos\User\User($userId);
            if (empty($user->email)) {
                // Nothing to send to. Not an error: an account can exist without a
                // deliverable address, and the alternative — throwing — would fail a
                // login over a notification.
                return false;
            }

            (new \Pramnos\Notification\Notifier())->sendNow(
                $user,
                new Notifications\NewSignInNotification($fingerprint)
            );

            return true;
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'New sign-in notification failed: ' . $ex->getMessage(),
                'auth'
            );

            return false;
        }
    }

    /**
     * Whether this sign-in is from a combination the account has not used.
     *
     * **An account with no history at all answers `false`.** A first-ever sign-in is
     * new by definition and notifying about it tells the person something they are
     * currently doing, from an account they just created. It is also what every
     * account looks like on the day this ships if the history source is empty — see
     * the class docblock.
     *
     * @param  int    $userId      The user
     * @param  string $fingerprint The current sign-in's fingerprint
     * @param  \Pramnos\Database\Database|null $database Explicit connection
     * @return bool
     */
    public static function isNew(int $userId, string $fingerprint, ?\Pramnos\Database\Database $database = null): bool
    {
        $known = self::knownFingerprints($userId, $fingerprint, $database);

        if ($known === array()) {
            return false;
        }

        return !isset($known[$fingerprint]);
    }

    /**
     * The configured action, or `notify` when nothing (or nonsense) is configured.
     *
     * Unrecognised values fall back to `notify` rather than to the strictest reading. A
     * typo in a settings row must not start demanding passkeys from a user base that has
     * none — failing open on a *typo* is right here, because the strict readings are the
     * ones that can lock everybody out, and the setting is visible on a screen where a
     * value that does nothing gets noticed.
     */
    public static function trigger(): string
    {
        $configured = (string) (\Pramnos\Application\Settings::getSetting(self::TRIGGER_SETTING) ?: 'new_device');

        return in_array($configured, self::TRIGGERS, true) ? $configured : 'new_device';
    }

    public static function action(): string
    {
        $configured = (string) (\Pramnos\Application\Settings::getSetting(self::ACTION_SETTING) ?: 'notify');

        return in_array($configured, self::ACTIONS, true) ? $configured : 'notify';
    }

    /**
     * What this account must satisfy for a sign-in from an unrecognised device.
     *
     * Returns the step-up methods to demand — `[]` when nothing is demanded, which is the
     * answer for a recognised device, for the `notify` action, and for an account whose
     * history is empty (a first-ever sign-in is new by definition; demanding a second
     * factor from somebody who has just registered is a wall, not a defence).
     *
     * The resolution, in order, is the whole reason this is one method rather than a
     * condition at each call site:
     *
     *   - `authlink` asks for the link and nothing else. It is satisfiable by every
     *     account, so it never needs a fallback.
     *   - `require_passkey` asks for a passkey when the account has one. When it does not,
     *     asking would be a lockout, so it drops to `require_2fa`'s answer.
     *   - `require_2fa` asks for the authenticator app when the account has one, and for a
     *     mailed code when it does not — **imposed for this sign-in regardless of the
     *     account's own email-factor switch**, because the demand is the site's, not the
     *     account's, and a mailbox is the one factor every account has.
     *
     * The imposed code is why `email` can appear here for an account that never asked for
     * it. That is deliberate and it is the only way `require_2fa` means anything for the
     * accounts that have set nothing up — which are the accounts a stolen password
     * threatens most.
     *
     * **Whether the device is new is the caller's answer, not this method's.** That
     * question costs a query against the activity log, and this is otherwise pure policy —
     * a resolver that read the database could not be tested without one, and the caller
     * already has to decide whether the query is worth making at all (it is not, when the
     * action is `notify`).
     *
     * @param  int  $userId       Who is signing in
     * @param  bool $isNewDevice  Has this account been used from this device before?
     * @param  bool $hasTotp      Does the account have an authenticator app?
     * @param  bool $hasPasskey   Does the account have a passkey?
     * @return string[] Step-up methods to demand, in the order to offer them
     */
    public static function requiredFor(
        int $userId,
        bool $isNewDevice,
        bool $hasTotp,
        bool $hasPasskey
    ): array {
        $action = self::action();
        if ($action === 'notify' || $userId < 2 || !$isNewDevice) {
            return array();
        }

        if ($action === 'authlink') {
            return array('authlink');
        }

        if ($action === 'require_passkey' && $hasPasskey) {
            // The app is offered beside it when the account has one: a passkey the person
            // left at home must not strand them when they are carrying a second factor
            // the site already trusts.
            return $hasTotp ? array('passkey', 'twofactor') : array('passkey');
        }

        if ($hasTotp) {
            return $hasPasskey ? array('twofactor', 'passkey') : array('twofactor');
        }

        return array(EmailSecondFactor::METHOD);
    }
}
