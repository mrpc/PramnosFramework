---
date: 2026-08-20
categories: [Changelog]
---

# A schedule nobody was running

The framework declares periodic work of its own — `spool:drain` every minute,
`timescale:drain` hourly, `queue:cleanup` daily — and ships `work`, a long-running process
that runs it where there is no cron. `DaemonOrchestrator` supervises long-running processes.
Nothing connected the two.

<!-- more -->

## Added

`DaemonOrchestrator` now supervises `work` alongside the application's own daemons. Nothing
to declare, nothing to remember:

```
[started] stats pid=118
[started] realtime pid=30
[started] schedule pid=141      ← not in buildDesiredProcesses()
```

The gap was invisible from every direction. A project that extends `DaemonOrchestrator` lists
*its* daemons, which is what the abstract method asks for; the framework's own periodic work
is not something an application should have to know exists — the whole point of
`FrameworkSchedule` is that it does not. So an installation with an orchestrator, no crontab,
and three healthy application daemons ran none of it.

Measured on the installation that surfaced this: twenty requests sitting unwritten in
`var/spool/`, a `tokenactions` table that had never had a row, and a Performance panel
reporting "no data for this period" — a symptom three layers from its cause, with nothing in
between saying so.

`includeScheduler(): false` opts out for an installation whose crontab already runs
`schedule:run`. Running both is safe — every framework task takes an overlap lock — but
pointless. An application that already declares `work` itself keeps its own entry, recognised
by what the entry runs rather than by the id it was given; a daemon merely *named*
`workflow:run` or `network:sync` is not mistaken for it, which would have switched the
schedule back off for exactly the projects that have one.

## Documentation

`Pramnos_Workers_And_Daemons_Guide.md` — §1c gains the third way to run the schedule
(nothing to do), and §3 documents what the orchestrator adds, both overrides, and why.
