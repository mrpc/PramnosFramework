---
use_cases:
  - Writing help pages for the administration screens of an application
  - Letting an administrator ask the analyst about the record on the page they are looking at
  - Telling the side panel which record an admin screen shows
  - Adding the Help screen and the side panel to an existing project
  - Finding a help page from a question in Greek or English
  - Turning help pages on or off for an installation
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
application the worker runs.

## See also

- [Language models](Pramnos_LLM_Guide.md) — the analyst, its tools, and the conversation page.
- [Routing](Pramnos_Routing_Guide.md) — the administration area and its prefix.
