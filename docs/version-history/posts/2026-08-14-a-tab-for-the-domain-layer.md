---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - debug
  - services
  - scaffolding
---

# A parent class for services, and a tab that admits they exist

In a Services + API + SPA project the domain logic lives in
`src/Services/*Service.php` — plain classes, deliberately, with nothing for the
framework to hook. The debug toolbar's **Models** tab was therefore empty for a
request that had done all of its work in services, and an empty tab named after
the other style says nothing at all: not "nothing happened", not "your code does
not appear here". Just nothing.

<!-- more -->

## `Pramnos\Application\Service`

Services now have a base class, the way models have one. Extending it is the
whole contract:

```php
namespace App\Services;

use Pramnos\Application\Service;

class BillingService extends Service
{
    public function overdue(int $days = 30): array
    {
        return $this->measure('overdue', fn(): array => $this->queryBuilder('invoices')
            ->where('due_at', '<', gmdate('Y-m-d', time() - $days * 86400))
            ->where('paid', 0)
            ->getAll());
    }
}
```

| Member | What it does |
| --- | --- |
| `__construct(?Database $database = null)` | The connection to use, or none. |
| `$this->database()` | Resolved on **first use**, not at construction. |
| `$this->queryBuilder(?string $table)` | A builder on that connection. |
| `$this->measure(string $name, callable $work)` | Runs it, returns its value untouched, records the duration. |

Laziness is the part worth stating: a service constructed in a unit test that
only exercises pure logic never opens a database connection to do it. And
`measure()` re-throws whatever the callback threw *after* recording the attempt —
the call that failed is the one worth seeing in the toolbar, so swallowing it
would turn a debugging aid into a bug-hiding one.

## The Domain tab

`ServicesCollector` is fed by that base class, so recording is automatic rather
than opt-in: constructing a service records it, and `measure()` adds the cost of
one operation. The tab is now **Domain**, with a Models section and a Services
section — the label follows the content instead of the content following the
label.

The two empty states are distinguished, because their fixes are different: *no
services recorded* means the class does not extend the base, while *no call was
timed* means it does and no method has called `measure()` yet. The badge counts
both sections, so a services-oriented request no longer shows `0` above a panel
listing six calls.

The payload keeps its **`models` key**, with `services` beside it as its own
collector — anything already reading the payload is unaffected. One renderer
still draws both deliveries, so the server-rendered toolbar and the SPA panel
gained this at the same moment.

## Scaffolding

`create:service` and the SPA scaffold's `StatusService` now generate services
that extend the base — and the status service uses `measure()`, so a new project's
very first request shows a timed service call in the panel.

## Not yet

A container-resolved timing proxy would need neither `measure()` nor a base
class, and stays a follow-up: it should wait until services are actually resolved
through the container.

## Documentation

- [Application Styles Guide](../../Pramnos_Application_Styles_Guide.md) — the
  `Service` base class, its four members, and what inheriting buys.
- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — the Domain tab, and why
  it is one tab rather than two.
