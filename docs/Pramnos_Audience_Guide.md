---
use_cases:
  - Declaring what an administrator can filter people by — a column, a side table, a computed value
  - Choosing an audience for a send or a rule from conditions over people's attributes
  - Counting, sampling or paging through the people a filter matches
  - Explaining why one person is or is not in an audience
  - Leaving staff or deleted accounts out of every audience
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
