---
use_cases:
  - Writing help pages for the administration screens of an application
  - Letting an administrator ask the analyst about the record on the page they are looking at
  - Telling the side panel which record an admin screen shows
  - Adding the Help screen and the side panel to an existing project
  - Finding a help page from a question in Greek or English
  - Turning help pages on or off for an installation
  - Giving a site's visitors help pages of their own, apart from the administration's
  - Showing help pages in a SPA or a mobile application
  - Hiding help pages or parts of them from customers whose plan does not include a feature
  - Letting the application's own assistant answer from the site's help pages
  - Making the site's help button a small icon, or replacing it with the application's own
  - Putting separate Ask and Help buttons in a theme's header, each opening the panel on its tab
  - Styling the side panel from the theme's stylesheet, to match daisyUI or Bootstrap
  - Announcing a change to the administrators in a What's new window they close once
---

# Administration help and the side panel

Every administration page has an **Ask** button in its header. It opens a panel beside the page
with two things:

- **Ask**: the analyst, about the page you are on. On `/admin/Users/edit/5`, "when did this user
  last sign in?" means user 5.
- **Help**: that page's help, written as Markdown.

Without the analyst — the `llm` feature off, no provider key set yet, or an administrator below
its floor — the button is **Help** and the panel holds the help alone. A page with no help and no analyst has no button.

The help pages are also a screen of their own, `/admin/Help`, with a search. The analyst reads
them too, through its `help` tool, so "how do I refund an order?" is answered from your pages.

## The `help` feature

Help pages are a feature, listed in `app/app.php` like any other:

```php
'features' => ['auth', 'queue', 'help'],
```

With it, the panel has its Help tab, `/admin/Help` answers, the menu has *Help*, and the analyst has
the `help` tool and reads a page's help as background. Without it, none of these: the panel is Ask
alone, and `/admin/Help` is not found. A new project made with `init` has it on; `init
--features=…` lists it like the rest. Ask in the panel is the `llm` feature's, independently.

## Help pages

A help page is a Markdown file. Its name is its path without `.md`:

```
app/help/users.md            the Users screen — every action of it
app/help/users/edit.md       only its edit form
app/help/devices/massiveupdate/3.md  that action for one option of its address: /Devices/massiveupdate/3
app/help/billing/refunds.md  a page of its own, reached from the list or a link
app/help/images/refund.png   a picture a page shows
```

**A screen's page is named after the screen**: the controller's class name, lowercase, without
`Controller`. `Admin\Controllers\Users` is `users`, and its `edit` action is `users/edit`, falling
back to `users`. The panel finds it on its own.

**A page per option of the address.** Where one action does a different thing for each option —
`/Devices/massiveupdate/3` changes the area of many devices, `/Devices/massiveupdate/1` their
owner — `devices/massiveupdate/3.md` is that one's page, found before `devices/massiveupdate.md`,
which stays the overview for an option without a page of its own and for the address with none.
Give such a page a `title:`, so the Help index lists it by what it does, not by a number. In code,
`Help::forScreen($screen, $action, $option)`; the help API takes `&option=` beside `screen` and
`action`.

**The application's pages come first, whichever of the two it wrote.** An application that wrote
`app/help/users.md` gets it on the edit form too: the framework's own `users/edit.md` is used only
when the application has neither `users/edit.md` nor `users.md`.

The framework ships a page for each of its own screens in `resources/help/`. A page of the same
name in `app/help/` replaces it. `'help' => ['paths' => [...]]` in `app/app.php` searches other
directories instead of `app/help`; the framework's are searched last.

**Leaving the framework's pages out.** An application that documents its own screens may not
want the framework's pages at all. `/Help` lists and searches every page it has, so the pages
for screens the application does not use (queue, tokens, roles, the assistant) read as
features its operators have. `'help' => ['framework' => false]` leaves `resources/help/` out:
only the application's directories are searched, and every page in them is the application's.
Left unset, the framework's pages are included.

```php
// app/app.php
'help' => ['framework' => false],
```

### Front matter

Optional, for what the file cannot say by itself:

```markdown
---
title: Refunds
section: Billing
questions:
  - How do I refund an order?
  - Πώς επιστρέφω χρήματα σε έναν πελάτη;
updated: 2026-10-01
---
# Refunds

A refund starts from the order's page. See [orders](help:orders).

![The refund button](images/refund.png)
```

- `title` — the first `#` heading when not given, the page's name when there is none.
- `section` — the heading the page is listed under on `/admin/Help`. The framework's pages are
  under *Administration*.
