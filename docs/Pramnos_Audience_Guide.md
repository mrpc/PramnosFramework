---
use_cases:
  - Declaring what an administrator can filter people by — a column, a side table, a computed value
  - Choosing an audience for a send or a rule from conditions over people's attributes
  - Counting, sampling or paging through the people a filter matches
  - Explaining why one person is or is not in an audience
  - Leaving staff or deleted accounts out of every audience
  - Keeping a named audience up to date and reacting when somebody enters or leaves it
  - Putting everybody a filter matches in a group, or taking them out
  - Making a send or a rule respect what people agreed to receive, by channel
  - Putting a filter builder with a live count on an admin form, or an audience picker on a send
  - Finding out why one person is or is not in a segment, from the admin
  - Recording what people do — payments, posts, page visits — and filtering by it
  - Filling the history of an event from the application's existing tables
  - Taking framework updates while holding the audience migrations back
  - Sending a message, waiting, branching and following up automatically when people do something
  - Adding an application's own action — an SMS, a call list — to the rules
  - Trying a rule before switching it on: why a person would or would not get it, a test send
  - Giving an administrator the right to make and switch on rules
  - Showing people one of two variants and counting which reaches a goal more often
---

# Audience Guide

An **audience** is the people who match a **filter**: conditions over their **attributes**,
grouped with "all" and "any". Sends, segments and rules all choose their people this way, so an
application declares its attributes once and every screen can use them.

## Before the migrations run

The audience tables — `segments`, `pramnos.segment_members`, `consent_lists`, `pramnos.person_events`, `rules`,
`rule_versions`, `pramnos.rule_runs`, `pramnos.rule_log`, `site_messages`, `experiments`,
`pramnos.experiment_assignments` — come from core migrations, and an application can hold them back while
it tries the feature elsewhere:

```php
// app/app.php
'migrations' => ['exclude' => ['create_segments_tables', 'create_consent_lists_table', 'create_person_events_tables', 'create_rules_tables', 'create_site_messages_table', 'create_experiments_tables']],
```

Until they exist, nothing here does anything: a dispatched `PersonEvent`, an event with
`Segments::refreshOn()` on it, a named page, an email open or click queue nothing and raise
nothing; `segments:recompute` and `audience:prune-events` succeed with one line; Segments and
Consent lists stay out of the menu and, typed in, say they are not installed; the person card
shows groups alone. `Pramnos\Audience\Installed` answers whether they are there, asked only where
something would use them and remembered for five minutes per process, so a worker notices a
`migrate` without asking on every event.

## People

A person is one row of the users table, identified by `users.userid`. There is no other table of
people: every id an audience stores is a `userid`.

Who counts as a person at all is the application's **scope**, applied to every count, sample,
page and explanation:

```php
use Pramnos\Audience\Attributes;
use Pramnos\Database\QueryBuilder;

Attributes::scope(fn (QueryBuilder $q) => $q
    ->where('users.usertype', '<', 97)      // staff are not an audience
    ->where('users.active', '!=', 2));      // nor deleted accounts
```

## Attributes

An attribute is one thing a condition can ask about a person. The framework declares the generic
ones over the columns every users table has:

| Key | Type | Reads |
| --- | --- | --- |
| `usertype` | number | `users.usertype` |
| `language` | text | `users.language` |
| `regdate` | date | `users.regdate` |
| `lastlogin` | date | `users.lastlogin` (0 is "never", so empty) |
| `active` | list | `users.active` — Active, Inactive |
| `validated` | boolean | `users.validated % 2` — odd is a verified address |
| `groups` | many | `userstogroups.groupid`, the groups as choices |
| `email_optouts` | many | the lists the address left by the unsubscribe link (`emailoptouts.list`, `all` among them) |

An application adds its own, and replaces one of these by defining it under the same key — for
example to give `usertype` its own account types:

```php
use Pramnos\Audience\Attribute;

// A column of the users table, with labelled choices
Attributes::define(Attribute::column('usertype', 'usertype', Attribute::LIST)
    ->label(t('Account type'))->group(t('Account'))
    ->values([1 => t('Parent'), 2 => t('Professional'), 3 => t('Both')]));

// A column of a one-to-one table, joined on its userid
Attributes::define(Attribute::column('pillar', 'pillar', Attribute::LIST)
    ->from('professionals')->label(t('Pillar'))->values(fn () => Pillars::labels()));

// A one-to-many table: a person has none, one or several values
Attributes::define(Attribute::many('interests', 'userinterests', 'interestid')
    ->label(t('Interests'))->values(fn () => Interests::labels()));

// A value the query builder cannot write: an SQL expression, one per driver if they differ
Attributes::define(Attribute::expression('age',
    ['mysql' => 'TIMESTAMPDIFF(YEAR, FROM_UNIXTIME(users.birthdate), NOW())',
     'postgresql' => "EXTRACT(YEAR FROM age(to_timestamp(users.birthdate)))"],
    Attribute::NUMBER)->label(t('Age')));

// Only accounts with an ability may see or filter by it
Attributes::define(Attribute::column('revenue', 'revenue', Attribute::NUMBER)
    ->from('customer_totals')->ability('reports.revenue'));
```

