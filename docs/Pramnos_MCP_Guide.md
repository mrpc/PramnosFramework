---
use_cases:
  - Letting an AI assistant explore this application's schema, routes and migrations
  - Making the framework's own documentation reachable from inside a project
  - Exposing an application-specific capability to an assistant as an MCP tool
  - Registering a project file as a resource an assistant can read
  - Working out why an assistant reimplemented something the framework already ships
  - Checking a change against the framework's rules before calling it finished
  - Letting an assistant read this installation's error logs instead of being handed a paste
---

# MCP server

The framework ships an **MCP** (Model Context Protocol) server, launched with
`php <cli> mcp:serve`. It speaks JSON-RPC 2.0 over stdio, which is what an assistant such
as Claude Code expects, and it exposes two kinds of thing:

- **tools** — callable capabilities: five introspect the application, two report what its logs
  say, and two need neither an application nor a database because they are about the framework
  itself — one reads its guides, the other checks this project against its rules;
- **resources** — read-only files the assistant can fetch by URI.

There is no HTTP surface and no port. The client starts the process, talks to it on stdin
and stdout, and stops it. Nothing is exposed to a network.

!!! note "This guide is the owner of the topic"
    MCP shipped in v1.2 and, until this page existed, was documented **only** in
    `1.2-new-features.md` — a frozen reference — and in a handful of dated changelog
    posts. That is precisely the failure mode the documentation rules describe: a
    capability that is present, documented, and not findable. The frozen page is still
    accurate about the classes it lists; this page is the one that is kept current.

## Enabling it

Add the feature key to `app/app.php`:

```php
'features' => ['auth', 'queue', 'mcp'],
```

`McpServiceProvider` then registers the server in the container as `mcp.server`, together
with the built-in tools and resources.

Scaffolded projects also get a `.mcp.json` in the project root, which is how the client
discovers the server. `pramnos init` writes it.

**It names the CLI this project has, which is not `./bin/pramnos`.** That path exists in
the framework's own repository; in a project the CLI is `<cliName>.php` at the root and
`bin/pramnos` lives under `vendor/`. A Docker project gets the container form, because
the database is only reachable from inside and `mcp:serve` is a database tool above all
— one running on the host answers every query with a connection error:

```json
{ "mcpServers": { "myapp": {
    "command": "docker-compose",
    "args": ["exec", "-T", "-u", "www-data", "app", "php", "myapp.php", "mcp:serve"]
} } }
```

`-T` is not optional: MCP speaks stdio over the pipe, and `docker-compose exec` without
it allocates a TTY that the protocol never gets a clean stream through. The scaffolded
`./<cliName>` wrapper is deliberately *not* reused here for that reason — it keeps its
TTY so an interactive `migrate` keeps its prompts.

A project without Docker gets the plain form:

```json
{ "mcpServers": { "myapp": { "command": "php", "args": ["myapp.php", "mcp:serve"] } } }
```

The server reports **`app/app.php`'s `name`** as its own, so a client listing several
projects can tell them apart. It reads the configuration file rather than a
database-stored setting: the name is right there, no connection is involved, and a project
whose settings failed to load would otherwise have shown the framework's generic default
for every one of its servers.

## The built-in tools

| Tool | Answers |
|---|---|
| `list-tables` | Which tables exist, with row counts |
| `query-schema` | The columns, types, keys and indexes of a table |
| `migration-status` | Which migrations have run and which are pending |
| `model-inspect` | A model's table, primary key, columns and relations |
| `route-list` | Every registered route, with method, URI, action and permissions |
| `log-analytics` | **What is going wrong here, and how much** — see below |
| `log-errors` | **What the log lines actually say** — see below |
| `framework-docs` | **How the framework works** — see below |
| `pramnos-check` | **Whether this project has broken a documented rule** — see below |

The first five are **application introspection**: they answer *what exists in this
project*. They need a database or an application to answer at all, and two of them are
skipped when no connection is configured. The two log tools answer *what has been happening*,
and need only a readable log directory.