- `questions` — what a reader would ask. A search matches them, and the title, three times as
  strongly as the text.
- `updated` — shown under the page. A changelog post may say `date` instead.
- `whatsnew` — lines that announce the page in [What's new](#whats-new), to every administrator
  until they close it.
- `order` — a number that sorts the page within its section, before the title. Pages without one
  come after those with one.
- `screens` — `[editorial, draft, sources]`: the screens this page is the help of, beside the
  one it is named after. A screen's page is the one named `screen/action`, then the one named
  `screen`, then the first by name that lists the screen. A dedicated page therefore overrides an
  area's without anyone editing the area's list.

A list can be written on one line, `[a, b]`, or as `- a` lines.

### Links and pictures

- `[orders](help:orders)` links to another page by name, so the files are not tied to a host.
- `![alt](images/x.png)` shows a picture beside the page, from its own directory. It is served
  by `/admin/Help/image` to somebody signed in, only from inside a help directory, and only as a
  picture (`png`, `jpg`, `gif`, `webp`, `svg`).
- `https://…` opens in a new tab; a path from the site's root (`/admin/Orders`) opens in the same
  one.

The renderer is `Chat\Markdown`: headings, lists, tables, emphasis, code, links and pictures. It
escapes everything first, and has no HTML and no nested lists. A page's headings are the levels it
writes — `#` is `<h1>`, the page's title, `##` a section — because `Help::html()` passes
`'headingShift' => 0`. A model's reply, drawn under its conversation's own title, keeps the default
of two levels lower.

### What's new

A page with `whatsnew:` in its front matter is **announced**. Its lines are what a window over
the administration says, until each person closes it. Usually the page is a changelog post. Most
posts are not worth interrupting anybody for, and the one that is says so:

```markdown
---
title: Invoices in two currencies
section: Changelog
date: 2026-10-19
whatsnew:
  - An invoice can now be issued in dollars as well as euros.
  - Reports show both, converted at the day's rate.
---
```

- **The window opens by itself, without a script,** while something announced is unseen. It
  shows the newest three in full, each with *More →* to its page, and counts the rest, with a
  link to the help. Only the announcements scroll; the title and *Close* stay in view.
- **Close and More** are a POST with the session's token to `/admin/Help/whatsnew`. Everything
  announced is then seen by that person, and the browser goes back to the page, a path of this
  site only, or on to the post.
- **Seen is per person,** in their user settings (`whatsnew.seen`). Somebody who has never had it
  starts with everything dated before today seen. A new administrator is not shown the history,
  but whoever first opens the administration on the day of an announcement still sees it.
- **The header's button** shows while something was announced in the last fourteen days, and
  opens the same window with those. Closing it there is only a click.
- **For** the people of the administration: the help on, and a usertype at the area's floor or
  above.

A theme written by `init` has both hooks. In a theme of your own:

```php
<?php echo \Pramnos\Assist\WhatsNew::button('<li>', '</li>'); ?>   <!-- header, nothing when there is nothing -->
<?php echo \Pramnos\Assist\WhatsNew::window(); ?>                  <!-- footer -->
```

Each theme's `components.css` styles `pf-whatsnew`. The settings, with their defaults:

```php
'help' => ['whatsnew' => ['shown' => 3, 'recent_days' => 14, 'min_usertype' => 90]],  // min_usertype: the area's floor unless set
```

`WhatsNew::announced()`, `unseen($user)`, `recent()` and `dismiss($user)` are there for a
screen of your own.

### Search

Words are compared folded (case, accents and the final sigma) and by their first five letters,
so `λογαριασμού` finds `λογαριασμός` and `email` finds `Email`. A word of four letters also meets
a longer one it begins, so `goal` finds `goals`; one of three letters matches only exactly.

Words of two letters are ignored, and so are common words of three or more: `how`, `what`, `the`
and the like, `πώς`, `και`, `για`, `τον`, `του` and the like. They are in every question and tell
pages apart no better than nothing.

A page is scored as follows:

- Its title and `questions:` count three times what its text does, each word up to three times.
- Each word of the question is weighed by how rare it is across the pages. A word on every page
  says little, so a long page cannot win by repeating the question's common words.
- A question that is one of a page's `questions:` puts that page first.
- A question whose every word appears in one of them puts the page nearly first.

So listing the questions readers actually ask is the most effective thing a page can do to be
found.

```php
use Pramnos\Assist\Help;

Help::search('πώς αλλάζω το email', 5);   // pages, best first, each with its score
Help::page('users/edit');                  // one page: title, section, questions, body
Help::forScreen('users', 'edit');          // the page the panel shows there
```

## What the panel knows about the page

Each request's first controller is recorded as the page: its screen, its action, the address and
the document's title. A question asked from the panel carries them. The analyst is told what
"this" and "here" mean, and gets the page's help as background.

A screen whose actions take a record's id in the address says what that record is, in one
property:

```php
class Posts extends \Pramnos\Application\Controller
{
    protected string $assistSubject = 'post';   // /admin/Posts/edit/12 is post 12
}
```

An action that knows better names the record itself, with a label the analyst can quote:

```php
\Pramnos\Assist\Page::about('post', $post->id, $post->title);
```

The framework's screens declare theirs: user, organization, email, queue task, OAuth
application, role, token, mass message, message template, group, push notification, log file,
service, permission and token action.

The analyst reads the record with its tools where one can — `user` for a person, the
application's `admin_tools` for its own records — and says so when none can. What comes back
from the browser is checked by `Page::fromInput()`: known keys only, short, and a screen name of
letters, digits, `-` and `_`. It steers the answer and is never used to read anything.

## The panel itself

`Analyst::headerButton()`, which every scaffolded admin header calls, draws three things: the
button, the panel inside an inert `<template>`, and `assets/js/pf-assist.js`.

- The panel opens beside the page, and Esc or ✕ closes it.
- It stays open, on the same tab and conversation, from one administration page to the next
  (per browser tab).
- **Ask** is the analyst's own page, fetched, with its conversation run by `pf-chat.js`. It holds
  the conversation and the question box; the list of conversations and the settings stay on
  `/admin/Ai`, a click away (↗).
- **New** starts another conversation.

Without the script the button is a plain link: to Ask, or to the page's help.

### Buttons of the theme's own

`headerButton()` draws one button. A theme that wants its own, an "Ask" and a "?" for the page's
help each opening the panel on its tab, draws openers and the panel separately:

```php
<?php use Pramnos\Assist\Panel; use Pramnos\Llm\Analyst\Analyst; ?>
<?php if (Analyst::canAsk()): ?>
    <?php echo Panel::opener('ask', t('Ask'), 'btn btn-ghost btn-sm'); ?>
<?php endif; ?>
<?php echo Panel::opener('help', '?', 'btn btn-ghost btn-sm btn-circle', 'aria-label="Help" title="Help"'); ?>
<?php echo Panel::template(Analyst::canAsk(), adminUrl('Ai')); ?>
```

- **Every `[data-pf-assist-open]` is an opener.** One that names a tab (`="ask"`, `="help"`)
  opens the panel there.
- **Pressing it again closes the panel.** Pressing another tab's opener while the panel is open
  turns the panel to that tab.
- **`opener('help')` draws nothing on a page without help.** `opener('ask')` draws nothing on
  Ask's own page.
- **`template()` draws nothing** where there is neither Ask nor help.

### Styling the panel from the theme

The panel always carries its classes: `pf-assist`, `pf-assist-header`, `pf-assist-title`,
`pf-assist-button`, `pf-assist-tabs`, `pf-assist-tab` (styled by `[aria-selected="true"]`),
`pf-assist-pane`, `pf-assist-more`. It also carries inline styles, which win over any class,
unless the application says the theme styles it:

```php
// app/app.php — init writes it
'assist' => ['styles' => 'theme'],
```

Then the panel carries only its classes and is shown and hidden with `hidden`. Every scaffolded
stylesheet has the rules, from the system's own surface colours; a theme overrides them like any
other. A project scaffolded before keeps the inline look until it adds the key and the rules.

## The site's help, for visitors

A site has its own help, apart from the administration's: pages for its visitors in
`app/help-site/`, written exactly as above.

```
app/help-site/posts.md       the site's Posts page
app/help-site/faq.md         a page of its own
app/help-site/images/p.png   a picture a page shows
```

The two are never mixed. The administration's pages describe settings and workflows a visitor
has no business reading, so the site's help reads only `app/help-site/`: no page of `app/help/`,
and none of the framework's, which document its administration screens. `'help' => ['site' =>
['paths' => [...]]]` in `app/app.php` searches other directories instead.

Which pages a request gets is its **level**. Outside the administration area the site's,
inside it the administration's. An application with no area at all, no `admin` prefix and no
`AdminArea::serve()`, has one level, the administration's.

- **The header button** shows the site page's help on a site page, to a visitor signed in or not,
  where `app/help-site/` has a page named after the screen. A visitor gets the help alone; an
  administrator on a site page gets Ask beside it.
- **`/Help`** lists, searches and shows the site's pages, open to everybody, through
  `src/Controllers/Help.php` extending `SiteHelpController`. `init` writes it with the `help`
  feature; a project made before adds it:

    ```php
    <?php
    // src/Controllers/Help.php
    declare(strict_types=1);

    namespace App\Controllers;

    class Help extends \Pramnos\Application\Controllers\SiteHelpController
    {
    }
    ```

- **The analyst** reads the administration's pages: it is an administrator's tool, wherever the
  question was asked from.

```php
use Pramnos\Assist\Help;

