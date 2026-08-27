---
date: 2026-08-27
categories: [Changelog]
---

# Adding a member to an organization was impossible

The screen has an Add Member form and a Remove link on every row. Neither could run, and
both reported success.

<!-- more -->

## Fixed

**`Organizations::addmember()` never received the organization id.** The form posts to
`organizations/addmember/{id}` and carries only `userid`. The action read the id from its own
route argument — and the classic dispatcher passes the request's arguments **array** to every
action, so `(int)` of it was never the id. `$_POST` carried no `org_id` either, so the id was
0: the screen answered "No valid entries were selected" and redirected to
`organizations/0/members`.

`members()` had it right all along, reading `Request::staticGetOption()`. Both member actions
now go through one `idFromRoute()` that does the same.

**`removemember()` could not receive two ids at all.** The link was
`removemember/{orgId}/{userId}`, and the framework's URL parser turns `action/a/b` into
`$_GET['a'] = 'b'` rather than into two options — so the second id arrived as neither an
argument nor an option. The link is now `removemember/{orgId}?userid={userId}` (updated in
all three bundled themes), and the user id is read from the request only. Not from
`staticGetOption()`: that is the organization segment, and resolving both the same way made
them equal — the update matched no row and the screen still said "Removed."

**A removed member stayed on the list.** `removemember()` keeps the row and sets
`is_active = 0` for the audit trail, and `members()` selected every row regardless. So a
removed member remained on screen, indistinguishable from one who still has access: the
button looked broken, and the page answered "who is in this organization" with everyone who
ever was. Now filtered to active memberships.

**And "Removed." was reported whether or not anything was.** A second click, a back button,
a link bookmarked before somebody else removed them — all matched no row and still reported
success. It now says "That person is not a member of this organization."

## Documentation

- `Pramnos_Framework_Guide.md` — a new *Reading an id out of the URL* under **Controllers**:
  an action's parameters are not URL segments, one id per path, and the rest as query
  parameters.