### `framework-docs`

The odd one out, and the reason it exists is worth stating plainly.

`docs/` is not export-ignored, so every guide ships inside the composer package and sits
in `vendor/pramnos/framework/docs/` of each project. That is deliberate: the documentation
should be available to whoever is working there, an AI assistant included, and the
vendored docs always match the vendored code so there is no version to negotiate.

Nothing ever **offered** them. The other five tools answer questions about the
application; the registered resources are the application's own files. The only route to a
guide was for an assistant to guess that it should look inside `vendor/`. That is not a
hypothetical: it is the failure the documentation rules were written after — a feature
documented, present in the vendored corpus, not found, and built a second time beside the
working copy.

This tool takes no application and needs no database. It is the same answer in every
project.

**Calling it.**

```jsonc
// The index — every guide and the task each one covers. The cheap first call.
{}

// Find the page for a task
{"query": "issue an API token for a signed-in browser"}

// Read one in full
{"page": "Pramnos_Authentication_Guide"}

// The dated history of a change, rather than how the thing works now
{"corpus": "changelog", "query": "session exchange"}
```

**How pages are ranked.** Every guide carries `use_cases:` frontmatter, and each entry is
phrased as *the task the reader has in hand* rather than as a description of the page —
"Adding a column to an existing table", not "Schema builder reference". Those are the
closest thing in the corpus to the question an assistant arrives with, so a hit there
outweighs a hit in a heading, which outweighs a hit in the body. Body matches still count,
because a question about a specific method name will not appear in any use case; they are
simply worth less.

A page carrying **no** use cases is demoted, because it is not guidance. Two pages are in
that state on purpose — a frozen version reference and a release index — and they are also
the two longest files in the corpus. On body volume alone, the frozen one outranked every
live guide on the first query this tool was measured with, which would have sent a reader
to a page that stopped describing current state deliberately. The rule is structural
rather than a list of names, so a page cannot become quietly exempt by being added later.

**Two corpora, never merged.** `guides` describes current state; `changelog` is the dated
stream of what changed and when. There are far more posts than guides, and each post
repeats the vocabulary of the change it describes — merged, "how does this work" would be
answered by three fragments of a feature's history. That is exactly what the
guide/changelog split exists to prevent, and merged search would reintroduce it as a
ranking accident rather than as a decision. Ask `guides` first; reach for `changelog` when
the date or the reason for a change *is* the question.

### `pramnos-check`

The other half of `framework-docs`, and the half with evidence behind it. That tool lets an
assistant *find* a rule; this one tells it when a rule has been **broken** — and every rule it
checks is something that happened *after* the guide describing it was written.

```jsonc
{}                                          // the whole project
{"path": "src/Models"}                      // one subtree, or a single file
{"rules": ["raw-sql", "flash-query-params"]} // a subset
```

Seven rules. Six are defects; the seventh polices the escape hatch.

| Rule | Why it is invisible when you make it |
| --- | --- |
| `raw-sql` | The builder is the only layer that knows the dialect. Hand-written SQL has produced both a query against a table no migration creates and unqualified names that match nothing. |
| `unqualified-authserver` | On PostgreSQL `authserver` is a real schema and is not on the default `search_path`, so the unqualified name returns no rows and no error. On MySQL the qualified form becomes `authserver_x` — a different table again. |
| `flash-query-params` | `?message=` is shown again on every reload, stays in history, and is user-controlled text arriving in a page that displays it. |
| `view-reserved-props` | `sections`, `path`, `model` and `_layout` are used by the View engine, so the value is overwritten and the variable is simply absent in the template. No error, no log line. |
| `baseline-migration-timestamp` | Installations predating the migration system set `migration_cutoff = 2020_01_02_000000`, so a new `2020_01_01_*` migration is silently never run there. |
| `duplicate-debug-panel` | A second reader of the `_debug` payload is usually a panel written because the shipped one was not known to exist. That has happened, and it is why the documentation rules were rewritten. |
| `unexplained-suppression` | A suppression with no reason hides a finding and tells the next reader nothing. |

