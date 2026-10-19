---
use_cases:
  - Setting up a new OAuth2 / OpenID Connect authorization server from scratch
  - Branding and translating the sign-in, second-factor and consent screens
  - Registering the first client application on a new auth server
  - Deciding which auth screens to change and which to leave to the framework
---

# Setting up an auth server

An authorization server built on this framework is an application with the `auth` and `authserver`
features. You do not copy the sign-in screens into your project or write them yourself. The
framework draws them, and the application only gives them its name, its logo and its language.
This page goes from an empty directory to a server a client can sign people in through.

## 1. Scaffold it

```bash
php vendor/bin/pramnos init --app-name="Example ID" --namespace=ExampleId --features=auth,authserver
```

`init` writes thin controllers that extend the framework's. Each one is a class body with nothing
in it, kept so you can override one action when you need to:
- `Account`, `Login`, `Register`, `Sso`;
- `TwoFactorAuth`, `Passkey`, `Session`;
- `Oauth`, `Discovery`, `Device`, `Gdpr`;
- `Capabilities`, `InternalPermissions`;
- the API controllers.

It also writes the `.well-known` rewrite rules, the `authserver` block of `app/app.php` (grants,
client authentication methods, scopes) and the public `Status` and `Health` controllers.

No auth addon is registered. Signing in, "remember me" (a rotating token per sign-in), the
session and the activity log are all built in.

## 2. Keys and the first administrator

```bash
php bin/pramnos key:generate                       # APP_KEY: encrypts credentials kept in settings
php bin/pramnos oauth:keys --owner=www-data        # the RSA pair tokens and ID tokens are signed with
php bin/pramnos migrate
php bin/pramnos user:create --username=admin --email=admin@example.com --admin
```

`oauth:keys --force` replaces a pair, and every token signed with the old one stops verifying.
Keep the pair out of the web root and out of version control.

## 3. Brand it

Four settings, read by every auth screen through `Pramnos\Auth\Brand`:

| Setting | What it is |
|---|---|
| `auth_brand_name` | the name over the card; `sitename` when unset |
| `auth_brand_logo` | the logo's URL; the name is shown when unset |
| `auth_brand_primary_color` | the buttons' colour (`#2563eb` by default) |
| `auth_brand_footer` | a line under the forms: the operator, a link to the terms |

The brand is drawn by one partial, `partials/auth_brand`, on every screen somebody reaches from a
link, so a person can see whose server it is before typing a password:
- the sign-in and the second factor;
- registration, the forgotten and reset password;
- the consent screen;
- the device code screens;
- the messages.

The public screens before sign-in also carry a link back to the site (`partials/auth_home_link`).

## 4. Its language

The screens' words go through `t()`, and the framework ships their translations in
`resources/language/<code>.php`. Greek is `el`. An application whose language is Greek gets Greek
screens without writing a single string. The catalogue is found by the language's `LangShort`, or
by its name (`greek` → `el`).

To word something differently, put the same English key in your own `app/language/<language>.php`.
The application's catalogue always wins over the framework's:

```php
// app/language/greek.php
$lang['Sign in'] = 'Είσοδος στο Example ID';
```

Flash messages from the controllers go through `t()` as well, for example "Your profile has been
updated." and "That code is not correct.". OAuth error responses (`error`, `error_description`) stay
in English. They are read by programs, and the protocol expects them that way.

## 5. The first client

A client is registered in the administration, under **Applications**. That screen sets:
- its grants and redirect URIs;
- the scopes it may ask for;
- its token lifetimes and how it authenticates.

A client can also register itself through Dynamic Client Registration where that is turned on.

What a client's developer needs to know is in the
[AuthServer Integration Guide](Pramnos_AuthServer_Integration_Guide.md). Discovery is at
`/.well-known/openid-configuration`, and the signing keys at `/.well-known/jwks.json`.

## 6. Status and health

- `/Status` is the public status page: the verdict, and each check's verdict by name. It never
  shows a check's message or details, because those describe the host. Point a footer's "Status"
  link here.
- `/Status/check` and `/Health/check` answer 200 or 503 with the full report, for monitors.
- `/health` is the administrators' dashboard, behind a sign-in, with the details.

## What to change, and what to leave

**Leave the screens to the framework.** An application's own view with the same name, under its
views directory (for example `src/Views/login/login.html.php`), is used instead of the
framework's. That works, but every later fix to the framework's screen then has to be merged into
your copy by hand.

The three things that made copies necessary are each handled once instead:
- **Words** go in your language file.
- **Branding** goes in the four settings.
- **One screen's behaviour** goes in an override of that one action in its thin controller.

Copy a view only when its layout itself has to differ.

**Sign-in rules** are configuration and hooks, not forks:
- `second_factor_everywhere` asks for the second factor on every session.
- The registration policy decides who may sign up, and invitations who may join.
- The scopes a role may grant limit the consent screen.

These are covered in the [Authentication Guide](Pramnos_Authentication_Guide.md).

## Related

- [Authentication Guide](Pramnos_Authentication_Guide.md): sign-in, second factors, passkeys,
  sessions, remember-me
- [AuthServer Integration Guide](Pramnos_AuthServer_Integration_Guide.md): for the developers of
  client applications
- [OAuth Client Guide](Pramnos_OAuth_Client_Guide.md): this framework as a client of another server
