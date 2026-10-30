---
use_cases:
  - Offering a passkey in the username field's autofill
  - Signing in with a passkey from a SPA
  - Understanding what the scaffolded sign-in screen does and says when it fails
---

# Passkeys

Part of the [authentication guide](Pramnos_Authentication_Guide.md).

## A passkey inside the username autofill

The passkey button is not where most people sign in with one. Conditional mediation puts the
credential in the username field's own autofill list, so signing in is one tap on a suggestion
instead of noticing a second button and choosing it. The scaffolded sign-in screen does this out of
the box; two things make it work.

**The field declares it.** The `webauthn` token is how the specification marks the field a passkey
suggestion belongs in:

```html
<input type="text" name="username" id="username"
       autocomplete="username webauthn"
       autocapitalize="none" autocorrect="off" spellcheck="false" required>
```

**The ceremony is started on load and left waiting.** `pf-auth.js` does it when the page has both
that field and a `[data-pf-passkey-login]` button to read the URLs from. The promise settles when
somebody picks a passkey — which may be never, and that is the normal case.

```js
window.PramnosWebAuthn.conditional(optionsUrl, verifyUrl)
    .then(function (result) { if (result) { window.location = result.redirect; } });
```

!!! warning "A browser allows one outstanding `credentials.get()`"
    A conditional request holds it for the life of the page. So switching this on naively **breaks
    the passkey button**: the second ceremony is refused, silently, and nothing on the page says why.

    `PramnosWebAuthn.authenticate()` cancels any pending conditional request before starting its own,
    which is what keeps both paths working. If you write your own button, call
    `PramnosWebAuthn.cancelConditional()` first.

Three cases stand down rather than interfere, and each matters for a reason:

- **`isConditionalMediationAvailable()` is absent** — older browsers. Calling it there is a
  `TypeError` on the sign-in page, so it is feature-detected.
- **it answers false** — no ceremony is started at all. Starting one that then failed would hold the
  single outstanding `get()` and break the button for exactly the browsers that need it most.
- **the person cancels, or uses the password form** — an `AbortError`, which is the normal ending of
  a conditional ceremony and is not reported. An error message about a ceremony nobody asked for,
  appearing while somebody types a password, is worse than no message.

The client is tested by being run: `ConditionalMediationClientTest` loads the shipped
`pf-webauthn.js` under Node against a stubbed `navigator.credentials` and asserts, among the rest,
that pressing the button cancels the waiting ceremony and still signs the person in.

**Email is offered precisely because it needs nothing set up in advance.** An
authenticator app protects an account only if the person installed one before the day
they needed it, and most have not. Mail is a channel every account already has, so it is
the only second factor that can be turned on for everybody — and a weak second factor
is a large improvement on a password alone.

It is never ranked above TOTP. An account with both is asked for the app and *offered*
mail as the fallback; reversing that would quietly downgrade every account that had done
the stronger thing.

### Adding a factor of your own — SMS, a push, anything

The three the framework ships — `totp`, `email` and `push` — are adaptors like any other, held
in `Pramnos\Auth\SecondFactorRegistry` (passkeys sit outside the registry). An application adds
its own from a service
provider's `boot()`:

```php
\Pramnos\Auth\SecondFactorRegistry::register(new \MyApp\Auth\SmsSecondFactor());
```

`Pramnos\Auth\SecondFactorInterface` is seven methods:

```php
public function name(): string;                 // 'sms' — travels in forms and the audit log
public function label(): string;                // 'Text message' — what a person is shown
public function strength(): int;                // ordering: app 60, SMS ~40, mailed code 20
public function isEnrolledFor(int $userId): bool;
public function needsSending(): bool;           // true for SMS, false for an authenticator app
public function send(int $userId): bool;
public function verify(int $userId, string $code): bool;
```

Nothing else changes. The login offers it, the step-up screen renders it, the audit log
records which factor carried the sign-in, and `auth_newsignin_action` can demand it —
without the framework knowing your gateway exists.

**Three obligations, because the flow cannot check them for you.**

- **`isEnrolledFor()` must be a promise.** True means `verify()` can succeed. An SMS
  adaptor with no phone number on file must answer false, or the login offers a step-up
  nobody can complete — a lockout wearing the clothes of a security feature.
- **`isEnrolledFor()` must not send.** It is called while deciding what to offer,
  including on pages that are never submitted. Sending there texts somebody on every
  failed password attempt, at your expense.
- **What you send must expire, be single-use and be attempt-capped.** Only the adaptor
  knows what it issued, so the flow cannot enforce it. `EmailSecondFactor` is the worked
  example: ten minutes, five attempts and then the code is *destroyed*, single use.

