---
use_cases:
  - Deploying a branch automatically when it is pushed
  - Working out why a push did not reach production
  - Securing an endpoint that runs shell commands on the server
  - Choosing what a deploy webhook should answer and when
---

# Deploy webhooks

`Pramnos\Webhook\WebhookHandler` receives a push from GitHub or Bitbucket, checks
the signature, works out which branch was pushed, and runs the commands you mapped
to it.

```php
// www/webhook.php
$handler = new \Pramnos\Webhook\WebhookHandler(
    secret:     $_ENV['WEBHOOK_SECRET'],
    repoDir:    ROOT,
    logChannel: 'webhook',
);

$handler->onBranch('main', [
    'git fetch --all',
    'git reset --hard origin/main',
    'composer install --no-dev --optimize-autoloader',
    'php pramnos migrate',
    'php pramnos cache:clear',
]);

$handler->handle();
```

`handle()` never returns. Point the provider's webhook at that address, choose
`application/json`, and set the secret on both sides.

## It answers before it works

**`handle()` sends `202 Accepted` and then runs the commands.** This is the default
and it is the whole reason this page exists.

GitHub allows a webhook **ten seconds in total, and does not retry.** A deploy of
fetch, reset, `composer install`, `migrate` and `cache:clear` takes four to six of
them — nothing is wrong with the commands, there is simply no margin, and the
margin is against somebody else's network. A single slow fetch to github.com
crosses the limit.

What happens then is the expensive part:

- the provider records `context deadline exceeded` and gives up after one attempt,
- the aborted request does not finish the work either,
- so the push is **silently not deployed** — production keeps serving the previous
  commit while `git log` on your machine says it shipped,
- and every health check stays green, because the application is perfectly healthy.
  The only record is a red row in a delivery list nobody opens.

Answering first makes the ten seconds irrelevant instead of a budget.
`ignore_user_abort()` and `set_time_limit(0)` are set before the work starts, so a
caller that has already hung up does not take the deploy with it.

### What you give up, and where the result went

The response cannot report an outcome that has not happened yet. It is `202`
whether the deploy goes on to succeed or fail, so the provider's delivery list is
green either way.

**`webhook.log` is where the result lives** — every command, its exit code and its
output, on both paths. If you watch for red deliveries to know a deploy broke,
watch the log instead.

For a webhook whose commands are fast and whose caller genuinely reads the answer:

```php
$handler->respondFirst(false);   // 200 with the outcome, 500 when a command fails
```

### `fastcgi_finish_request()`, and when it is not there

Under PHP-FPM — which is how a containerised application is served — the
connection is genuinely closed and the process carries on alone. Under mod_php or
the built-in server there is no such call: the response is flushed with a
`Content-Length` so the client knows it has the whole body, but the socket stays
open until the script ends. The body is still on the wire before `composer
install` starts, which is most of the benefit; the provider's clock is only partly
appeased.

## Security

The `secret` is required — an empty one raises `InvalidArgumentException` from the
constructor rather than accepting unsigned payloads quietly. Signatures are
compared with `hash_equals()` against `X-Hub-Signature-256` (preferred) or
`X-Hub-Signature` (SHA-1, legacy).

This endpoint runs shell commands as the web user. Two things follow: keep the
command list to the deploy, and keep the secret out of the repository.

## Events and branches

| Event | Header value | What happens |
|---|---|---|
| push | `push` | the pushed branch's commands run |
| release (published) | `release` | the `main` mapping runs, if there is one |
| workflow_run (completed, success) | `workflow_run` | the head branch's commands run |
| anything else | — | `204`, silently ignored |

A branch with no mapping is also a silent `204`. That is deliberate: a repository
with twenty branches should not log an error twenty times a day.

## Commands

They run in order in `repoDir`, **stopping at the first non-zero exit**, with
stdout and stderr captured into the log. Fail-fast is what you want here: there is
no sense running `migrate` against a checkout that `git reset` did not update.

## Reading the log

```
Webhook deploy: branch=main commands=[…] elapsed=4331ms
Webhook deploy failed on branch=main: [{"command":"php pramnos migrate","exit_code":1,…}]
```

Both lines go to the `webhook` channel, visible in the log viewer. Set
`logChannel: ''` to turn logging off — and then accept that a deploy which ran and
what each command exited with is no longer recorded anywhere.

## Configuring through the service provider

```php
// app/config/app.php
'features' => ['webhook'],
'webhook'  => [
    'secret'      => $_ENV['WEBHOOK_SECRET'] ?? '',
    'repo_dir'    => ROOT,
    'log_channel' => 'webhook',
    'timeout'     => 120,
],
```

The container then hands you a configured handler under the key `webhook`, and you
map branches onto it as above.

## Related

- [Workers & Daemons](Pramnos_Workers_And_Daemons_Guide.md) — the schedule and the
  queue, which is where a long deploy step belongs if it grows one
- [One server to several](Pramnos_Multi_Server_Guide.md) — deploy consistency
  between nodes, which this does not solve
- [Logging](Pramnos_Logging_Guide.md) — channels and the log viewer
