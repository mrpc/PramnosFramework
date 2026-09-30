---
use_cases:
  - Turning on the EU cookie banner in a new or existing application
  - Loading Google Analytics, Tag Manager or a Meta Pixel only after the visitor agrees
  - Wiring Google Consent Mode v2 into a Tag Manager container
  - Checking from PHP whether a visitor allowed analytics or marketing cookies
  - Translating or restyling the cookie banner
---

# Cookie Consent Guide

The framework ships an EU cookie banner that works the same way in every scaffold theme
(Bootstrap, Tailwind, plain CSS) and every app style (MVC, SPA, hybrid). It covers:

- a banner on the first visit, where **Accept all** and **Reject all** are equally prominent,
  plus a **Customise** dialog with a switch per category;
- the choice, kept in the browser for 180 days and asked for again whenever the policy
  version changes;
- third-party scripts, which stay inert until their category is allowed;
- **Google Consent Mode v2** signals on `window.dataLayer`, so Tag Manager and gtag.js start
  denied and follow the visitor's choice;
- a "Cookie settings" link in every footer, so a visitor can withdraw as easily as they agreed;
- a server-side record in `authserver.user_consents` for signed-in visitors.

The categories are **strictly necessary** (always on and never asked about),
**preferences**, **analytics** and **marketing**.

## How it fits together

| Piece | What it does |
|---|---|
| `www/assets/js/pf-consent.js` | Draws the banner and the dialog, writes the `pf_consent` cookie, releases gated scripts and pushes Consent Mode signals. It is one file shared by all themes and app styles. |
| `\Pramnos\Security\CookieConsent::tag(sURL)` | Emits the `<script>` element, with the site's settings and translated texts in `data-config`. Each theme footer calls it. |
| `\Pramnos\Security\CookieConsent::allows('analytics')` | Answers from PHP whether this visitor agreed to a category. |
| `/cookieconsent` | `GET` returns the configuration as JSON for the SPA shell. `POST /cookieconsent/record` writes a signed-in visitor's choice to the consent trail. |

**The banner is decided in the browser, never on the server.** The page cache keys on the
URL and ignores cookies, so if markup differed per visitor, one visitor's page would be served
to another. Every visitor receives the same tag and the script reads the cookie. For the same
reason the server never sets the cookie: `PageCache` refuses to store a response that carries
`Set-Cookie`. The tag is an external file with no inline script, so it adds no CSP nonce to the
page and does not stop the page being cached.

## Turning it on

### A new project

`init` asks **"Show an EU cookie consent banner? [Y/n]"**. Pass `--cookie-consent=y|n` to
answer it without the prompt.

- **Yes (the default)** wires everything: the script under `www/assets/js/`, the tag and the
  "Cookie settings" link in the theme footer and the login layout, the tag in the SPA shell,
  and the `/cookieconsent` route. Configure it under
  **System Settings → Security → Cookie consent**.
- **No** is for a site that sets only strictly necessary cookies. It writes
  `'cookie_consent' => false` into `app/app.php`, and gives the SPA shell the script configured
  `{"enabled":false}` inline, so it fetches nothing.

### Switching it off, or back on, in `app/app.php`

```php
'cookie_consent' => false,
```

With this key, the feature is off whatever the settings say. The footer link is hidden,
`allows()` answers `true`, and the settings screen shows a note instead of the fields.

**Off means everything is allowed, in the browser too.** `tag()` still emits the script,
configured `{"enabled":false}`, and `/cookieconsent` answers the same to a SPA shell. The
script then draws nothing and treats every optional category as granted: `has()` answers
`true`, `type="text/plain"` scripts are released, `onGrant()` callbacks run, and Consent Mode
gets an all-granted `update` after its default. No cookie is written, so turning the banner
on later still asks every visitor. The key belongs to the deployment and is versioned with the code, so a switch on
a live server cannot add a banner to a site whose theme was never checked for one.

To turn it on, delete the line. An MVC project then works immediately, because its footer
already calls `tag()`. In a SPA or hybrid project, also replace the shell's
`data-config="{&quot;enabled&quot;:false}"` with the `data-config-url` of step 3 below.

### An existing project

1. Copy the script into the web root:

    ```bash
    php <cli> project:resync --js --all
    ```

    `--all` is required. Without it, resync only refreshes files the project already has,
    and it reports `pf-consent.js` as `skip … (not present; use --all to add)`.