Registering is not enabling: `auth.twofactor_methods` still decides. A shared codebase can
register an SMS adaptor everywhere and a deployment that does not pay for the gateway
leaves it out of the list — no code change, and accounts stay enrolled for the day it comes
back.

Registering under an existing name replaces it, which is how an application routes the
mailed code through its own transactional provider: register your own `email` and it is
used instead.

### Turning the email factor on

Two switches, answering different questions. **The application decides the method
exists:**

```php
// app/app.php
'auth' => [
    'twofactor_methods' => ['totp', 'email'],
],
```

The default is `['totp']`, so an installation that does not ask for this gets exactly
what it had. `totp` is always in the list whether or not you write it — an application
cannot switch off the method its existing accounts are enrolled in by adding a config
key, which is what omitting it would otherwise mean.

**Declaring it is not enough on its own: the installation has to be able to send mail.**
`EmailSecondFactor::isAvailable()` is false while `smtp_host` is empty
(`Email::isConfigured()`). A code nobody can receive is not a factor, so with no mail the
method is not offered, and an account that enabled it is treated as not having it rather
than asked for digits that never arrive.

**The account decides it wants it**, from its own security screen, behind its own
password (`user_twofactor.email_enabled`). Attaching a second factor is a change to how
an account authenticates, so a borrowed session must not be able to make it — in either
direction. An operator cannot turn it on for somebody: that would be adding a credential
to another person's mailbox. The administration screen shows the state and nothing more.

Because the two switches are independent, an application can withdraw the method without
touching a single account row, and turning it back on restores each account's own answer.

### What makes a six-digit code safe enough

Not the hashing. A million possibilities is nothing to a KDF, so the code is stored as an
HMAC keyed by the installation secret *and* the user id — enough that a leaked table
hands out no live codes and a row copied between accounts is worthless, and no more than
that. What makes it safe is three limits, all enforced inside
`Pramnos\Auth\EmailSecondFactor`:

- **ten minutes**, after which it is refused;
- **five attempts**, after which the code is *destroyed* rather than merely refused — a
  code left alive after the cap can be guessed at while its owner is still holding it;
- **single use** — a correct verification deletes the row, so an intercepted code is
  spent.

Asking again replaces the code rather than adding one, so "send it again" never leaves
two live codes.

### Sending, and when not to

A code is **not** mailed when the password is accepted. It is mailed when the screen asks:

```php
$flow->sendEmailCode();          // false when nothing is pending, or no address
$flow->hasLiveEmailCode();       // so the screen can ask without sending
$flow->completeEmailCode($code); // tagged `email`, not `twofactor`
```

Sending on password success would mail an account that has an app and email as a fallback
on every sign-in it never reads — and would mail one for each of somebody else's failed
password attempts, which is a way to use your login form as somebody else's mail flood.

The completion is recorded as its own method so an audit can tell which factor actually
carried a login. They are not equally strong, and a log that calls them the same thing
cannot answer that afterwards.

## Passkeys from a SPA

A single-page application authenticates with a bearer token, not a session cookie, and its code is
modules rather than `<script>` tags. Neither difference touches the ceremony — a passkey sign-in is
the same conversation with the authenticator either way — so a SPA runs **the framework's own
WebAuthn client**, delivered as an ES module.

What a scaffolded SPA gets, without asking:

| Piece | Where | What it is |
|---|---|---|
| `Pramnos\Auth\Controllers\ApiPasskey` | `Api\Controllers\Passkey` in the project | The web `Passkey` controller, answering a verified assertion with a bearer token |
| `/passkey/*` routes | `app/api-routes.php` | The seven endpoints, documented in the generated OpenAPI |
| `lib/webauthn.js` | the SPA source tree | The framework's ceremony, with ES exports — **framework-owned, not to be edited** |
| `loginWithPasskey()` and friends | `lib/api.js` | The calls a screen makes |

A sign-in screen needs this much:

```js
import { passkeySupported, loginWithPasskey } from './lib/api.js';

// The button exists only where the browser can honour it: false on plain http://
// (localhost excepted) and in older browsers.
if (passkeySupported()) {
    const user = await loginWithPasskey();   // no argument: the authenticator decides who
}
```

`loginWithPasskey()` stores the issued token exactly as `login()` does, so everything after it is a
normal authenticated call. Passing a username pins the ceremony to one account; passing nothing
makes it usernameless, which is the version worth offering — a sign-in that asks for nothing at all.

Enrolment and management are the same shape: `registerPasskey(label)`, `listPasskeys()`,
`renamePasskey(id, name)`, `revokePasskey(id)`. For the autofill experience described above,
`loginWithPasskeyAutofill()` and `cancelPasskeyAutofill()` are the module's conditional pair.

