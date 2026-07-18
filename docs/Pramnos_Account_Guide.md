# Account & Security — End-User Guide

## Overview

Every application scaffolded on Pramnos Framework ships a complete, self-service
**account area** — no code required beyond branding. This guide is written for
the **end user** of such an application: it explains what each account page does
and how to use it.

The account area lives under a single base path (shown here as `/account` — your
application may brand it differently) and groups everything a signed-in user
needs to manage their identity, security and privacy:

| Area | Pages |
|------|-------|
| **Profile** | View / edit profile, dashboard |
| **Security** | Change password, Two-Factor Authentication, Passkeys, Active Sessions |
| **Privacy** | Privacy settings, Export my data, Delete account |

All pages share one navigation sidebar and breadcrumb, so the layout is the same
whichever theme (plain-CSS, Bootstrap, Tailwind) the application uses.

---

## Signing in

The login screen accepts your username or email plus your password. Depending on
how the application is configured you may also see:

- **"Sign in with a passkey"** — passwordless login using a device passkey (see
  [Passkeys](#passkeys) below).
- A **two-factor prompt** after your password, if you have 2FA enabled.

If you enter the wrong password several times, the account is temporarily locked
(brute-force protection). Each failed attempt — and any lockout it triggers — is
recorded in your [activity log](#recent-account-activity).

### Staying signed in

Ticking **"Remember me"** keeps you signed in on that device. You can review and
end remembered sessions from [Active Sessions](#active-sessions).

---

## Changing your password

**Security → Change Password.**

Enter your current password, then your new one twice. The new password must:

- be at least **8 characters**,
- contain at least **one digit**, and
- contain at least **one special character**.

The form checks these rules as you type. On success your password is updated and
a `password_changed` entry is added to your activity log.

---

## Two-Factor Authentication (2FA)

**Security → Two-Factor Authentication.**

Two-factor authentication adds a one-time code (TOTP) on top of your password.

1. **Set up:** the page shows a QR code — scan it with an authenticator app
   (Google Authenticator, Authy, 1Password, …) or enter the secret manually.
2. **Confirm:** type the 6-digit code the app shows to verify the pairing.
3. **Backup codes:** you are given one-time backup codes — store them somewhere
   safe. Each can be used once if you lose access to your authenticator.

Once enabled, you will be asked for a code (or a backup code) after your password
on every sign-in. You can **regenerate backup codes** or **disable 2FA** from the
same page (disabling requires your password).

---

## Passkeys

**Security → Passkeys.**

A passkey is a phishing-resistant credential stored on your device (Face ID /
Touch ID / Windows Hello / a hardware security key). It can be used **instead of
your password** or as a **second factor**.

- **Add a passkey:** click *Add passkey*, give it a name (e.g. "MacBook"), and
  follow your browser/device prompt.
- **Rename / remove:** manage each passkey from the list; removing one takes
  effect immediately.
- **Sign in:** on the login screen choose *Sign in with a passkey*.

Only passkey **metadata** (its name, when it was added, last used) is ever stored
server-side — never anything that could be used to impersonate you.

---

## Active Sessions

**Security → Active Sessions.**

This lists the devices/browsers currently signed in to your account, with the
approximate location (IP), browser and last-seen time. If you see a session you
do not recognise, click **Sign out** next to it — that device is signed out on
its next request. Your current session is always kept.

---

## Recent Account Activity

The Security page shows your **recent account activity** — sign-ins, sign-outs,
failed sign-ins, password changes, 2FA/passkey changes, application
authorizations, privacy changes and data-export requests — each with a timestamp
and the IP/browser it came from. Use it to spot anything you did not do.

---

## Authorized Applications

**Security → Authorized Applications.**

If you have granted third-party applications access to your account (via
"Sign in with …"), they are listed here. **Revoke** removes an application's
access and invalidates its tokens immediately.

---

## Privacy settings

**Privacy.**

Toggle privacy preferences such as usage-analytics sharing and marketing emails.
Changes are saved immediately and recorded in your activity log.

---

## Export my data (GDPR)

**Privacy → Export my data.**

You can download a complete copy of the personal data the application holds about
you. The confirmation page lists exactly which sections the export contains
(profile, authorized applications, OAuth consents, passkeys, two-factor status,
active sessions, tokens, token activity, account details, privacy settings and
activity log — plus any application-specific data).

Confirm to download a single JSON file. For your safety the export **never
contains secrets** — no passwords, token values, 2FA secrets/backup codes,
passkey keys, or raw request bodies.

---

## Delete my account (GDPR)

**Privacy → Delete account.**

This permanently erases your personal data. To prevent accidents you must:

1. re-enter your **password**, and
2. type **`DELETE`** in the confirmation field.

Deletion removes your data across all account tables. This cannot be undone —
export your data first if you want a copy.

---

## Related guides

- [Authentication & User Management](Pramnos_Authentication_Guide.md) — how the
  login/2FA/passkey stack works under the hood.
- [Security Hardening](Pramnos_Security_Guide.md) — CSRF, session cookies,
  escaping.
- [Third-Party Integration Guide](Pramnos_AuthServer_Integration_Guide.md) — for
  developers connecting an external application.