The authserver table list is **read from the framework's own migrations** at runtime, so it
cannot drift out of step with the schema the framework creates.

#### Suppressing a finding

```php
// pramnos-check: ignore raw-sql — recursive CTE the builder cannot express
$rows = $db->query('WITH RECURSIVE …');
```

Same line or the line above. **The reason is required.** A bare `ignore raw-sql` suppresses
nothing and is reported as its own finding — because the value of rule 12's "leave a one-line
comment saying why" is that the next reader can tell a considered exception from an oversight.

#### On precision

A check that cries wolf gets muted, and then the real finding it makes next month is muted
with it. So the rules match **constructions, not names**, and each one carries a negative test.

That is not a stylistic preference. The first run of this tool against the framework's own
`src/` reported 29 raw-SQL findings and **sixteen were noise**: `SELECT version()`,
`SELECT NOW()`, `select @@global.long_query_time`, TimescaleDB catalogs, and one example
inside a docblock — because the first version did not strip comments. Rule 12 exempts
introspection and driver-specific features in its own text; a statement with no table cannot
be expressed by a builder at all; a fixture in test code is clearer as a literal.

What the rules deliberately do not report:

- raw SQL in `database/migrations/**`, in `tests/**`, or in the framework's `Testing/` helpers;
- statements with no table to address, or against `information_schema`, `pg_*`,
  `timescaledb_information`, or `SHOW …`;
- *reading* `?message=` — an application does not control every link pointing at it;
- `authserver.user_activity_log`, which contains the unqualified form as a substring;
- `$config->path = …` or `$this->model = …`, which are not view variables;
- `_debug` in a project with no shipped `lib/debug.js`, which has nothing to duplicate.

#### The framework does not pass its own check

Run against `src/`, it reports **9 raw-SQL** findings and **67 flash-query-parameter** ones.
Those are real, and they are the argument for the tool existing: the rules were written down,
and the framework itself drifted from them in seventy-six places. They are recorded rather
than quietly fixed, because rewriting seventy-six call sites is a decision about priorities
and not a tidy-up.

### `log-analytics` and `log-errors`

These two exist because of an asymmetry. «What is going wrong on this installation» is the
first question anybody asks, and the answer already existed — the `/admin/logs` dashboard
computes a trend, a breakdown by level, the most frequent errors with their counts, and a
per-file error rate. It was reachable only by a human with an administrator's session.

So an assistant asked to look at a problem got handed a pasted log file, which is both more
work and less information: a paste has no counts, no rates, and no idea what it left out.

The aggregation now lives in `Pramnos\Logs\LogAnalytics`, and the dashboard is one of its
callers. One implementation on purpose — two copies of the same aggregation drift, and the day
they disagree the screen and the tool each look right on their own.

**`log-analytics`** returns the summary. Counts, not lines:

```jsonc
{}                                     // the last 24h, every readable file
{"timespan": "1h"}                     // 1h, 6h, 24h, 7d, 30d
{"timespan": "7d", "files": ["error.log"]}
```

```jsonc
{
  "timespan": "24h", "from": 1756300000, "to": 1756386400, "group": "hour",
  "trends":    {"08:00": 4, "09:00": 0, "…": 0},
  "levels":    {"info": 21, "error": 3},
  "topErrors": [{"message": "column \"userid\" does not exist", "count": 2,
                 "file": "error.log", "last_seen": "…"}],
  "files":     {"error.log": {"last_entry": "…", "error_rate": 12.5, "total_entries": 24}},
  "truncated": false
}
```

Three things in that answer are deliberate:

- **`topErrors` is keyed by the message**, so the same failure in three files is one row
  carrying the total — which is the number somebody acts on, rather than three that each look
  survivable.
- **`truncated`** says a file was too large to scan in full and only its tail was read. Without
  it, a summary of the last 25 MB of a multi-gigabyte log reads as a complete picture.