**The cookie still travels, and it is not a credential.** A ceremony is two calls, and the challenge
issued by the first is held server-side so that only the session that started one can finish it.
That correlation is what the cookie carries; `credentials: 'same-origin'` in the generated client is
why it works. Authentication is still the token — `/passkey/login` seals the request's identity and
establishes no login session.

**One implementation, and the reason it matters.** `lib/webauthn.js` is generated from the same
source the server-rendered login page is served (`PasskeyAsset::spaModule()`), and the only thing
the SPA replaces is the transport: one injected function that carries the API key and the bearer
token instead of posting same-origin. Writing a second WebAuthn client is the obvious alternative
and the wrong one — the ceremony is exact in a way that does not survive being typed twice, and its
failure mode is a flat `authentication_failed` that names no field.

To add the module to a SPA project scaffolded before it existed:

```bash
./<cli> project:resync --debug-panel --all   # writes lib/debug.js and lib/webauthn.js
```

`lib/api.js` is the project's own file and is never overwritten, so its passkey functions are a
copy-in from the current stub.

## What the scaffolded SPA sign-in screen does

Both stacks — Svelte and build-less — ship the same three paths, because a screen that
implements only the first one is a dead end rather than a missing feature:

1. **Password.** `login(username, password)`.
2. **The second factor, when the account has one.** `login()` *throws* `TwoFactorRequired`
   rather than returning — the password was correct and the account needs more — and the
   screen replaces the password form with a code form, posting only the code through
   `loginTwoFactor()`. The pending login is held server-side, so nothing is carried across;
   a wrong code leaves it pending and the person tries again instead of starting over.
3. **A passkey**, where the browser can honour one (`passkeySupported()`).

A screen that does not catch `TwoFactorRequired` shows "Could not sign in" to somebody whose
password was right, with no way forward from that screen at all — which is why
`GeneratedSpaPathsTest` asserts both stacks handle it, rather than leaving it to a reviewer.

The code field carries `autocomplete="one-time-code"`, which is what lets a phone offer the
code from the message it has just received, and `inputmode="numeric"`, which keeps the numeric
keypad without rejecting an alphanumeric backup code.

## What the form says when it fails, and while it works

Two things the scaffolded sign-in screens do that are invisible on the machine of the person who
built them.

**A failed submission is announced.** The error box carries `role="alert"`, and — the part that
actually does the work — the first field of the form points at it:

```php
$errorFieldAttributes = $errorText !== ''
    ? ' aria-invalid="true" aria-describedby="form-error"'
    : '';
```

```html
<div role="alert" id="form-error" class="alert alert-error">Wrong username or password.</div>
…
<input type="text" name="username" id="username" autocomplete="username webauthn"
       aria-invalid="true" aria-describedby="form-error" autofocus>
```

`role="alert"` on its own is not enough here, and this is the easy thing to get wrong while believing
it is done: a live region is announced when it **changes**, and a server-rendered error has been in
the document since before the page existed. It never changed, so most screen readers say nothing. The
description is what works with no JavaScript at all — the message is read out as part of the field the
moment focus lands on it, and focus lands there on load because the first field carries `autofocus`.

The **first** field only. These errors are form-level — «wrong username or password» is about the pair
— and marking four fields invalid to report one failure tells a screen reader four things that are not
true.

The same split applies to every other message box on a scaffolded page: `alert-error` and
`alert-danger` get `role="alert"`, which interrupts; `alert-info`, `alert-success` and `alert-warning`
get `role="status"`, which waits for the next pause. A success message that interrupts whatever was
being read is how a well-meaning accessibility sweep makes a page worse.

**A submitted form says it heard the button.** Mark the form and `pf-auth.js` does the rest:

```html
<form method="POST" action="…/login" data-pf-progress>
```

On submit the submit buttons are disabled, gain a `pf-busy` class, get `…` appended to whatever they
said (or `data-pf-busy-label` if the screen wants real words), and the form gets `aria-busy="true"`.
The pause is real and unavoidable — the human-check proof runs in a worker and the form waits for it —
and a person who cannot tell whether the press registered presses again. A second sign-in attempt is
not free: it is a failed attempt against a lockout counter.

One subtlety worth knowing before you touch it. A submit listener that called `preventDefault()` did
one of two opposite things: **refused** the submit, in which case disabling its button hands somebody
a form that can never be submitted; or **held** it, in which case that hold is exactly when the second
press happens. Nothing in the event distinguishes them, so the indicator skips both — it runs a tick
after the event and returns if the default was prevented — and whoever is holding marks the form
itself:

```js
window.PramnosAuth.markSubmitBusy(form);
```

which is what `pf-humancheck.js` does while it waits for its proof.