- **One-to-one tables** (`->from()`) are LEFT JOINed under their own name, once, and only when a
  condition reads them. A person with no row there has an empty value — which is why "is not",
  "not one of" and "does not contain" include them.
- **One-to-many tables** (`Attribute::many()`) are never joined. They are asked with `EXISTS`, so
  a count is always a count of people, however many rows each has.
- **Expressions** are SQL written by the application, never by the person filtering: the value
  compared with them is always bound. A boolean expression yields 1 or 0.
- **A one-to-many table keyed on something else** says what its key is compared with:
  `->matching('LOWER(users.email)')` for a table keyed on the address. It cannot be broken down.
- **Dates** are kept as seconds by default; `->storedAs('datetime')` or `->storedAs('date')` for a
  column of that type. Days are counted in the application's time zone.
- **Choices** given as a closure are read only when the builder needs them, never on a count.

`Attributes::visible()` is the set the signed-in account may use: what the filter builder offers.

### Operators

| Type | Operators |
| --- | --- |
| text | `is`, `is_not`, `one_of`, `not_one_of`, `contains`, `not_contains`, `starts_with`, `empty`, `not_empty` |
| number | `is`, `is_not`, `gt`, `lt`, `between`, `one_of`, `not_one_of`, `empty`, `not_empty` |
| date | `after`, `before`, `between` (`YYYY-MM-DD`), `more_than_days_ago`, `less_than_days_ago` (days), `empty`, `not_empty` |
| boolean | `true`, `false` (false includes empty) |
| list | `is`, `is_not`, `one_of`, `not_one_of`, `empty`, `not_empty` |
| many | `any_of`, `all_of`, `none_of`, `empty`, `not_empty` |

Text compares ignoring case on both databases, and `%` or `_` in a value is a literal. "After" a
day starts the next day; "before" a day ends the one before; "between" includes both days whole.
"Before" and "more than N days ago" leave out an empty date: "never signed in" is `empty`, its
own condition.

## Filters

A filter is JSON — stored as it is, so a segment, a send or a rule keeps the conditions that were
chosen rather than the list they meant once:

```json
{"match": "all", "conditions": [
  {"attribute": "usertype",  "op": "is",                 "value": 2},
  {"attribute": "lastlogin", "op": "more_than_days_ago", "value": 30},
  {"match": "any", "conditions": [
    {"attribute": "groups",   "op": "any_of", "value": [4, 7]},
    {"attribute": "language", "op": "is",     "value": "el"}
  ]}
]}
```

Groups nest up to five deep. An empty filter is everybody in scope.

```php
use Pramnos\Audience\Filter;

$filter = Filter::fromArray($json);     // checked here; InvalidArgumentException says what and where

$filter->count();                       // how many people now
$filter->sample(10);                    // the newest ten: userid, username, names, email
$filter->ids(after: 0, limit: 1000);    // a page of ids; pass the last one to get the next
$filter->matches($userid);              // one person
$filter->breakdown('language');         // [['value' => 'el', 'label' => 'el', 'count' => 812], …]
$filter->query();                       // the QueryBuilder, for anything else
```

A filter is checked once, when it is made: an unknown attribute, an operator its type does not
have, or a value of the wrong shape (`filter.1.0: between needs two values.`) is refused there, so
a filter that loads always compiles. Values are cast to what they compare with — numbers from a
form arrive as strings.

Every answer reads with `getAllOrFail()`, so a query that fails — a side table named wrong in
`->from()` — throws `QueryException` on both databases instead of coming back as nobody.

Pages of ids are a keyset on `userid`, so the thousandth page costs what the first did — a full
recomputation walks an audience of any size with `ids()` and the last id of each page.

## Why yes, why no

`explain($userid)` answers for one person, condition by condition:

```php
$filter->explain(1234);
// ['ok' => false, 'inScope' => true, 'match' => 'all', 'conditions' => [
//     ['attribute' => 'usertype', 'op' => 'is', 'value' => 2, 'label' => 'Account type',
//      'actual' => 1, 'ok' => false],
//     ['match' => 'any', 'ok' => true, 'conditions' => [ … ]],
// ]]
```

`actual` is the value the person has — a list for a one-to-many. A group's `ok` combines its
conditions as the query does, and `inScope` says whether the person is an audience member at all:
a staff account can satisfy every condition and still be out.

## Segments

A segment is a filter with a name, kept worked out: `pramnos.segment_members` holds who is in it, one row
per stay (`entered_at`, `left_at` — NULL while it lasts), so a person who leaves and comes back
has two rows and "who entered this week" is a range over one column.