Help::site()->forScreen('posts');     // the site's page for a screen
Help::site()->search('sign up', 5);   // the site's pages for a question
Help::book();                         // this request's level: Help::site() or Help::admin()
Help::level();                        // 'site' or 'admin'
```

The static `Help::` methods (`page()`, `forScreen()`, `search()`, `url()` and the rest) are the
administration's.

### The site's help button

By default the button on a site page is the word *Help*. `app.php` changes it:

```php
'help' => ['site' => ['button' => 'icon']],                     // a small, muted "?"
'help' => ['site' => ['button' => App\Help\Button::class]],     // the application's own
```

`'icon'` is a round question mark at 60% opacity, full on hover and focus, named *Help* for a
screen reader and as its tooltip. The scaffolded theme stylesheets style it as `.pf-help-icon`;
a project made before copies that rule into its own stylesheet.

A class of the application's own implements `Pramnos\Assist\HelpButton` and returns the markup:

```php
final class Button implements \Pramnos\Assist\HelpButton
{
    public function render(string $href, string $label, string $attributes, array $page): string
    {
        return '<a class="nav-link" href="' . $href . '" ' . $attributes . '>' . $label . '</a>';
    }
}
```

- `$href` and `$label` arrive escaped.
- `$page` is the page found: `slug` and `title`, raw. Escape them if you print them.
- **`$attributes` must stay on the element that is clicked.** `pf-assist.js` finds the button by
  `data-pf-assist-open` and opens the panel from it, whatever its class or tag. Without the script
  the button is the link `$href`.

The panel itself stays the framework's. An administrator's *Ask*, on a site page or inside the
administration, is not changed by this setting.

In a SPA the same three choices are props of `HelpPanel.svelte`, below.

### Pages for some customers only

A page, or part of one, can require something the reader's plan includes. The framework cannot
know what a plan is, so the application answers with a `HelpGate`:

```php
// app/app.php
'help' => ['site' => ['gate' => App\Help\PlanGate::class]],
```

```php
final class PlanGate implements \Pramnos\Assist\HelpGate
{
    public function allows(string $requirement, \Pramnos\Assist\HelpReader $reader): bool
    {
        return $reader->organizationId !== null
            && Plans::of($reader->organizationId)->has($requirement);
    }
}
```

```markdown
---
requires: editorial          # or [editorial, series]: every one must hold
---
# Editorial

