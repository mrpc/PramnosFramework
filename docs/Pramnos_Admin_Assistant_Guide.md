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
app/help/billing/refunds.md  a page of its own, reached from the list or a link
app/help/images/refund.png   a picture a page shows
```

**A screen's page is named after the screen**: the controller's class name, lowercase, without
`Controller`. `Admin\Controllers\Users` is `users`, and its `edit` action is `users/edit`, falling
back to `users`. The panel finds it on its own.

**The application's pages come first, whichever of the two it wrote.** An application that wrote
`app/help/users.md` gets it on the edit form too: the framework's own `users/edit.md` is used only
when the application has neither `users/edit.md` nor `users.md`.

The framework ships a page for each of its own screens in `resources/help/`. A page of the same
name in `app/help/` replaces it. `'help' => ['paths' => [...]]` in `app/app.php` searches other
directories instead of `app/help`; the framework's are always searched last.

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
- `updated` — shown under the page.
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
escapes everything first, and has no HTML and no nested lists.

### Search

Words are compared folded — case, accents and the final sigma — and by their first five letters,
so `λογαριασμού` finds `λογαριασμός` and `email` finds `Email`. Words of two letters are ignored.

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
application the worker runs. An administration with a front controller of its own calls
`AdminArea::serve('admin')` in its `init()` (see the [Routing Guide](Pramnos_Routing_Guide.md#an-administration-with-a-front-controller-of-its-own)),
and its help is linked under the area's URL.

## See also

- [Language models](Pramnos_LLM_Guide.md) — the analyst, its tools, and the conversation page.
- [Routing](Pramnos_Routing_Guide.md) — the administration area and its prefix.