```php
use Pramnos\Audience\Segments;

$id = Segments::create('Dormant professionals', $filter, owner: $adminId, description: 'For the win-back series');
Segments::update($id, ['filter' => $newFilter]);   // applies at the next recomputation

Segments::recompute($id);              // ['entered' => 12, 'left' => 3, 'members' => 840]
Segments::recomputeAll();              // every segment, each after the ones its filter names
Segments::recomputePerson($userid);    // one person, against every segment, now

Segments::of($userid);                 // the person card: [['id', 'name', 'since'], …]
Segments::movement($id, days: 7);      // the library: ['entered' => 31, 'left' => 9]
Segments::isMember($id, $userid);
```

- **The first working out is the start, not movement.** It puts everybody who matches in at once,
  silently — no `segment.entered` events — and records the moment as `firstComputedAt`. Who
  entered and who left (`movement()`, `movements()`, the library, Ask, the rule tester's entries
  into a segment) counts only what happened after it, so a segment made today shows 0 entered,
  not its size; the library and the segment's page add *since DD/MM* while it is younger than a
  week, so that 0 does not read as a still week. A later change of filter is movement: its
  working out announces who entered and who left. `Segments::countsFrom($id, $since)` is where
  counting starts for one segment.

- **Kept up to date twice over.** The framework schedules `segments:recompute` hourly, which
  catches what time alone changes ("last sign-in more than 30 days ago"). For changes a person
  makes, `Segments::refreshOn('user.saved', 'profile.updated')` puts a queued listener on those
  events: the person is recomputed in the worker, and a burst of events about one person within a
  minute is one recomputation. The event's first argument names the person — an id, or an object
  with `personId()`.
- **Every change is an event.** `segment.entered` (`SegmentEntered`) and `segment.left`
  (`SegmentLeft`), dispatched as classes with `segmentId`, `personId` and `at`, so a queued listener
  receives them again in the worker. **Except the first recomputation**, which records who is in
  without announcing them: they did not just arrive, and a rule that greets new members must not
  greet everybody the moment a segment is made. Until then, a one-person recomputation skips the
  segment.
- **Segments of segments.** A condition can be `{"segment": 4, "op": "in"}` or `"not_in"`: in the
  segment now. A full run computes a segment after the ones it names; a filter that would name
  its own segment, directly or through others, is refused when saved.
- **In use is not deleted.** `Segments::delete()` refuses while another segment's filter names it,
  or while something an application reports through `Segments::usage('rules', fn (int $id) =>
  [...names...])` does; the refusal lists what. Deleting takes the history with it.

## Groups

Groups are the framework's `usergroups` / `userstogroups` — the same ones permissions and the
Groups screen use. To a filter they are the `groups` attribute (`any_of`, `all_of`, `none_of`).
Membership changes go through one path, whoever makes them:

```php
use Pramnos\Audience\Groups;

Groups::add($groupId, [$userid, $other], by: $adminId);     // how many were added
Groups::remove($groupId, $userid, source: 'rule');
Groups::addMatching($groupId, $filter, by: $adminId);       // the bulk action, paged
Groups::removeMatching($groupId, $filter);
Groups::of($userid);        // [['id' => 4, 'name' => 'Press', 'addedAt' => 1761…, 'addedBy' => 7, 'source' => 'admin'], …]
Groups::movements();        // [4 => ['entered' => 12, 'left' => 3], …] over seven days
```