Everybody reads this.

<!-- requires: routines -->
Routines run the editorial plan every morning.
<!-- /requires -->
```

What the gate refuses does not exist for that reader:

- A refused page is not in the list, the search, a page by name, a screen's page, or what the
  assistant reads.
- A refused block is cut, markers included. An allowed one loses only its markers.
- The search scores what is left, so a hidden block's words cannot make its page match.

The markers are whole lines and do not nest. An unclosed block is cut to the end of the page.

**It fails closed.** With no gate configured, every requirement is refused. A gate that throws
refuses, and the error goes to the `help` log.

`HelpReader` is who is asking:

- In a request, `HelpReader::fromRequest()`. That is the signed-in user, or an API token's user,
  and the organisation `OrganizationScope::resolveWith()` names.
- For an assistant, `HelpReader::fromScope($scope)`.
- `HelpReader::guest()` has neither.
- `$reader->user()` loads the user when a gate needs more than the id.

`Help::site()` reads as the request's reader. `Help::site()->for($reader)` reads as another. The
gate applies to the site's pages only; the administration's are not gated.

A picture is served by `/Help/image` to whoever names its path, whatever page shows it. Keep a
picture that must be gated out of `app/help-site/`.

### The application's assistant

The analyst's `help` tool reads the administration's pages. An application's own assistant, the
one customers ask, reads the site's with `SiteHelpTool`:

```php
use Pramnos\Llm\Chat\Tools\SiteHelpTool;

