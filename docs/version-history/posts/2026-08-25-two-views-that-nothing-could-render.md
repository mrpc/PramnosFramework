---
date: 2026-08-25
categories: [Changelog]
---

# Two views that nothing could render

Every bundled theme has shipped `register/register.html.php` and `sso/sso.html.php`.
Neither had a controller. The registration form posted to `Home/register`, a route the
scaffold does not create, and the discovery document advertised `registration_endpoint`
as `/register` — a 404 with a promise attached.

<!-- more -->

## Added

- **`Account::register()`** and the `/register` route, scaffolded by `init` with the
  `auth` feature. **Closed until `auth_allow_registration` is switched on**: a scaffolded
  application must not gain a public sign-up page by being upgraded, and most applications
  on this framework create their accounts by some other route entirely. With it off the
  page renders and says so, rather than 404-ing a page the views link to.

  The guard order is the security story, and it is deliberate: the registration switch is
  read before the request body, so a crafted POST to a closed server cannot write a row;
  CSRF is checked before validation, so a form without a token is not even an
  account-existence oracle; and every field is validated before any query, so the endpoint
  is not a way to make the database work for free.

  The password rules are the ones `resetpassword` already enforced — eight characters, a
  digit, a symbol — because there is one policy and it lives in one method. The forms were
  advertising `minlength="6"`, which is a form accepting what the server rejects, then
  sending somebody back to the page that had told them they were fine.

  `registrationIsOpen()`, `validateRegistration()`, `usernameExists()` and `createUser()`
  are all seams, so an invite code or a domain allow-list is one overridden method rather
  than a reimplemented flow.

  On enumeration, stated plainly rather than papered over: "that username is taken"
  confirms an account exists, and a form that has to let somebody pick another name has to
  say why. It reveals that. The mitigations are leaving registration off when you do not
  need it and keeping the login lockout, since the value of an enumerated username is what
  happens next. The email case is worded so that it does not add a second confirmation.

- **`Account::sso()`** and the `/sso` route — the page that answers "does this server
  already know me, and what have I authorized?". Public, because for a signed-out visitor
  that negative answer is the useful half.

## Fixed

- **Eighteen bundled views linked to routes the scaffold does not create.** `Home/login`,
  `Home/register` and a bare `logout` were all dead; the real routes are `/login`,
  `/register` and `/login/logout`. A dead link in a scaffold is worse than a missing
  feature, because it looks like a feature until somebody clicks it. There is now a test
  that walks every view in every theme looking for exactly these three.

- **The SSO page rendered every application without its link.** The view was documented as
  receiving `website_url`; `getAuthorizedApplications()` never selected it. It does now.

## Documentation

- [Authentication](../../Pramnos_Authentication_Guide.md) gains "Self-service
  registration" — the switch, the enforcement order, the enumeration trade-off, and how to
  gate sign-up on an invite code instead — and "The single sign-on status page".
- [Account & Security](../../Pramnos_Account_Guide.md) gains "Creating an account", for the
  person reading it rather than the person wiring it.
