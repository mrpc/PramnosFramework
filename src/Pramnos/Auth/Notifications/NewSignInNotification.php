<?php

namespace Pramnos\Auth\Notifications;

use Pramnos\Auth\SignInFingerprint;
use Pramnos\Notification\NotificationInterface;

/**
 * "Your account was signed in to from a browser you have not used before."
 *
 * Sent only to people who asked for it — see {@see \Pramnos\Auth\NewSignInAlert}.
 *
 * ## What it says, and what it deliberately does not
 *
 * It names the browser and the kind of device, and the time. It does **not** name the
 * IP address, and that is a decision rather than an omission:
 *
 *   - it is not evidence a person can act on. Nobody recognises their own address,
 *     and on a mobile network it is not theirs in any meaningful sense;
 *   - printing one invites the reader to compare it with the last mail, which is
 *     exactly the comparison this feature refuses to make — consumer addresses are
 *     dynamic, and a person taught to worry about a changed address will worry every
 *     few days, forever.
 *
 * The address is in the audit log for an administrator investigating an incident.
 * That is the right audience for it.
 *
 * ## The action it offers
 *
 * One: *if this was not you, change your password.* Not a "this wasn't me" link —
 * a link in an unexpected security email is the shape of the attack it warns about,
 * and training people to click one is worse than sending nothing.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class NewSignInNotification implements NotificationInterface
{
    /**
     * The fingerprint of the sign-in being reported.
     *
     * @var string
     */
    private string $fingerprint;

    /**
     * When it happened, as a Unix timestamp.
     *
     * @var int
     */
    private int $when;

    /**
     * The site's own name, for the subject line.
     *
     * @var string
     */
    private string $siteName;

    /**
     * @param string $fingerprint As produced by {@see SignInFingerprint}
     * @param int    $when        Unix timestamp; defaults to now
     * @param string $siteName    Shown in the subject; falls back to the URL
     */
    public function __construct(string $fingerprint, int $when = 0, string $siteName = '')
    {
        $this->fingerprint = $fingerprint;
        $this->when        = $when > 0 ? $when : time();
        $this->siteName    = $siteName !== ''
            ? $siteName
            : (defined('URL') ? (string) URL : 'this site');
    }

    /**
     * Channels this goes out on: mail always, and push where there is a browser for it.
     *
     * **Not the database channel**, and that exclusion is the older half of this decision: an
     * in-app notification puts a security warning in the panel of the session that triggered
     * it — visible to whoever just signed in, who in the case worth warning about is the wrong
     * person. What matters is reaching the account's *owner* rather than its current user.
     *
     * Push passes that same test, which is why it is here. A browser receives a push only
     * because somebody granted permission in it earlier, so the subscriptions on an account are
     * the owner's devices — not the one the sign-in just happened on. Same property as mail,
     * arriving in seconds instead of whenever the mailbox is next opened, which for "somebody
     * else is inside your account right now" is the whole difference.
     *
     * Not *instead of* mail. A browser can be closed, unsubscribed, or have its site data
     * cleared; the mail is the copy that survives all three and is still there tomorrow as a
     * record.
     *
     * Asked only when there is something to ask: {@see \Pramnos\Push\Subscriptions::exist()}
     * is an indexed existence check, and without it every account that never subscribed would
     * write a "nothing subscribed" row to the push log on every alert.
     *
     * @param  mixed $notifiable The recipient
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $channels = array('mail');

        if (\Pramnos\Push\Subscriptions::exist(self::accountOf($notifiable))) {
            $channels[] = 'push';
        }

        return $channels;
    }

    /**
     * Which account a notifiable is, in the order every channel resolves it.
     *
     * `routeNotificationFor('push')` first, then `userid`, then `id` — the same order
     * `PushChannel` and the database channel use, so an object that works for one works for
     * all of them. Zero when it is none of those, which makes the push check answer false and
     * the mail go out alone.
     */
    private static function accountOf(mixed $notifiable): int
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $routed = $notifiable->routeNotificationFor('push');

            if (is_numeric($routed)) {
                return (int) $routed;
            }
        }

        foreach (array('userid', 'id') as $property) {
            if (is_object($notifiable) && isset($notifiable->$property)) {
                return (int) $notifiable->$property;
            }
        }

        return 0;
    }

    /**
     * The same fact, in the length a notification has.
     *
     * A push is a title and two lines on a lock screen. It carries **no link that acts** — the
     * mail has those, behind a signed token. A notification that appears unprompted and offers
     * a button is the exact shape of the attack this warns about, so this one only says what
     * happened and where to look.
     *
     * `tag` collapses repeats: two sign-ins from two devices should not stack into two
     * notifications that say almost the same thing.
     *
     * @param  mixed $notifiable
     * @return array<string, mixed>
     */
    public function toPush(mixed $notifiable): array
    {
        return array(
            'title' => t('New sign-in to your account'),
            'body'  => t(
                'From %s. If this was not you, change your password now.',
                SignInFingerprint::describe($this->fingerprint)
            ),
            'tag'   => 'newsignin',
        );
    }



    /**
     * The message.
     *
     * @param  mixed $notifiable The recipient
     * @return array<string, string>
     */
    /**
     * This one belongs to a list, so it carries an unsubscribe link.
     *
     * The only notification the framework sends that does: the account can already turn these
     * alerts off on its privacy screen, so there is a real preference for the link to act on —
     * and honouring an unsubscribe flips that same checkbox, rather than leaving a switch that
     * says "on" while a record elsewhere suppresses the mail.
     *
     * It matters most under the `optout` policy, where the mail arrives without having been
     * asked for. A message somebody did not choose, with no way out except the spam button, is
     * exactly what mailbox providers count — and they count it against the password resets
     * too.
     */
    public function unsubscribeList(): string
    {
        return 'newsignin';
    }

    public function toMail(mixed $notifiable): array
    {
        $device = SignInFingerprint::describe($this->fingerprint);
        $time   = date('j M Y, H:i T', $this->when);

        $safeDevice = htmlspecialchars($device, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTime   = htmlspecialchars($time, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<p>' . t(
                'Your account was just signed in to from <strong>%s</strong>, which this '
                . 'account has not been used from before.',
                $safeDevice
            ) . '</p>'
            . '<p>' . t('Time: %s', $safeTime) . '</p>'
            . '<p>' . t(
                'If this was you, there is nothing to do — you will not be told again about '
                . 'this browser.'
            ) . '</p>'
            . '<p>' . t(
                '<strong>If it was not you, change your password now.</strong> Open the site '
                . 'yourself rather than following a link in an email, including this one.'
            ) . '</p>';

        return array(
            'subject' => t('New sign-in to your account on %s — %s', $this->siteName, $device),
            'body'    => $body,
        );
    }

    /**
     * The fingerprint being reported.
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }
}
