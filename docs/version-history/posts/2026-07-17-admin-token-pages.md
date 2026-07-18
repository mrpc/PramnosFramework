---
date: 2026-07-17
categories:
  - Changelog
  - Administration
tags:
  - admin
  - tokens
  - oauth2
readtime: 2
---

# Admin Tokens & Token Actions pages fixed (all themes)

The built-in admin **Tokens** and **Token Actions** pages showed nothing (or
wrong data), and their view/revoke links were dead. Both are now correct across
the plain-CSS, Bootstrap and Tailwind themes.

<!-- more -->

## Fixed

- **Admin actions read the route id from the request option, not a method
  argument.** The dispatcher always calls controller actions with an empty
  `$args`, so `…/view/5`, `…/revoke/5`, `…/show/5` etc. must read the id from
  `Request::staticGetOption()`. Tokens, Token Actions, Applications,
  Permissions, Emails and Queue admin controllers were corrected — fixing the
  dead view/revoke/edit/delete links.
- **Token Actions used the wrong columns.** It now selects the real `actionid`
  primary key, joins `urls` to show the human-readable endpoint (the row stores
  an integer `urlid`), renders the Unix `servertime` as a date, and the CSV
  export carries the endpoint instead of the opaque id.
- **Tokens no longer hides session/API tokens.** The applications join is a
  LEFT join, so `web_session`/API tokens (which have no `applicationid`) appear;
  the list shows the username and a formatted last-used time, and the
  `user_id`/`app_id` filters work.
- **Bootstrap & Tailwind parity.** Both themes were brought in line with the
  corrected plain-CSS views, and the Tailwind tables got proper cell styling and
  pill status badges (they previously rendered unstyled).