Only real changes count: a person already in is not added again, and one not in is not removed.
Each real change flushes the cache `User::getGroups()` reads, tells the applications the person
uses when the group holds a role, and dispatches `group.joined` (`GroupJoined`) or `group.left`
(`GroupLeft`) with `groupId`, `personId`, `by` and `source` (`admin`, `bulk`, `rule`, or the
application's own word). The Groups screen adds and removes members through it too.

An application with its own `addGroup()` / `removeGroup()` on its user class can keep them and
have them call these, so a membership changed anywhere is announced the same way.

**When, and who came and went.** With the migration `add_membership_history_to_usergroups`, a
membership keeps when it began, the account that made it and how (`userstogroups.addedat`,
`addedby`, `addedsource`) — state, gone with the membership. Every real change is also written to
the changelog as `group.joined` or `group.left` on the group (entity `usergroup`, `Groups::ENTITY`),
with the person and how; that is what counts who entered and who left a group over a week, so
only changes made through `Groups::add()` / `remove()` count, and memberships older than the
migration are not counted as entering. An application that knows when they began from a log of its
own fills `addedat` itself. Without the migration nothing is said of when
(`Groups::keepsHistory()`); without the `changelog` feature there is no movement to count.

**What uses a group** is what the library's *used by* shows and what keeps a group from being
deleted: a segment whose filter names it, a rule that reads it or puts people in it or takes them
out (`Rules::linksUsingGroup()`), and what the application reports — a coupon that adds its buyers,
a package, a support team:

```php
Groups::usage('coupons', fn (int $id): array => array_map(
    fn ($coupon) => ['name' => 'coupon ' . $coupon->code, 'url' => adminUrl('Coupons/edit/') . $coupon->id],
    Coupon::addingTo($id),
));
Groups::uses($groupId);   // [['name' => 'segment Greek speakers', 'url' => …], ['name' => 'coupon SUMMER', 'url' => …]]
```

A finder answers names, or `['name' => …, 'url' => …]` where there is a page to open.
`Filter::groupsIn($filter)` lists the groups a filter names, as `segmentsIn()` lists segments.

## Consent lists

A send or a rule never names a field to find out who agreed — it names a **consent list**, so
where consent is kept can change without touching a rule. The application declares **sources** in
code: a filter that means "agreed", and the users column a new account's default is written to.
Administrators make **lists** over them: a name, a channel (`email`, `sms`, `push`, `phone`,
`site`), a description in words a person reading their settings understands, and the default for
new accounts.

```php
use Pramnos\Audience\ConsentLists;

// code
ConsentLists::source('smsoffers', t('Offers by SMS (profile setting)'),
    ['conditions' => [['attribute' => 'smsoffers', 'op' => 'true']]], column: 'smsoffers');

// the screen, or a seeder
$sms  = ConsentLists::create('SMS offers', 'sms', 'smsoffers', description: t('Offers and discounts by SMS'), defaultOn: false);
$news = ConsentLists::create('Newsletter', 'email', 'everyone', optOutList: 'newsletter');

// a send
$final   = ConsentLists::narrow($audience, $sms);    // a Filter: the audience that agreed
$removed = ConsentLists::removes($audience, $sms);   // how many consent takes out
ConsentLists::allows($sms, $userid);                // one person, now

// registration
ConsentLists::applyDefaults($userid);               // each list's default into its column
```

- **Email always respects the unsubscribe link.** An email list leaves out every address that left
  `all`, and its own `optOutList` when it names one — whatever a profile checkbox says.
- **`everyone`** is the framework's source: no stored preference, so on an email list only the
  opt-outs decide — the model for service mail with an unsubscribe link.
- **Defaults** go into the source's column; where two lists read one column, the one made last
  wins. A source with no column has nothing to write.
- **In use is not deleted**: `ConsentLists::usage('rules', fn (int $id) => [...names...])`, as for
  segments. A list whose source the code no longer declares is refused when it is read, rather
  than read as "nobody agreed".

## The builder and the picker

Two form fields draw all of this for an administrator, with the count beside them as it changes:

```php
use Pramnos\Html\AudienceBuilder;

// a segment's form: conditions in groups of "all" / "any", the count, a breakdown, a sample
echo AudienceBuilder::filter('filter', $segment['filter'] ?? null);

// a send's form: in any of these segments and groups, except these, a filter for this send,
// and the consent list — with what each step takes out
echo AudienceBuilder::picker('audience', $send['audience'] ?? null, ['channel' => 'email']);
```

The field posts its state as JSON — a filter, or a picker state that `Picker` compiles:

```php
use Pramnos\Audience\Picker;

$state = Picker::normalise($_POST['audience']);   // checked; InvalidArgumentException says where
Picker::final($state)->ids();                     // who the send reaches, now
Picker::counts($state);                           // ['included', 'excluded', 'consent', 'final']
```

Store the state, not the people: a send that waits for its hour reaches the audience as it is
then.

The mass-message compose form carries the picker: a message whose audience was picked stores
`['audience' => $state]` as its criteria, and `MassMessageAudience` resolves it through `Picker`.


- **What it offers** is what the signed-in account may use: `Attributes::visible()`, every
  segment, group and consent list (`picker()` with a `channel` offers that channel's lists only).
  It is written into the page, so the builder draws with no request.
- **The numbers** come from `/admin/Audience/preview`, a framework controller behind the
  administration floor, which refuses an attribute the account may not see even in a hand-made
  request. A condition still missing its value is left out until it has one, and an answer that
  arrives after a newer question is dropped.
- **A sample** links each person to their card: the framework's Users screen, or
  `'audience' => ['person_url' => '/admin/People/{id}']` in app.php. Under each name is a line:
  the email, or the attributes the application names —
  `Attributes::sampleLine(['usertype', 'city', 'lastlogin'])` reads "Professional · Athens · 2
  days ago" (labelled values; a date as today, yesterday, so many days ago within a month, else
  its day, and none as "never"; a many-valued attribute is left out).
- **The conditions** sit on one line each: what (a searchable list under the attributes'
  headings, what people did under theirs, and *Segment*), how, the value, and a remove button.
  The word between two rows is a switch between *all* and *any*.
- **Options:** `sample` (how many people, default 5), `breakdown` (`filter()` only, default on),
  `live` (the panel, default on), `api`.
- `pf-audience.js` ships with the framework; a project made before it reaches its web root with
  `project:resync --js --all`. It fires `pf:audience-change` with the state on every change.

## The screens

They look the same in every theme: the markup is the framework's own `pf-*` classes — page head,
cards, tabs with counts, tables whose row is one link, status pills, a segmented control,
fields, a footer that keeps a long form's buttons in reach — dressed by each theme's
`components.css` from the theme's colours (daisyUI's `--color-*`, Bootstrap's `--bs-*`, the plain
theme's variables). A layout that prints the page's title itself hides the screens' own with
`$document->bodyclasses[] = 'pf-titled'`.

*Groups and segments* (`/admin/Segments`) has a tab each — all of them, groups, segments, and the
account's own segments — with its count. Groups and segments are different things, so each tab has its own
server-side datatable, with its own columns, column picker and export name; its rows come from
`Segments/data` with the tab:

| tab | columns | first order |
|---|---|---|
| All | name and description, kind, members, used by | kind: groups first, each in its own order |
| Groups | #, name and description, members, entered and left in seven days, order, used by | the order; a group with none after |
| Segments | name and description, members, entered and left in seven days, used by, owner, last worked out | most members |
| Mine | as Segments, without the owner | most members |

Each sorts by any column but *used by*, searches the number, name and description, and exports.
A segment not yet worked out reads *being worked out*, not 0. The week's movement is counted for
every segment in two queries, so the list can sort by it; what uses a group or a segment is worked
out for the rows shown only. The button makes what the tab lists — a group or a segment, and both on *all*. A default tab not
among `tabs` gives way to the first that is.

The groups tab is shown unless the address asks for another (`?kind=segments`, `?mine=1`), or
app.php names its own first:

```php
'audience' => [
    'tabs'              => ['all', 'groups', 'segments', 'mine'], // the tabs shown, in this order; all four unless set
    'default_tab'       => 'segments',                            // all, groups, segments or mine; groups unless set
    'row_actions'       => ['groups', 'segments'],                // open, edit, delete on each row; ['groups'] unless set
    'group_link'        => 'members',                             // a group's name leads to its members' list…
    'group_members_url' => '/admin/users/display/groupid/{id}',   // …here; 'page' (the default) leads to the group's page
],
```

A tab whose kind `row_actions` names ends with *Row actions*: open, edit and delete, each an icon
with its name as `title` and `aria-label`. Delete is a POST with the form token and asks first;
while something uses the group or segment it is disabled, its title saying what uses it, and the
server refuses it then too. The column is not sorted by and stays out of every export.
`Controllers\Groups::linkFor($id)` is where a group's name leads, wherever the administration
shows one — the library and a person's card.

Two pieces work in any form:

```html
<!-- a searchable choice under headings, posting the hidden field -->
<div data-pf-combobox data-groups='[{"label":"Payments","options":[{"value":"payment.made","label":"Paid"}]}]'
     data-placeholder="Choose an event…"><input type="hidden" name="goal"></div>

<!-- a search over a table's rows (pf-utils.js), matched on each row's data-pf-row-text -->
<input type="search" data-pf-filter-rows="#my-table">
```

The first needs `pf-audience.js` on the page.

The administration screens, under People:

- **Segments** (`/admin/Segments`, ability `admin.segments`) — the library: every segment with how
  many are in it now, who entered and left over seven days, and what uses it; the groups beside
  them with their counts; tabs for segments, groups, and the account's own. A segment's page has
  its numbers, its newest members, *Work it out now*, and **Why is this person in or out?** —
  an account id explained condition by condition, ✓/✗ with the person's value. Saving a segment
  works it out at once. One in use cannot be deleted.
- **Consent lists** (`/admin/ConsentLists`, ability `admin.consent`) — every list with its channel,
  where it is read from, the default for new accounts and how many are in it now; a list whose
  source the code no longer declares says so instead of a count.

Each change to a segment or a list is in the audit log with the before and after
(`segment.created`, `segment.updated`, `segment.deleted`, `consentlist.*`).

**On a person's card**, `\Pramnos\Html\AudiencePanel::person($userid)` lists the segments they
are in, since when — each linked to the segment's "why" for them — and their groups. The
framework's Users screen shows it; an application's own card puts it where it likes.

## What people did: person events

Attributes are what is true of a person now; **events** are what they did, and when. A filter can
ask about both.

```php
use Pramnos\Audience\PersonEvents;

PersonEvents::define('payment.made', t('Paid'), ['amount' => t('Amount'), 'package' => t('Package')], t('Payments'));
```

**Recording needs no call of its own.** An event class that implements
`Pramnos\Audience\PersonEvent` — `personId()`, `properties()` — is recorded for that person
whenever it is dispatched, in the queue:

```php
final class PaymentMade implements QueueableEvent, PersonEvent
{
    public const EVENT = 'payment.made';
    public function personId(): ?int { return (int) $this->invoice->userid; }
    public function properties(): array { return ['amount' => $this->invoice->total]; }
    // …
}

Event::dispatch(new PaymentMade($invoice));     // its listeners, and the person's record
PersonEvents::recordLater($userid, 'login');    // or directly, for something with no class
```

Where nothing could be queued it is recorded at once rather than lost, and after recording the
person's segments are worked out again (folded per person). The framework records two itself:
`email.opened` and `email.clicked`, for a message the mail log ties to an account.

**Named pages** are events too — a signed-in person opening a path that matches:

```php
PersonEvents::page('premium.viewed', t('Saw the Premium page'), '/el/premium*');
```

matched on the path alone, ignoring case, on every page the application renders. Only the pages
named are recorded, never every URL.

### The summary, and the change log

| | Keeps | Answers |
| --- | --- | --- |
| `pramnos.person_events` | per person and event: how many, the first, the last — for good | ever, never, at least / at most N of all time, first or last before / after a day, last within N days, *had it been on* |
| `pramnos.changelog_events`, entity `user` | each occurrence, with its properties as `details` — what the change log keeps | at least / at most N **in the last M days**; an A/B test's goal |

Each occurrence is an application event of the change log (`PersonEvents::LOG`): written through
the write spool — a file append, or Redis where `spool_driver` says so — and drained into the
table a minute later; partitioned weekly, compressed and kept two years on TimescaleDB, as the
`changelog` feature declares. A person is the event's `itemid`, as a string; the index on
`(entity, itemid, created_at)` answers one person's window, and `(entity, event, created_at)`
everybody's. So a windowed count sees an occurrence about a minute after it, while the summary,
rules and segments see it at once.

The table is what is needed, not the feature: an application that runs `create_changelog_tables`
with the `changelog` feature off — no model audit trail — keeps every occurrence all the same.
Without the table only the summary is kept: a window condition is refused with what to enable,
and an A/B test says its goal is not counted.

### Conditions

```json
{"event": "payment.made", "op": "ever"}
{"event": "payment.made", "op": "at_least", "value": 3, "days": 30}
{"event": "login",        "op": "not_within_days", "value": 30}
{"event": "post.created", "op": "first_after", "value": "2026-01-01"}
```

| Operator | Value |
| --- | --- |
| `ever`, `never` | — |
| `at_least`, `at_most` | a count; with `days`, in that window (from the log), else all time |
| `last_within_days`, `not_within_days`, `first_within_days` | days |
| `first_before`, `first_after`, `last_before`, `last_after` | a day, `YYYY-MM-DD` |

`at_most` and `never` include people who never did it. `explain()` gives the person's count,
first and last, and the count in the window when there is one. The builder lists the declared
events under *What they did*.

### The past

The history an application already has — invoices, requests, logins — goes in once:

```php
PersonEvents::backfillFrom('payment.made',
    $db->queryBuilder()->table('invoices')->where('status', 1),
    'userid', 'paid_at', 'datetime',
    fn (array $row) => ['amount' => (float) $row['total']]);

PersonEvents::backfill('login', $occurrences);   // [[userid, unix time, properties?], …]
PersonEvents::forget('payment.made');            // to run a backfill again without counting twice
```

Run a backfill before live recording of that event starts, or `forget()` it first: a second run
counts every occurrence again. `PersonEvents::of($userid)` lists what one person did, newest first.

## Rules

A rule is **when** something happens, **who** it is for, **what** to do in which order, and
**until** when — kept as versioned JSON and run in the queue.

```php
use Pramnos\Audience\Rules\Rules;
use Pramnos\Audience\Rules\Engine;

// app/app.php init — in the request and in the worker
Engine::listen();

$id = Rules::create('Premium expiring', [
    'trigger' => ['type' => 'segment_entered', 'segment' => $expiringSoon],
    'who'     => ['conditions' => [['attribute' => 'autorenew', 'op' => 'false']]],
    'steps'   => [
        'mail'  => ['type' => 'action', 'action' => 'email', 'params' => ['template' => 'premium.expiring', 'list' => 'offers'], 'next' => 'wait'],
        'wait'  => ['type' => 'wait_event', 'event' => 'payment.made', 'seconds' => 3 * 86400, 'next' => null, 'timeout' => 'check'],
        'check' => ['type' => 'branch', 'message' => ['step' => 'mail', 'status' => 'opened'], 'yes' => 'call', 'no' => 'sms'],
        'sms'   => ['type' => 'action', 'action' => 'sms', 'params' => ['text' => '{firstname}, 4 days left'], 'next' => null],
        'call'  => ['type' => 'action', 'action' => 'notify_admin', 'params' => ['to' => 12, 'subject' => 'Call {full_name}'], 'next' => null],
    ],
    'exit'    => ['conditions' => [['event' => 'payment.made', 'op' => 'last_within_days', 'value' => 1]]],
    'goal'    => ['event' => 'payment.made', 'days' => 10],
    'consent' => $offersList,
    'hours'   => ['from' => '09:00', 'to' => '21:00'],
    'caps'    => ['sms' => ['max' => 1, 'days' => 7]],
], owner: $adminId);

Rules::setStatus($id, 'shadow');   // runs, writes what it would do, sends nothing
Rules::setStatus($id, 'active');
```

**When** (`trigger`): `event` — a named event about a person (its first argument an id, or an
object with `personId()`); `segment_entered` / `segment_left` — starts or stops matching;
`schedule` — a cron expression, everybody who matches then (`rules:run`, every minute); `once` —
everybody who matches when it is switched on. **Entry**: `first` (once ever) or `every` (again
after the last run ended, never two at once).

**What** (`steps`, each naming the next or `null` to finish; no step leads back to itself):

| Step | Does |
| --- | --- |
| `action` | an action, below |
| `wait` | `seconds`, then `next` |
| `wait_event` | until `event` about the person → `next`; after `seconds` without it → `timeout` |
| `branch` | on a `filter` (any condition, attributes, events, segments), or on what happened to a message an earlier step sent (`opened`, `clicked`, `delivered`) → `yes` / `no` |
| `split` | `variants` by share, adding to 100 — the same person always in the same one |

**Until**: `exit` — a filter that takes the person out at the next step; `goal` — an event within
some days of entering, which ends the run as reached.

### Actions

The framework's: `email` (a mail template, in the person's language, into the outbox; with a
`list`, the unsubscribe link, open/click tracking, and nobody who left the list), `bell` and
`push` (a notification), `site_message` (a banner, popup or card on the site, below), `group_add` / `group_remove`, `attribute` (a column of a declared
attribute), `notify_admin`, `webhook` (POSTs the person and the origin), and — with the
`conversations` feature — `conversation_post` (tells the team in a channel, or one administrator;
see the [Conversations Guide](Pramnos_Conversations_Guide.md#references-and-commands-what-the-application-adds)).
An application adds its own:

```php
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;

Actions::define('sms', t('Send an SMS'), ['text' => ['label' => t('Message'), 'type' => 'textarea']],
    function (int $person, array $params, Context $context): array {
        $sent = Sms::send($person, Actions::fill($params['text'], $person), origin: $context->origin());

        return ['status' => $sent ? 'sent' : 'error', 'detail' => ['provider' => $sent?->id]];
    }, channel: 'sms');
```

**Templates.** The email action offers the framework's mail templates (`mailtemplates`, by
category and title) and the declared mail types' unsubscribe lists, as choices in the editor. An
application whose templates live elsewhere declares where:

```php
Actions::templates(
    fn () => Templates::titles(),                                    // ['jobs.new' => 'New job posts', …]
    fn (string $key, object $user) => Templates::render($key, $user), // ['subject' => …, 'body' => …] or null
);
```

The second answers with the template filled for the person — in their language, placeholders
replaced — or null, which the run logs as an error. It may name the email layout to wrap it in as
`'emailtemplate'`; an empty one sends the body bare, for a body the application has already laid
out. Left out, the installation's default layout applies.

An action answers `sent`, `done`, `skipped` or `error`, with what is worth keeping. One with a
**channel** is a message, and before it the engine checks, in order: the sending **hours** (it
waits for them), the rule's **consent list** (skipped), the **frequency caps** — the rule's, then
`'audience' => ['caps' => ['email' => ['max' => 1, 'days' => 1]]]` in app.php, counted over every
rule's sent messages — (skipped). In **shadow** mode no action is called; the log says what it
would have been.

### Origin, versions and the run log

Every action knows where it comes from (`Context`): `origin()` is `rule:4:v3:mail:run812`, which an
email keeps in `mails.module = 'rule'` / `moduleinfo` — how a branch finds the message it asks
about, and how the Email Log's message page says *Sent by rule …, version …, step …*, linked to
the rule (`Context::parse()` reads an origin back). A site message keeps it in its `origin`; an
application's SMS or push action passes `$context->origin()` or `toArray()` to its provider. `toArray()` adds the trigger and its
causation ids, the variant and the consent list.

A saved change is a new version (`rule_versions`); runs finish on the version they started on.
Creating, changing, switching and deleting are in the audit log with before and after. Each run
(`pramnos.rule_runs`) moves only from the step its queued task names — a retried task, or a timeout after
the event it waited for, does nothing — and every decision is a row in `pramnos.rule_log`: entered, each
action's outcome and reason, each branch with its conditions and values, waits, exit, goal. The log
keeps `audience.rule_log_days` (90; 0 keeps it), pruned daily by `audience:prune-events`, which
also deletes segment stays that ended more than `audience.segment_history_days` ago (365). A segment or consent
list a rule reads cannot be deleted; a running rule cannot be either.

### Trying a rule before it runs

```php
use Pramnos\Audience\Rules\Tester;

Tester::why($rule, $userid);          // ['enters' => false, 'reasons' => ['who does not match'], 'who' => explain, 'exit' => …, 'steps' => …]
Tester::simulate($rule, days: 7);     // ['entered' => 486, 'messages' => ['email' => 486, 'sms' => 486], 'basis' => 'the event log']
Tester::testSend($rule, 'mail', $userid, $me);   // the step's action, to me, filled with their details
Tester::diff($rule, 3, 4);            // ['before' => 812, 'after' => 790, 'in' => 12, 'out' => 34]
```

- **why** — entered before (first time) or already on its way (every time), who with every
  condition and value, the exit, and for each step: the channel and consent an action would meet,
  the conditions a branch would read.
- **simulate** — the last days had the rule been on: the people the trigger's events brought
  (the event log, or entries into or departures from the segment) who match *now*, and at most as
  many messages per channel as there are message steps; a once or schedule rule counts who matches
  now. An estimate, and it says what it was counted from.
- **testSend** — a step's action for a real person's details, sent to the account that asked —
  `[Test]` in an email's subject, `Context::isTest()` for an application's action — and part of no
  run.
- **diff** — what changing who does, before saving it as the version to run.

Shadow mode is the live trial: the rule runs for real people on real events and writes in its log
what it would have sent.

### The Rules screen

`/admin/Rules` (People → Rules) is behind its own ability, `admin.rules`: rules send to people on
their own, so an administrator is given it or not. A new rule needs the tables
(`create_rules_tables`); until then the item stays out of the menu.

- **The list**: each rule's status, its trigger in words, how many came in over seven days, how
  many are on their way, how many reached the goal.
- **The editor** (`Html\RuleEditor`, `pf-rules.js`): when, who and the exit in the audience
  builder, the steps as boxes — each with its fields and where it leads, a branch on conditions
  with a builder of its own — and goal, consent list, sending hours, caps. Saving a change is a new
  version; a new rule is a draft.
- **A rule's page**: the boxes drawn with how many went through each and what they came to
  (`Html\RuleCanvas`), *Run in shadow* / *Switch on* / *Pause*, **why** for an account id,
  **had it been on** for 7 or 30 days, **send a test to me** for a message step with an account's
  details, the versions with *compare*, and the last 50 rows of the log. Only a message step can be
  tried on oneself — a group or attribute change would be real.


## Messages on the site

A banner across the page, a popup, a card — for one person, until they close it or it expires:

```php
use Pramnos\Audience\SiteMessages;

SiteMessages::post($userid, 'banner', t('Your Premium ends on Friday'), '<p>Renew to keep it.</p>',
    link: '/premium', linkText: t('Renew'), days: 7);
```

A rule's `site_message` action does the same, with its origin. The layout shows them to whoever
is signed in — the scaffolded themes beside the flash messages:

```php
echo \Pramnos\Html\SiteMessages::render();          // banners and one popup at a time
echo \Pramnos\Html\SiteMessages::render('card');    // cards, where the page keeps them
```

Each is neutral `pf-site-message` markup the theme dresses; the popup a `<dialog open>`; the close
button a form to `/SiteMessages/dismiss/{id}` with the session's token, back to the page — no
script. The first showing is recorded (`seenat`), and the closing (`dismissedat`). Nothing for a
visitor, and nothing before the table is installed.

## Asking about audiences

The administrators' Ask has five tools here, offered where the tables are and behind each screen's
ability: `audience_segments`, `audience_rules` and `audience_experiments` read the libraries and
their figures; `audience_why` explains one account against a segment or a rule; `audience_draft`
writes drafts only — a segment as a link to `/admin/Segments/edit?draft=…`, which opens the
builder filled in for an administrator to save, and a rule saved as a draft. See the
[LLM guide](Pramnos_LLM_Guide.md).

## A/B tests

A test gives each person — or visitor — one of two or more variants by share, the same one every
time, and counts which reaches a goal more often:

```php
use Pramnos\Audience\Experiments;

if (Experiments::variant('annual-first') === 'B') {
    // show the annual plan first
}
```

`variant($slug, ?$person, ?$visitor)` answers null for a draft, a stopped test, a test that does
not exist, or somebody outside the test's segment; the winner for everybody once one is decided.
Without arguments it takes whoever is signed in, else the visitor's `pf_visitor` cookie (set once).
The assignment is kept (`pramnos.experiment_assignments`), so the answer never changes; a visitor who
signs in keeps what they were shown — it moves to the account once a session, unless the account
had its own.

Made on `/admin/Experiments` (ability `admin.experiments`) or in code — `create`, `update` while a
draft, `start`, `stop`, `decide($id, 'B')`, each audited. A test has a name for the code, two
variants or more with shares adding up to 100, optionally a segment, and a goal: a person event
within N days of being given a variant.

**Results** (`Experiments::results($id)`): per variant the people, how many reached the goal, the
rate, **how sure** the difference from the first variant is (a two-proportion z-test), and how many
more people each variant needs for the difference seen to be told at 95% with 80% power. Visitors
who never signed up are counted apart. The goal is read from the change log (see above).

A filter can ask who was given a variant — `{"experiment": "annual-first", "variant": "B", "op":
"in"}` (or `not_in`) — and a rule's split can follow a test, so the rule's numbers and the test's
are the same people:

```json
{"type": "split", "experiment": "annual-first", "variants": [{"key": "A", "next": null}, {"key": "B", "next": "offer"}]}
```

