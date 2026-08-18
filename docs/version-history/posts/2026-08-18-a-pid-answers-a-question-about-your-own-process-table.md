---
date: 2026-08-18
categories: [Changelog]
---

# A pid answers a question about your own process table

Reported from an application whose admin panel showed all four background daemons as **down**
while all four were running, and whose `/api/realtime-config` therefore advertised the SSE
fallback with a healthy WebSocket worker listening and accepting connections.

```
panel:      stats down · maintenance down · tracker down · realtime down
the host:   all four running, locks touched seconds ago
```

<!-- more -->

## What was wrong

`DaemonOrchestrator::status()` decided each daemon's `running` flag like this:

```php
'running' => $daemonPid > 0 && $this->isProcessRunning($daemonPid),
```

Which is correct, and answers a question nobody asked. `status()` is read by *whatever asks* —
a web request, an admin panel, a health endpoint — and what asks is frequently **not** the
process that started the daemons. In the reported case the supervisor ran in one container and
the panel was served from another, where pid 20 is either nothing or an unrelated process.

The same fault is reachable on a single host: any reader that is not the supervisor is looking
at a pid it did not record, and a recycled pid is a false *yes* exactly as a foreign namespace
is a false *no*.

## The fix

A daemon is judged by its **heartbeat** when its pid cannot answer:

```php
protected function daemonLooksAlive(int $pid, string $lockFile): bool
{
    if ($pid > 0 && $this->isProcessRunning($pid)) {
        return true;
    }

    if ($lockFile === '' || !is_file($lockFile) || is_file($lockFile . '.stop')) {
        return false;
    }

    $age = time() - (int) @filemtime($lockFile);

    return $age >= 0 && $age <= static::HEARTBEAT_STALE_SECONDS;
}
```

Every managed worker touches its lock file on each heartbeat, and the lock lives where both
sides can read it, so *"touched within the stale window"* is a fact about the **daemon** rather
than about the reader. A `.stop` sentinel beside it still means *asked to go*, whatever the
timestamp says.

The pid is still consulted and still answers first, so a single-host install with a daemon that
declares no lock file behaves exactly as before.

## Why this is the second entry today

The other one — *a zombie is not a running daemon* — is the same sentence from the other side.
`posix_kill($pid, 0)` says yes to a corpse; a corpse touches nothing. Between them the rule is:
**a process table tells you about processes, and a heartbeat tells you about work.** A supervisor
wants the second.

## Tests

`daemonLooksAlive()` is asserted against a real lock file across three states — fresh with a
foreign pid, aged past the stale window, and with a `.stop` sentinel beside it — and against a
live pid with no lock at all, which is the path that must not change.
