---
use_cases:
  - Letting an AI assistant explore this application's schema, routes and migrations
  - Making the framework's own documentation reachable from inside a project
  - Exposing an application-specific capability to an assistant as an MCP tool
  - Registering a project file as a resource an assistant can read
  - Working out why an assistant reimplemented something the framework already ships
  - Checking a change against the framework's rules before calling it finished
  - Letting an assistant read this installation's error logs instead of being handed a paste
  - Working out what an MCP tool actually returns, or why a client says it is broken
  - Finding out who calls a method, or where a class is defined, without grepping
---

# MCP server

The framework ships an **MCP** (Model Context Protocol) server, launched with
`php <cli> mcp:serve`. It speaks JSON-RPC 2.0 over stdio, which is what an assistant such
as Claude Code expects, and it exposes two kinds of thing:

- **tools** — callable capabilities: five introspect the application, two report what its logs
  say, one answers where a symbol is defined and who calls it, and two are about the framework
  itself — one reads its guides, the other checks this project against its rules. Only the
  first five need an application or a database;
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
| `find-symbol` | **Where a symbol is defined and who calls it** — see below |
| `log-analytics` | **What is going wrong here, and how much** — see below |
| `log-errors` | **What the log lines actually say** — see below |
| `framework-docs` | **How the framework works** — see below |
| `pramnos-check` | **Whether this project has broken a documented rule** — see below |

`find-symbol` reads source files and needs neither, so it works when nothing boots — which is
when it is most likely to be wanted.

The first five are **application introspection**: they answer *what exists in this
project*. They need a database or an application to answer at all, and two of them are
skipped when no connection is configured. The two log tools answer *what has been happening*,
and need only a readable log directory — so they register even when there is no application,
which is exactly when somebody is asking why.

All nine come from one list, `McpServiceProvider::registerDefaults()`. `mcp:serve` calls it
when no container has a server, which is the normal case; a second copy of that list is how
two tools once ended up registered and unreachable at the same time.

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

### `find-symbol`

The question `grep` cannot answer. Grep finds **strings**; this finds **calls**, and names the
function each one sits inside.

```jsonc
{"name": "hasTable"}                          // definitions and callers
{"name": "SchemaBuilder::hasTable"}           // narrow the definitions
{"name": "LogAnalytics", "scope": "app"}      // only the consuming project
{"name": "parseTimestamp", "kind": "callers"}
```

```jsonc
{
  "symbol": "hasTable",
  "files": {"searched": 1550, "containing": 8},
  "definitions": [
    {"source": "framework", "file": "…/SchemaBuilder.php", "kind": "method",
     "name": "Pramnos\Database\SchemaBuilder::hasTable", "line": 308}
  ],
  "callers": [
    {"source": "framework", "file": "…/Permissions.php", "line": 413,
     "in": "Pramnos\Auth\Permissions::tableExists", "type": "method",
     "code": "return $database->schema()->hasTable($table);"}
  ],
  "counts": {"definitions": 1, "callers": 7},
  "complete": true
}
```

**`in` is the field that matters.** `Permissions::tableExists` explains a line number;
`src/Pramnos/Auth/Permissions.php:413` does not. The tool exists because of an afternoon spent
tracing which code ran a particular query: eight greps, and then a patch to
`QueryBuilder::exists()` that dumped a backtrace — in a framework whose entire source was on
disk. Grep could not find it, because the calling line contained neither word being searched
for: it read `$database->queryBuilder()->table($table)`, and the name was in a constant three
lines up.

Token-based, so a mention in a comment, a doc-block or a string literal is not reported — which
is most of what grep returns when the name is an ordinary English word like `logs` or `table`.
Measured on one case: grep returned 14 hits for `parseTimestamp`; four were calls, ten were in
tests, and one was a sentence in a comment.

- **`scope`** is `all` (default), `app` or `framework`. The framework's own **tests** are in
  scope: "a test asserts this" is part of the answer to "who calls this", and it is what tells
  you whether changing it is safe.
- **`type`** on each caller distinguishes `method`, `static`, `call`, `new` and `class-ref` —
  the last being `Foo::` with no parentheses after the name, which is how a class reached
  statically is used, and how most of this framework's classes are used.
- **A qualified name matches on its last segment**, so `\Pramnos\Logs\LogAnalytics::summary()`
  is found by searching for `LogAnalytics`.
- **Naming a class narrows the definitions, not the callers**, and the answer says so:
  establishing that a given `$thing->hasTable()` is a `SchemaBuilder` would need type
  inference.
- **What it cannot see:** a dynamic call (`$method()`, `call_user_func`), a type hint, a `use`
  statement, `instanceof`. An empty answer says this, because "nothing calls this" is how a
  method gets deleted.

No cache, deliberately: tokenising all 557 files of the framework measures at 60ms, and a cache
is a second source of truth that can be stale about the one thing this tool exists to be right
about.

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

## Debugging it