2. In your theme's `footer.php`, add the tag **before** your own scripts and
   `renderJs()`:

    ```php
    <?php echo \Pramnos\Security\CookieConsent::tag(sURL); ?>
    ```

    Then add a link that reopens the dialog wherever your footer text is:

    ```php
    <?php if (\Pramnos\Security\CookieConsent::enabled()): ?>
        <a href="#" data-consent-open>
            <?php echo htmlspecialchars(\Pramnos\Framework\Factory::getLanguage()->_('Cookie settings')); ?>
        </a>
    <?php endif; ?>
    ```

    Any element carrying `data-consent-open` opens the dialog. If a page has no such element,
    the script adds a small fixed "Cookie settings" button so that withdrawing consent is
    always possible.

3. **SPA and hybrid projects.** In the shell (`www/spa.php` or `www/app.php`), add this line
   before `</body>`:

    ```php
    <script src="<?php echo htmlspecialchars($siteUrl . 'assets/js/pf-consent.js', ENT_QUOTES); ?>"
            data-config-url="<?php echo htmlspecialchars($siteUrl . 'cookieconsent', ENT_QUOTES); ?>" defer></script>
    ```

    The shell does not boot the application, so it cannot read settings itself. The script
    fetches them from `/cookieconsent` instead. In a pure SPA, add `cookieconsent` to the list of
    paths that `www/.htaccess` sends to `index.php` (the `RewriteRule ^(api|health|…)` line).
    Otherwise the shell answers that path and the banner receives HTML where it expects JSON.

## Settings

| Setting | Screen label | Default | Meaning |
|---|---|---|---|
| `cookie_consent_enabled` | Show the cookie banner | `1` | Set it to `0` only if the site sets nothing beyond strictly necessary cookies. When it is off, nothing is drawn and every category counts as granted — `allows()` in PHP, `has()` and gated scripts in the browser. |
| `cookie_consent_categories` | Categories to ask about | `preferences,analytics,marketing` | Any subset of the three. Unknown names are dropped, so a typo cannot become a switch that controls nothing. |
| `cookie_consent_policy_url` | Cookie policy page | *(empty)* | The page the banner links to. When empty, there is no link. |
| `cookie_consent_version` | Policy version | `1` | Change it whenever the policy changes. A choice made under an older version no longer counts, so everybody is asked again. |

The constants are on `\Pramnos\Security\CookieConsent` (`ENABLED_SETTING` and the others).

## Loading third-party scripts only after consent

To gate a script, write it with `type="text/plain"` and name its category in `data-consent`:

```html
<script type="text/plain" data-consent="analytics" src="<?php echo sURL; ?>assets/js/analytics.js"></script>
```

It stays inert until the visitor allows that category. The script then replaces it with a real
`<script>` that keeps every other attribute (`src`, `async`, `id`, `data-*`). This happens on
the same page right after the click, and on every later page load.

**A gated script must be a file.** A `text/plain` script carries no CSP nonce, so a released
inline script is refused by the framework's policy. Move a vendor's inline snippet into a file
under `www/assets/js/` and gate that file. This also keeps the page cacheable.

From JavaScript you already load, register a callback instead:

```js
PramnosConsent.onGrant('marketing', function () {
    // runs now if marketing is already allowed, otherwise once it is
});

document.addEventListener('pramnos:consent', function (event) {
    console.log(event.detail.granted);   // e.g. ['analytics']
});

PramnosConsent.has('analytics');   // true / false
PramnosConsent.open();             // the preferences dialog
```

**When a visitor withdraws a category they had allowed, the page reloads.** A script that has
already run cannot be unloaded, and a reload is the only way it stops running. Cookies that a
third party has already set stay until they expire. If your policy promises to delete them,
remove them by name in a `pramnos:consent` listener.

## Google Analytics 4 (gtag.js)

This is the simplest compliant setup: nothing from Google loads until the visitor allows
analytics.

`www/assets/js/analytics.js`:

```js
window.dataLayer = window.dataLayer || [];
function gtag() { window.dataLayer.push(arguments); }
gtag('js', new Date());
gtag('config', 'G-XXXXXXXXXX');
```

In the theme footer, **after** the consent tag:

```html
<script type="text/plain" data-consent="analytics" src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX" async></script>
<script type="text/plain" data-consent="analytics" src="<?php echo sURL; ?>assets/js/analytics.js"></script>
```

Load order between the two does not matter, because `gtag()` only queues onto `dataLayer`.

**Consent Mode, advanced setup.** If you want Google's cookieless pings and modelling for
visitors who refuse, load gtag.js *without* `type="text/plain"`. Place it after the consent
tag, with `defer` rather than `async`, so the script's all-denied `consent default` is on the
data layer before gtag.js reads it. The script then sends `consent update` with the visitor's
choice. Whether cookieless pings are acceptable under your reading of ePrivacy is a legal
question, not a technical one. The gated setup above avoids the question.

## Google Tag Manager

pf-consent.js pushes three things to `window.dataLayer`:

1. `gtag('consent', 'default', {…})` on every page load. Everything is `denied` except
   `security_storage`.
2. `gtag('consent', 'update', {…})` with the visitor's choice. It is pushed straight away for a
   returning visitor, and after the click for a new one.
3. `{event: 'pramnos_consent', pramnosConsent: ['analytics', …]}` alongside every update.

The categories map to Consent Mode signals as follows:

| Category | Consent Mode signals |
|---|---|
| preferences | `functionality_storage`, `personalization_storage` |
| analytics | `analytics_storage` |
| marketing | `ad_storage`, `ad_user_data`, `ad_personalization` |

Load the container **after** the consent tag, so that the default is set first. Put the
container snippet in `www/assets/js/gtm.js`:

```js
(function (w, d, s, l, i) {
    w[l] = w[l] || [];
    w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
    var f = d.getElementsByTagName(s)[0], j = d.createElement(s);
    j.async = true;
    j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i;
    f.parentNode.insertBefore(j, f);
})(window, document, 'script', 'dataLayer', 'GTM-XXXXXXX');
```

Then load it in the footer below the consent tag:

```html
<?php echo \Pramnos\Security\CookieConsent::tag(sURL); ?>
<script src="<?php echo assetUrl('assets/js/gtm.js'); ?>" defer></script>
```

Both are `defer`, so they run in document order. The consent script pushes the all-denied
default the moment it runs, before it has any configuration, so the order holds in a **SPA
shell** too. There the script fetches its settings, but the default does not wait for the
fetch. Put `gtm.js` after the consent line in the shell, also with `defer`.

In the container:

- Google tags (GA4, Google Ads, Floodlight) read Consent Mode by themselves. Set the
  container's consent overview so they require their built-in consent.
- For any other tag, such as a Meta Pixel template, LinkedIn or Hotjar, set **Additional
  consent checks** (for example `ad_storage` for marketing tags). Fire the tag on a **Custom
  Event** trigger named `pramnos_consent`, so that it runs on the click itself and not only on
  the next page view.
- **Custom HTML tags inject inline scripts, and the framework's CSP refuses them.** Use
  template tags from the Community Template Gallery instead. The alternative is loosening
  `script-src`, which weakens the whole site's policy.

If you prefer that nothing from Google loads before consent, gate `gtm.js` itself with
`type="text/plain" data-consent="analytics"`. The consent commands are already queued on the
data layer when it loads.

## Meta (Facebook) Pixel

`www/assets/js/meta-pixel.js`, which is Meta's snippet moved into a file:

```js
!function (f, b, e, v, n, t, s) {
    if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
    if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
    t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
}(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', 'YOUR_PIXEL_ID');
fbq('track', 'PageView');
```

Load it gated on marketing:

```html
<script type="text/plain" data-consent="marketing" src="<?php echo sURL; ?>assets/js/meta-pixel.js"></script>
```

Leave out the `<noscript><img …></noscript>` fallback that Meta's snippet includes. It fires
without JavaScript, which means it fires without consent.

## Other tools

Every tool follows the same pattern: move its snippet into a file, gate the file on the right
category, and allow its hosts in the CSP. Examples are Hotjar or Microsoft Clarity
(analytics), LinkedIn Insight or TikTok Pixel (marketing), and a YouTube or Maps embed
(marketing or preferences, depending on the provider). An embed that is an `<iframe>` rather
than a script can be created from an `onGrant()` callback.

## Allowing the hosts in the CSP

The framework's policy allows only `'self'` for scripts, images and connections. Add each
provider under `csp` in `app/app.php`:

```php
'csp' => [
    'script-src'  => [
        'https://www.googletagmanager.com',   // gtag.js, GTM
        'https://connect.facebook.net',       // Meta Pixel
    ],
    'img-src'     => [
        'https://www.googletagmanager.com',
        'https://*.google-analytics.com',
        'https://www.facebook.com',
    ],
    'connect-src' => [
        'https://*.google-analytics.com',
        'https://*.analytics.google.com',
        'https://www.googletagmanager.com',
        'https://www.facebook.com',
    ],
],
```

A request refused by the CSP shows up in the browser console and nowhere else. When a tag
"does nothing", check the console first.

## From PHP

```php
use Pramnos\Security\CookieConsent;

if (CookieConsent::allows('preferences')) {
    setcookie('layout', 'compact', ['expires' => time() + 86400 * 180, 'path' => '/']);
}

CookieConsent::granted();   // ['necessary', 'analytics'] — always includes necessary
```

Use this for behaviour and for cookies that PHP sets, and only on responses that are not
cached (a POST, or a signed-in page). **Never use it to vary the markup of a page that the page
cache stores.** The cache ignores cookies, so the first visitor's choice would be served to
everybody. For markup, gate in the browser with `data-consent`.

## The consent trail

When a signed-in visitor chooses, the script posts the choice to `/cookieconsent/record`. It
authenticates the request with the page's `<meta name="csrf">`. The endpoint appends one row
per category the site asks about to `authserver.user_consents`, refusals included:

| Column | Value |
|---|---|
| `consent_type` | `cookie:<category>@v<policy version>`, for example `cookie:analytics@v3` |
| `granted` | `1` or `0` |
| `revoked_at` | set on a refusal |
| `legal_basis` | `consent` |
| `ip_address` | the visitor's address |

An anonymous visitor has no row, because the table is keyed by user. Their record is the
cookie, which carries the policy version and the time of the choice. A SPA page has no CSRF
meta, so its choices are kept in the cookie only. The row is written after the cookie, so a
failure to write it is logged and never makes the banner ask again.

## Translating and styling

The banner's texts come from `CookieConsent::config()` and go through the language catalogue,
keyed by their English wording. Add them to `app/language/el.php` (or any other language):

```php
'We use cookies' => 'Χρησιμοποιούμε cookies',
'Accept all'     => 'Αποδοχή όλων',
'Reject all'     => 'Απόρριψη όλων',
'Customise'      => 'Προσαρμογή',
'Save choices'   => 'Αποθήκευση επιλογών',
'Cookie settings'=> 'Ρυθμίσεις cookies',
'Cookie policy'  => 'Πολιτική cookies',
// …and the category labels and descriptions, which you can find in CookieConsent::config()
```

The banner brings its own styles as a constructed stylesheet. A constructed stylesheet is not
an inline `<style>` element, so the CSP does not block it. It follows `prefers-color-scheme`.
Constructed stylesheets come after the page's own stylesheets in the cascade, so to recolour
the banner, override its custom properties with a slightly more specific selector in your theme
CSS:

```css
html .pf-consent {
    --pf-c-accent: #7c3aed;
    --pf-c-bg: #ffffff;
    --pf-c-fg: #111827;
}
```

The available properties are `--pf-c-bg`, `--pf-c-fg`, `--pf-c-muted`, `--pf-c-border`,
`--pf-c-accent` and `--pf-c-accent-fg`. The element classes are `.pf-consent-banner`,
`.pf-consent-dialog`, `.pf-consent-row` and `.pf-consent-reopen`.

## Troubleshooting

| Symptom | Cause |
|---|---|
| No banner at all | Check, in order: the setting is `0`, or `app.php` says `'cookie_consent' => false`; the footer does not call `CookieConsent::tag()`; `assets/js/pf-consent.js` is missing (run `project:resync --js --all`); in a SPA, `/cookieconsent` is answered by the shell (see the `.htaccess` step above). |
| The banner comes back on every page | The cookie is not being stored. Check that the site is not on a different host or path than the one the banner loaded on. A `Secure` cookie on plain `http` is dropped, and the script adds `Secure` only on https. |
| A gated script never runs | Its category is not among the ones the site asks about, it is inline instead of a file, or the CSP refuses its host. |
| GTM tags fire before consent | The container loads before the consent tag. Move it below the tag, with `defer`. |
