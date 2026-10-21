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
---

# Audience Guide

An **audience** is the people who match a **filter**: conditions over their **attributes**,
grouped with "all" and "any". Sends, segments and rules all choose their people this way, so an
application declares its attributes once and every screen can use them.

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

A segment is a filter with a name, kept worked out: `segment_members` holds who is in it, one row
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
Groups::of($userid);                                        // [['id' => 4, 'name' => 'Press'], …]
```

Only real changes count: a person already in is not added again, and one not in is not removed.
Each real change flushes the cache `User::getGroups()` reads, tells the applications the person
uses when the group holds a role, and dispatches `group.joined` (`GroupJoined`) or `group.left`
(`GroupLeft`) with `groupId`, `personId`, `by` and `source` (`admin`, `bulk`, `rule`, or the
application's own word). The Groups screen adds and removes members through it too.

An application with its own `addGroup()` / `removeGroup()` on its user class can keep them and
have them call these, so a membership changed anywhere is announced the same way.

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
  `'audience' => ['person_url' => '/admin/People/{id}']` in app.php.
- **Options:** `sample` (how many people, default 5), `breakdown` (`filter()` only, default on),
  `live` (the panel, default on), `api`.
- `pf-audience.js` ships with the framework; a project made before it reaches its web root with
  `project:resync --js --all`. It fires `pf:audience-change` with the state on every change.

## The screens

Two administration screens, under People, for every administrator:

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