`mcp:serve` speaks JSON-RPC on stdio and blocks on STDIN. Run by hand it looks like a hang;
run by a client, the client owns both pipes. Neither state lets you see what a tool returned,
and hand-writing an `initialize` frame to find out has the worst property a debugging
procedure can have: a mistake in the frame is indistinguishable from a broken tool.

Three things, for three different questions.

### `mcp:call` — what does this tool return?

```bash
php <cli> mcp:call                                   # every tool, with the arguments it takes
php <cli> mcp:call log-analytics --arg timespan=6h
php <cli> mcp:call log-errors --json '{"limit": 5, "query": "timeout"}'
php <cli> mcp:call route-list --raw                  # the JSON-RPC envelope, unwrapped
```

With no tool named it lists them, each with its input schema — including the enums, which
are the difference between one guess and five:

```
log-analytics
  Summarise this installation's logs: entry trend, counts per level, …
  · timespan: string (1h|6h|24h|7d|30d)
  · files: array
```

Four things it does deliberately:

- **It goes through `McpServer::dispatch()`**, the same method the stdio loop calls, rather
  than reaching for the tool object. A tool that works when called directly and fails through
  the protocol is a real bug and this is where it shows.
- **`--arg amount=2` arrives as the number 2.** A shell has only strings, and a schema that
  wants an integer would otherwise reject the obvious spelling. `true`, `false`, `null`,
  numbers and `a,b,c` lists are converted; use `--json` when a literal string is meant.
- **A tool that threw exits non-zero.** An exception inside a tool comes back as a
  *successful* JSON-RPC response whose content is the exception message, so without this it
  would print like an answer.
- **`--raw`** prints the envelope, for when the wrapper rather than the tool is the suspect.

### The MCP tab in the DevPanel — the same thing, interactively

`/devpanel/mcp` lists every registered tool, renders each one's schema **as a form**, and
calls it on the page. It is the answer to "what does this return" when you are already in the
panel, and it adds what a terminal cannot do conveniently: the arguments are discovered
instead of looked up.

- **An enum becomes a `<select>`.** That is the whole reason to render the schema rather than
  a textarea.
- **Every field can be left out.** An omitted argument and an empty string are different
  things — a tool with a default gets to keep it — so each control carries an explicit
  `— omit —`, and a boolean is a tri-state select rather than a checkbox, because an
  unchecked box cannot say "leave it out".
- **The arguments actually sent are printed above the answer.** `{"limit": "5"}` and
  `{"limit": 5}` are different calls, and a schema that rejected the first is otherwise a
  mystery.
- **A tool that threw is shown as a failure.** The protocol reports it as a *successful*
  response whose content is the exception message.
- **`show the JSON-RPC envelope`** prints the request and response as they go over the wire,
  for when the wrapper rather than the tool is the suspect.

It builds its own server when the container has none, so the tab works with the `mcp` feature
switched off — and says so, because the thing the feature adds is the container binding that
an application's *own* tools get registered into. The POST that runs a tool carries a CSRF
token: the panel's other endpoints read, this one executes whatever a project registered.

### `mcp:serve --log` — what is the client actually sending?

The question `mcp:call` cannot answer. When Claude Code or an IDE starts the server, that
process is not yours: you cannot see its STDOUT, and its STDERR goes wherever the client puts
it. `--log` records every message in both directions.

```bash
php <cli> mcp:serve --log              # into the log directory, as mcp.log
php <cli> mcp:serve --log=/tmp/mcp.log
```

In `.mcp.json`, add it to `args`:

```jsonc
{ "mcpServers": { "myapp": { "command": "docker-compose", "args":
    ["exec", "-T", "-u", "www-data", "app", "php", "myapp.php", "mcp:serve", "--log"] } } }
```

Each line is written in **the framework's own structured-log format**, so the log viewer,
`LogAnalytics` and the `log-errors` tool all read the file without knowing anything about MCP:

```jsonc
{"timestamp":"28/08/2026 15:04:11","level":"info","message":"→ tools/call log-analytics",
 "data":{ … the whole request … }}
{"timestamp":"28/08/2026 15:04:11","level":"info","message":"← result",
 "data":{ … the whole response … },"duration_ms":41.2}
```

Which means the useful query is one you already have:

```bash
php <cli> mcp:call log-errors --json '{"files": ["mcp.log"]}'
```

- **A failed call is logged at `error`** — including a tool that *threw*, which the protocol
  reports as a successful response. Without that, the most interesting event in a session is
  filed as routine and unfindable among a thousand good calls.
- **A malformed line is logged with the input that caused it.** "Parse error" alone is the
  least actionable message a protocol can produce.
- **It is off unless asked for, and it says the path on STDERR when it is on.** The payloads
  are whatever the tools returned, which for `query-schema` is table structure — leave it
  switched off when you are done.
- An unwritable path degrades to no logging rather than taking the server down. A debugging
  aid that kills the process it is instrumenting is worse than none.

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