public function tools(Turn $turn): array
{
    return ['help' => SiteHelpTool::tool($turn->scope, fn (string $slug): string => '/app/help?p=' . $slug)];
}
```

- **One page per call:** the best one for a `question`, or the `page` named. It comes with its
  link and with `others`, the names of up to three more that may answer.
- **Gated:** it reads as the scope's person and organisation.
- **Sized to fit:** the text is cut to 18,000 characters, and further if needed, so the encoded
  answer fits one tool result (20,000).
- **Links:** the closure builds a page's address. Without one, it is the site's `/Help`.
- **MCP:** `SiteHelpTool` is an MCP tool too.

## Help in a SPA

A front end that draws its own pages reads the help as JSON. `HelpApi` is mounted with one line
in the API routes. `init` writes it with the `help` feature:

```php
// src/Api/routes.php, inside the version group
\Pramnos\Assist\HelpApi::routes($r);
```

| Route | Answers |
|---|---|
| `GET /help` | every page: `slug`, `title`, `section`, `questions`; with `?q=`, those that answer it |
| `GET /help/page?p=faq` | one page, with `updated` and `html` |
| `GET /help/screen?screen=posts&action=edit` | the page for a screen, found as the side panel finds it |

The answers are the site's pages, to anybody. `?level=admin` reads the administration's, and
only for somebody who may open its Help screen (`admin.help`, above the area's `min_usertype`);
anybody else gets 403. Without the `help` feature every route answers 404.

A page's `html` is ready to draw:

- **Links to other pages** come as `?help=<slug>`. The front end opens them in place.
- **Pictures** come inline as `data:` addresses, up to `HelpApi::MAX_IMAGE_BYTES` (1 MB) each,
  because an `<img>` cannot carry the bearer token the API is called with. A bigger picture
  shows its alt text.

A Svelte SPA has `components/HelpPanel.svelte`. With the `help` feature, the shell sets
`features.help` in `window.__PRAMNOS__`, and the header draws the panel for the screen on show:

```svelte
<HelpPanel screen={route.name} />                 <!-- the site's page for this screen -->
<HelpPanel screen="users" level="admin" />         <!-- an administration screen's -->
```

It shows a Help button only where the screen has a page; a 404 there is the normal answer. The
button is the application's to change:

```svelte
<HelpPanel screen={route.name} compact />                   <!-- a small round, muted "?" -->
<HelpPanel screen={route.name} buttonClass="btn btn-link" /> <!-- the built-in button, other classes -->

<HelpPanel screen={route.name}>                              <!-- a button of the application's own -->
    {#snippet button(open, page)}
        <button class="btn btn-ghost btn-sm" onclick={open}>Guide: {page.title}</button>
    {/snippet}
</HelpPanel>
```

The snippet receives `open()`, which opens the panel, and the page found (`slug`, `title`). It
too is drawn only where the screen has a page. The
panel draws the page and opens `?help=` links in place. A screen's page is named after its route,
`app/help-site/posts.md` for the `posts` screen.

## Adding it to an existing project

Add `'help'` to `features` in `app/app.php`, then publish the scripts:

```bash
php bin/pramnos project:resync --js --all      # pf-assist.js, and pf-chat.js it runs on
```

A project made before the Help screen existed adds the screen's wrapper. Without it the panel
still shows help; only the *All help* link and the menu item are missing:

```php
<?php
// src/Admin/Controllers/Help.php
declare(strict_types=1);

namespace App\Admin\Controllers;

class Help extends \Pramnos\Application\Controllers\HelpController
{
}
```

The Help screen opens for anybody inside the administration area — the area's floor, not a
screen's. Under `admin_access = permissions` it is granted like any screen, by `admin.help`.

The analyst's links to help pages and to its exports are built in the queue worker, under the
area's URL that the question's request handed it — absolute and in the area, whatever
application the worker runs. An administration with a front controller of its own declares
`'admin' => ['serve' => true]` in its config (see the [Routing Guide](Pramnos_Routing_Guide.md#an-administration-with-a-front-controller-of-its-own)),
and its help is linked under the area's URL.

## See also

- [Language models](Pramnos_LLM_Guide.md) — the analyst, its tools, and the conversation page.
- [Routing](Pramnos_Routing_Guide.md) — the administration area and its prefix.
