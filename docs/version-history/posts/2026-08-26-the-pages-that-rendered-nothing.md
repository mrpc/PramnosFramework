---
title: The pages that rendered nothing
date: 2026-08-26
categories:
  - Auth
  - Bugfix
---

# The pages that rendered nothing

The OAuth2 consent screen did not exist. Neither did any page of the
device-authorization flow. Six screens, all answering 200 with the right title and
an empty body.

<!-- more -->

## What was happening

`View::display()` **returns** the rendered markup. It does not echo it:

```php
public function display($tpl = '', $render = false)
{
    $this->getTpl($tpl, '', $render);
    return $this->output;
}
```

A controller action that returns is fine — `Application::exec()` adds the returned
value to the document. These six did not return; they called `display()` as a
statement and threw the string away:

```php
$view->display('authorize');     // Oauth::showConsentForm()
$view->display();                // Device — code entry
$view->display('confirmation');  // Device — approve
$view->display('success');       // Device — approved
$view->display('deny');          // Device — denied
$view->display('errormessage');  // Device — error
```

Every one of them is called from an action declared `: void`, so nothing
downstream picked the markup up either. The response was the theme, the title, and
nothing in between.

## Why it lasted

Because every signal said the page had worked. Status 200. Correct `<title>`.
Layout, navigation, footer all rendered. Nothing in any log. The only thing missing
was the part the visitor had come for.

And these are the six screens least likely to be opened by whoever is working on
the server. The consent form needs an untrusted client mid-authorization — a
trusted one skips it entirely, and a developer's own client is trusted. The device
pages have no link pointing at them from anywhere in the site: you reach them by
typing a code off a television.

For the person affected it does not read as an authentication-server bug at all. A
relying party's users see a blank page after clicking "sign in with…", and report
it to the relying party.

## The fix

The markup goes into the document:

```php
\Pramnos\Framework\Factory::getDocument()->addContent(
    (string) $view->display('authorize')
);
```

`Oauth::showErrorPage()` had the mirror-image problem — it `echo`ed, so its message
went out *before* the page the framework then rendered, producing a fragment
followed by a complete HTML document. Also now added to the document.

## And what was behind one of them

With the device success page finally rendering, it fataled:

```php
$view->deviceAuth = (object) $deviceAuth;
```

All three bundled views index that value — `$this->deviceAuth['user_code']` — and
say `array` in their docblocks. The cast made rendering the page a `TypeError`.
Which had never happened, because the render was thrown away: the bug was
protected by the bug on top of it.

## Notes

- Nothing to change in a project. If you had worked around the blank consent screen
  by marking every client trusted, you can stop.
- A project with its own copies of these views needs no change; they were always
  correct.

## Documentation

- `Pramnos_Account_Guide.md` — a note under the consent screen on where its markup
  goes, and why an action that renders must return or add.