- **An empty `files`** comes back with a `note`, because "no log files were readable" and
  "nothing has gone wrong" are otherwise the same answer, and only one means stop looking.

**`log-errors`** is the next question — what the lines say:

```jsonc
{}                                                  // newest error-level entries, last 24h
{"levels": ["warning"], "query": "timeout"}
{"files": ["error.log"], "timespan": "1h", "limit": 20}
```

Its defaults are the levels somebody means by "the error log" — `emergency`, `alert`,
`critical`, `error`. `info` and `debug` are available by asking and are not what anybody wants
first. The response is bounded at 200 entries and carries `complete`, which is false when the
limit was reached: an answer that stopped early otherwise reads as «that is all there is»,
which is the wrong conclusion for somebody deciding whether a problem is over.

Files with no structured entries — `GitDeploy`, `GitWebhookDebug`, which are shell output — are
skipped by both. Counting levels in them produces a number that looks like a measurement.

## The built-in resources

Three, all of them the application's own files, registered only when present:

| URI | File |
|---|---|
| `file://CLAUDE.md` | `ROOT/CLAUDE.md` |
| `file://README.md` | `ROOT/README.md` |
| `file://app/app.php` | `ROOT/app/app.php` |

## Adding your own

### A tool

Implement `Pramnos\Mcp\McpToolInterface` — four methods, all required:

```php
use Pramnos\Mcp\McpToolInterface;

class StationHealthTool implements McpToolInterface
{
    /** Unique within one server instance. */
    public function name(): string
    {
        return 'station-health';
    }

    /** One sentence; it is all a model reads when deciding whether to call this. */
    public function description(): string
    {
        return 'Report the last successful stream check for a station.';
    }

    /** JSON Schema as a PHP array. */
    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'station_id' => ['type' => 'integer', 'description' => 'The station'],
            ],
            'required'   => ['station_id'],
        ];
    }

    /** Any JSON-serialisable value. */
    public function execute(array $input): mixed
    {
        return ['checked_at' => /* … */];
    }
}
```

Register it in a service provider's `boot()`:

```php
$this->app->getContainer()->get('mcp.server')->addTool(new StationHealthTool());
```

!!! warning "The application behind an MCP tool is the console kernel"
    `mcp:serve` is a console command, so the application your tool receives never handled
    an HTTP request. Anything built during HTTP bootstrap is absent — the router being the
    one that catches people out. `RouteListTool` therefore builds a router itself with
    `Router::loadFromDirectory()`, because attribute routes are discoverable without a
    request; that is the point of them being attributes.

    Before that fix it answered `{"error": "No router available"}` on the only path that
    can reach it. Not a defensive fallback — the whole method. A tool that returns an
    error on every real call is indistinguishable from a working one until somebody reads
    the output, so if yours needs something HTTP-shaped, build it rather than reporting
    its absence.

### A resource

```php
use Pramnos\Mcp\McpResource;

$server->addResource(new McpResource(
    uri: 'file://docs/architecture.md',
    name: 'Architecture notes',
    filePath: ROOT . '/docs/architecture.md',
    mimeType: 'text/markdown',
));
```

`read()` returns `null` for a missing or unreadable file rather than throwing, so a
resource that disappears degrades to absent instead of breaking the session.

## Protocol methods handled

| Method | Result |
|---|---|
| `initialize` | Server name, version, capabilities |
| `tools/list` | Every registered tool with its schema |
| `tools/call` | Runs one and returns its value |
| `resources/list` | Every registered resource |
| `resources/read` | The content of one |
| `ping` | Empty result |

Anything else returns JSON-RPC error `-32601`.

## Related

- [Console Commands](Pramnos_Console_Guide.md) — `mcp:serve`, `.mcp.json`, and what
  `init` and `project:resync` write.
- [Debugging](Pramnos_Debugging_Guide.md) — the toolbar and the API playground, which
  answer *what did this request do* rather than *what does this project contain*.
