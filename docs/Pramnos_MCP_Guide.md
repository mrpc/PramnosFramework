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
  - Offering a capability to an assistant outside this machine, authenticated with OAuth
  - Finding out who calls a method, or where a class is defined, without grepping
  - Finding out whether a generator already exists for the class you are about to write
  - Reading the design tokens, or checking whether the compiled stylesheet is stale
  - Reading the documented API surface, or checking whether the OpenAPI document is stale
  - Finding the test that covers a class, before writing a second one somewhere else
  - Checking a change against the framework's rules, or its coverage, without the noise of the
    whole project
  - Asking a live database a question without opening a shell on the server
  - Checking that a public MCP endpoint, its token and its scopes are what you think they are
  - Diagnosing a production installation — logs, migrations, schema drift — without SSH
  - Deciding which framework tools are safe to expose over HTTP, and behind which scope
---

# MCP server

The framework ships an **MCP** (Model Context Protocol) server, launched with
`php <cli> mcp:serve`. It speaks JSON-RPC 2.0 over stdio, which is what an assistant such
as Claude Code expects, and it exposes two kinds of thing:

- **tools** — callable capabilities: five introspect the application, two report what its logs
  say, one writes the day's changelog entry, and seven answer questions about the code and the
  project itself — where a symbol is
  defined and who calls it, which tests cover it, what the CLI can do, what the design tokens
  are, what the API documents, and what the framework's own guides and rules say. Only the
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

## What a tool must tell a client

Three methods, and together they are the whole of discovery: a client reads them once and builds every
call from them.

| Method | What it decides |
|---|---|
| `name()` | the wire identifier — a client sends `tools/call` with it and nothing else |
| `description()` | **whether** the tool is used, because a client chooses between fifteen of them by reading these and nothing else |
| `inputSchema()` | how the call is built and validated |

If you add a tool, these are the parts to get right, and the reason is that getting them wrong is
**silent**. A malformed schema is not a wrong answer: the tool disappears from the client's list, or the
client sends a shape the tool does not read, and nothing on the server logs anything because the request
never arrives. The same for a description — an empty one is a tool nobody calls, and a one-word one is a
tool called for the wrong thing, which is worse because the answer looks like an answer.

`ToolDiscoveryContractTest` holds every registered tool to it, so a tool added tomorrow is covered the
day it is registered rather than the day somebody writes a test for it. What it requires:

- a name matching `^[a-z][a-z0-9-]*$`, unique across the server — the server keys tools by name, so a
  duplicate does not collide loudly, it replaces;
- a description of more than thirty characters;
- a schema that is `{"type": "object", "properties": {…}}`, where every property has a `type` (what the
  client validates against) and a `description` (what stops the value being guessed);
- a `required` list naming only properties the schema defines — requiring one it does not define is
  rejected outright by a strict client and sent empty by a lenient one;
- and a schema that survives `json_encode`/`json_decode` unchanged, since that is the round trip it
  actually makes.

## The built-in tools

| Tool | Answers |
|---|---|
| `list-tables` | Which tables exist, with row counts |
| `query-schema` | The columns, types, keys and indexes of a table |
| `migration-status` | Which migrations have run and which are pending |
| `model-inspect` | A model's table, primary key, columns and relations |
| `route-list` | Every registered route, with method, URI, action and permissions |
| `find-symbol` | **Where a symbol is defined and who calls it** — see below |
| `console-commands` | **Every CLI command, including the twenty code generators** — see below |
| `theme-info` | **The design tokens, and whether the CSS was built from them** — see below |
| `api-docs` | **What the API promises, and whether the document is current** — see below |
| `find-tests` | **Which tests cover a class, and how to run them** — see below |
| `coverage` | **Which lines of your change no test touches** — see below |
| `changelog-add` | **Adds a section to today's changelog post** — the only tool that writes |
| `log-analytics` | **What is going wrong here, and how much** — see below |
| `log-errors` | **What the log lines actually say** — see below |
| `framework-docs` | **How the framework works** — see below |
| `pramnos-check` | **Whether this project has broken a documented rule** — see below |
| `status` | **Is anything broken, and what is waiting** — see below |
| `schema-drift` | **The schema on disk against the schema in the database** — see below |
| `request-debug` | **What a request that died actually did** — see below |
| `db-inspect` | **One read-only SELECT against the live database** — see below |

`find-symbol` reads source files and needs neither, so it works when nothing boots — which is
when it is most likely to be wanted.

**Start with `status`.** It answers the four questions a session opens with — is the database
up, are there migrations to run, is anything stuck, when did something last go wrong — and the
alternative is finding out from a failure ten minutes in.

The first five are **application introspection**: they answer *what exists in this
project*. They need a database or an application to answer at all, and two of them are
skipped when no connection is configured. The two log tools answer *what has been happening*,
and need only a readable log directory — so they register even when there is no application,
which is exactly when somebody is asking why.

They all come from one list, `McpServiceProvider::registerDefaults()`. `mcp:serve` calls it
when no container has a server, which is the normal case; a second copy of that list is how
two tools once ended up registered and unreachable at the same time.

### `db-inspect`

One read-only `SELECT`, against whatever database this installation is configured with —
including a production one, which is the case it was written for.

```
db-inspect { "sql": "SELECT count(*) FROM usertokens WHERE token_lookup IS NULL" }
```

It replaces SSH for one narrow purpose, and the reason that is an improvement is entirely
in what it refuses:

- a statement that writes is refused **before it reaches the database**, including a
  data-modifying CTE that is technically a `SELECT`;
- rows from a table declared as holding personal data are not returned — those answer with
  a count and the column names;
- columns that look personal (`email`, `phone`, `token`, `password`, …) come back as
  `[withheld]` in every table, declared or not;
- at most 200 rows, whatever the statement asks for;
- every call is logged to `mcpqueries` with its statement, before it runs.

It is on the **stdio** server by default, which already requires a shell on this machine.

#### Reaching it from off the box

Which is the whole point — SSH is what this is meant to replace. Four steps, none of them
a new subsystem:

**1. The endpoint has to exist.** `POST /mcp` is scaffolded by `init` when the `authserver`
feature is on. If the route is not there, that is why.

**2. Offer the tool.** In a `ServiceProvider`, or `app/providers.php`:

```php
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\Tools\DbInspectTool;

PublicRegistry::add(new DbInspectTool(\Pramnos\Framework\Factory::getDatabase()));
```

One line, and a deliberate one. The framework will not put a tool that reads data onto a
network-reachable endpoint on your behalf.

Most installations want the rest of the diagnostic readers at the same time —
`McpServiceProvider::offerDiagnostics($app)` offers all eleven, `db-inspect` included, behind
three separate scopes. See
[Offering the diagnostic tools](#offering-the-diagnostic-tools-the-ones-that-replace-ssh).

**3. Issue a token.** One command, run **on the installation you want to reach** — minting a
token means writing a row into its `usertokens`:

```bash
php <cli> mcp:token --user=you@example.com --scopes=diagnostics,logs,db --days=30
```

It prints the token **once** (the column is encrypted at rest) and the `.mcp.json` block to
paste, already filled in. `--scopes` takes the short names `diagnostics`, `logs`, `db`, or
full scope names; `mcp` is added whether you ask or not, so `whoami` is always reachable.

The ordinary OAuth2 authorization flow grants the same scopes if you would rather go that
way — see the [Authentication guide](Pramnos_Authentication_Guide.md). The command exists
because that is a browser round-trip for a capability whose whole point is that somebody is
at a terminal.

Three things about the scopes are worth knowing:

- **It does not exist until you offer the tool.** `scopes_supported` is served from
  `/.well-known/oauth-authorization-server` to anybody who asks, with each scope's
  description attached. A permanently registered `mcp:db_read` would announce that every
  Pramnos site has a capability for running SELECTs against its live database — including
  the great majority that never offered one. So the list is derived from `PublicRegistry`,
  and an installation discloses the capability exactly when it has one.
- It **inherits `mcp`**, so a token holding it also reaches `whoami`. That is how you check
  the token before trusting an empty answer from anything else.
- **`system:admin` does not inherit it.** An administrator of the application is not
  automatically somebody who may read its production tables from another machine, and the
  two being one grant is how the second gets handed out without ever being decided.

**4. Point the client at it.**

```json
{
  "mcpServers": {
    "production": {
      "type": "http",
      "url": "https://example.com/mcp",
      "headers": { "Authorization": "Bearer <the token>" }
    }
  }
}
```

A client that calls without a token gets `401` and a `WWW-Authenticate` header naming the
authorization server, which is the discovery mechanism — see
[Authentication is the one this server already does](#authentication-is-the-one-this-server-already-does).

**Start by calling `whoami`.** If `mcp:db_read` is not in the scopes it reports back, that
is the whole diagnosis and nothing else needs looking at.

> `mcp:token` cannot tell you whether the endpoint offers a scope, and says so rather than
> guessing. The call that offers the tools runs in an application `ServiceProvider`, booted
> by `Application::init()` — which a web request runs and a console command does not, so the
> scope registry looks empty from a terminal on **every** installation, including the ones
> that offer everything. The endpoint decides at request time; `whoami` is what reports it.

#### What you are trusting when you do this

Honestly stated, because the answer differs by installation:

| | |
|---|---|
| The scope | A boundary, enforced per request, revocable by revoking the token |
| A read-only database account | A boundary **PostgreSQL** enforces — the strongest one available, and optional |
| `ReadOnlyQuery` | A lexer. Where there is no read-only account, **this is the only thing between a token and a write** |
| The denial list | Decides what the rows contain, not whether the query runs |

The read-only account is worth setting up before the endpoint faces the internet, and it is
five `GRANT`s — the script is in the
[Security guide](Pramnos_Security_Guide.md#the-read-only-account-if-you-want-one), along
with the denial list, declaring your own tables, and what the lexer does and does not
promise.

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
// The index — one line per guide: its name, and the first task it covers.
{}

// The same index with every use case of every page — an order of magnitude larger
{"detail": "full"}

// Find the page for a task
{"query": "issue an API token for a signed-in browser"}

// Read one in full
{"page": "Pramnos_Authentication_Guide"}

// The dated history of a change, rather than how the thing works now
{"corpus": "changelog", "query": "session exchange"}
```

**The index is brief by default**, and that is a usability fix rather than a saving. Every use
case of every page came to about 27KB — a page of reading before the question has been asked —
and the measurable effect was that grepping `docs/` won instead: one line, and you know what
comes back. An index that fits in a glance gets asked reflexively, which is the only way an
index earns its place. `{"detail": "full"}` is there for when the brief one did not name what
you are looking for.

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
{"since": "HEAD"}                           // only the lines you changed — read this first
{"path": "src/Models"}                      // one subtree, or a single file
{"rules": ["raw-sql", "flash-query-params"]} // a subset
```

#### `since` — the option that makes it usable

Run over `src/`, this tool reports 76 findings, every one of them older than whatever you are
working on. The guide has said so from the start, and the predictable consequence was that
nobody ran it: with 76 pre-existing findings there is no way to see your own three.

`since` narrows to the lines the diff touched.

```jsonc
{"since": "HEAD",     "changed_files": 4, "changed_lines": 212,
 "suppressed": 5, "findings": [], "verdict": "No findings on the lines you changed."}
```

- **`HEAD`** is everything uncommitted, staged or not — what "my current change" means.
  **`staged`** is the index only, for a pre-commit gate. Any ref works: `main`, `HEAD~3`, a tag.
- **A new file counts entirely.** That is where new violations live, and skipping untracked
  files would pass every freshly written class.
- **Editing one line of a legacy file does not surface its other findings.** Otherwise this
  would be a file-level filter with a misleading name.
- **Outside a git working tree it refuses**, rather than reporting a clean change. "No findings
  on the lines you changed" when nothing was compared is a pass nobody earned.

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

### `console-commands`

Seventy-odd commands, twenty of which generate code. The reason this is a tool is the failure
it fixes: an assistant working in a codebase for a whole day writes a controller by hand rather
than running `create:controller`, because nothing told it the command exists. `--help` on
seventy commands is not a discovery mechanism.

```jsonc
{}                                  // everything, grouped by prefix
{"generators": true}                // only the commands that write files
{"filter": "migration"}
{"name": "create:crud"}             // one command's arguments and options
```

```jsonc
{
  "name": "create:crud",
  "description": "Create a complete CRUD (model, controller, views and/or API + SPA screen)",
  "usage": "create:crud [name]",
  "generates": true,
  "arguments": [{"name": "name", "description": "Name of the created object"}],
  "options": [
    {"name": "--table", "shortcut": "-t", "value": "value required", "description": "Database table"},
    {"name": "--target", "value": "value optional", "description": "mvc, spa or both"}
  ],
  "class": "Pramnos\Console\Commands\Make\MakeCrud"
}
```

- **`generates`** marks the commands that write files into the project. That is not something
  to infer from a one-line description: it decides whether a command can be run to see what it
  does.
- **`class`** is there so `find-symbol` is the obvious next question when the description is not
  enough. The two are meant to be used together.
- Read from the **live** console definition. A second catalogue kept in the tool would be a
  second thing to forget, and this file has already been bitten by exactly that.

Twenty generators exist: `controller`, `model`, `migration`, `crud`, `api`, `api-client`,
`screen`, `component`, `view`, `service`, `policy`, `provider`, `middleware`, `event`,
`listener`, `task`, `webhook`, `seeder`, `test`, `command`. Ask before writing one by hand.

### `theme-info`

Two questions, and the second is the one that bites.

```jsonc
{}                              // palette, themes, theme directories, build, freshness
{"theme": "msd-dark"}           // one theme's full token list
{"token": "color-primary"}      // one token across every theme
```

«What colour is `--color-primary` here» is answerable by reading a file, once you know which
file. «Is the CSS on disk built from the CSS in the repository» is not answerable by reading
anything, and getting it wrong is silent:

```jsonc
"freshness": {
  "built": true,
  "built_at": "27/08/2026 14:51",
  "stale": true,
  "newer_than_the_build": ["src/Views/register/register.html.php", "…"],
  "why": "These changed after the last build, so the served stylesheet does not reflect them."
}
```

daisyUI is a Tailwind **plugin**, so it cannot come from a CDN and the build is not optional:
without it the component classes resolve to nothing and the page renders *unstyled* rather than
failing. And the compiled stylesheet is committed on purpose — so a checkout serves the site
without npm — which makes it exactly the artifact somebody forgets to regenerate.

Three kinds of source are checked, because Tailwind depends on all three: the entry stylesheet,
the palette it `@import`s, and **every directory it `@source`s for class names**. The third is
the one that surprises people — adding `btn-primary` to a view means that class has to be
generated, so an untouched `app.css` is not evidence of a current bundle.

The input and output paths are read out of the `package.json` script rather than assumed, the
`--watch` script is never offered as the build command, and a project with no Tailwind script
is told about `theme:build` instead — whose output has no freshness problem, because it is a
direct translation of the palette.

`{"token": …}` keeps a `null` where a theme does not declare the token, and says what that
means: the value falls back to daisyUI's own, which is rarely the one beside it in the answer.
That is usually the real question — "is this readable in the other theme".

### `api-docs`

`route-list` answers *what URIs exist*. This answers *what the API promises* — parameters,
request bodies, response codes, which credential each operation needs. Different question, and
the one an integration is written against.

```jsonc
{}                                       // the operation list, plus the document's own facts
{"summary_only": true}                   // counts and freshness, no list
{"filter": "token"}
{"operation": "GET /me/tokens"}          // one operation in full
```

Each operation carries the **scheme names** from its `security` requirement rather than the
whole object, because "does this need a credential, and which" is the first thing anybody asks
and the object buries it.

The freshness check is the same idea as `theme-info`'s and matters for the same reason: the
OpenAPI document is a generated file that gets **committed**, so a controller can gain a
parameter while the published document goes on describing the old shape. Nothing fails. The API
works and the documentation lies, which is the worst available outcome because somebody
believes it.

Two generators are recognised, because a project uses one or the other — `api:docs` reading
`#[Route]` attributes, or an `openapi:generate` npm script converting apiDoc annotations.
Reporting only the framework's own would tell half the projects they have no API documentation.
An application with neither is told that `api:docs` would produce an empty document and pointed
at `route-list`, which parses the routes file instead.

`servers`, `parameters`, `summary` and `$ref` are legal keys beside the methods under a path,
and are not operations. Treating every key as one invented `SERVERS /oauth/token` and inflated
a real project's count from 15 to 20 — a fabricated endpoint in a list of endpoints is the same
failure as a wrong URI.

### `find-tests`

Where the test for this is, read from `#[CoversClass]` rather than guessed from a filename.

```jsonc
{"class": "LogManager"}
{"class": "Pramnos\Logs\LogManager"}
{"uncovered": true, "path": "src/Mcp"}
```

```jsonc
{
  "class": "LogManager", "covered": true,
  "coveredBy": [{"class": "LogManager", "tests": [
    {"class": "…\LogManagerTest", "file": "…/tests/Unit/Pramnos/Logs/LogManagerTest.php",
     "methods": 10}]}],
  "command": "./dockertest --filter 'LogManagerViewerCharacterizationTest|LogManagerTest'"
}
```

Guessing from the name has a wrong answer often enough to matter: `Pramnos\Logs\LogManager` is
tested in `tests/Unit/Pramnos/Logs/`, not `tests/Unit/Logs/`, and writing to a directory that
does not exist puts a new test somewhere nobody looks.

- **It runs nothing.** Running tests is something a shell does well, and wrapping it would hide
  the project's rule about *how*: these projects hold a lock, and two concurrent runs corrupt
  the shared test databases. So the command is reported — `./dockertest` when the project has
  one — and never executed.
- **The command runs every matching test class**, as a `--filter` alternation. Naming one of
  three is worse than useless: it looks like the command that verifies a change and silently
  skips two thirds of the evidence.
- **An undeclared class is not called untested.** `#[CoversClass]` is a declaration, not a
  measurement — but it is what the coverage report goes by, so the answer says both and points
  at whatever test files merely *mention* the class. That found a real gap on this repository:
  a `SeoTest` exercising `Seo` without declaring it.
- **The framework's own tests are in scope**, because a framework class is covered there and
  nowhere else, and "no tests" would otherwise be a confident lie.

### `status`

```jsonc
{}   // it takes no arguments at all
```

```jsonc
{
  "database":   {"connected": true, "type": "postgresql", "prefix": ""},
  "migrations": {"applied": 91, "pending": 0},
  "queue":      {"enabled": true, "by_status": {"pending": 3, "failed": 1}},
  "health":     {"status": "ok", "checks": {"database": {"status": "ok", "message": "Reachable"}}},
  "errors":     {"at": "29/08/2026 00:07:07", "level": "error",
                 "message": "column \"userid\" does not exist …",
                 "request": "62b82d3200a4be93"},
  "verdict":    "1 failed queue job(s); last error 29/08/2026 00:07:07."
}
```

Four questions that were four separate lookups and therefore usually none: a container that is
not running is discovered by a failure, and a pending migration by a column that does not exist.

- **The verdict is the product.** Five sections of JSON is what somebody skims past; one line
  is what gets read. It never says everything is fine while something is not, and an unreachable
  database is the *whole* answer rather than one finding among five — every other section is
  unanswerable without it, and the usual cause is a container that is not running.
- **Pending migrations are named, not counted.** "3 pending" is a number; the names say whether
  they are this afternoon's work or somebody else's from a branch.
- **The last error carries its request id**, which is the argument to `request-debug`.
- **It takes no arguments and changes nothing.** A tool called reflexively at the start of a
  session must not be able to start, migrate, clear or retry anything.

### `schema-drift`

```jsonc
{}                        // the whole comparison
{"table": "permissions"}  // one table: what creates it, and has that run here
```

`list-tables` reads the live database; `migration-status` reads the migrations. The question
that matters is neither: *does a migration create this table, and has it run here?* The gap
between those two answers is a whole category of bug and is invisible from either side.

```jsonc
{
  "unmanaged": ["deferredwrites", "schemaversion"],
  "applied_but_missing": [{"table": "pramnos.framework_policies",
                           "migrations": ["create_framework_policies_table"]}],
  "not_created_yet": [],
  "verdict": "2 live table(s) no migration creates, and 1 migration(s) applied without their table."
}
```

Three findings, and they are three different problems:

- **A table nothing creates.** It exists, code queries it, and a fresh installation will not
  have it. The failure is a deploy to a new environment, months later, by somebody who was not
  there.
- **A migration that ran without leaving its table.** The history says applied and the table is
  not there — so every future run considers it done. The alarming one.
- **A migration that has not run here.** Ordinary, and listed apart so it does not drown the
  other two.

Two more things it says, and both exist because the first version said them wrong:

- **A migration can declare itself conditional.** `pramnos.framework_policies` exists on MySQL
  and plain PostgreSQL and must *not* exist on TimescaleDB, which manages its own policies — so
  the history saying applied with no table is the migration behaving exactly as designed.
  Reported as drift, it is the loudest possible false alarm, and one at the top of a report is
  enough to make somebody stop reading it. `public bool $conditional = true;` on the migration,
  and it is listed apart.
- **It says what it could not read.** A migration that names its table with a constant or a
  setting — `createTable(DeferredWriteQueue::TABLE, …)` — cannot be read without running it, and
  the table it creates would otherwise appear under "no migration creates this". Those
  migrations are listed under `unreadable_migrations` and the note beside the unmanaged list
  points at them.

Two things it does that a simpler implementation would not:

- **It reads raw SQL as well as the schema builder**, and takes the `hasTable()` guard above an
  interpolated `CREATE TABLE {$t}` as the table's name. Several migrations have to write raw SQL
  — a hypertable, a schema-qualified table — and reading only `createTable()` reports every one
  of them as a table nothing creates, which is the loudest possible false alarm about the most
  carefully written migrations in the project.
- **It compares names normalised**, because the same table is spelled four ways:
  `#PREFIX#usersettings` in a migration and `pf_usersettings` in MySQL;
  `authserver.permissions` on PostgreSQL and `authserver_permissions` on MySQL. What it does
  *not* flatten is the schema itself — `authserver.permissions` and a bare legacy `permissions`
  are two different tables, and treating them as one hides the exact bug this was written for.

The migrations are **read, not executed**: `token_get_all()` and a regex over string literals. A
tool that ran a migration to find out what it creates would be a tool that migrates the database
by being asked a question.

### `request-debug`

```jsonc
{}                              // the requests that went wrong, most recent first
{"request": "62b82d3200a4be93"} // every line that request wrote
{"level": ""}                   // every request the log knows about
```

The debug toolbar answers this for a response somebody is looking at. A request that *died*
carried almost nothing back — an error page is not a JSON payload, and the header that still
gets through has room for a count and never for a message.

Listing is the default because the id is the part you do not have: it exists only after somebody
has read an error page and copied it out, which on a request that never rendered one nobody has.

Lines are tagged with a request id **only while the debug toolbar is active for that visitor**,
which is deliberate — on a live server everybody else is logging into the same seconds, and
their lines are not a developer's to read. So "no lines for that id" is usually correct, and the
tool says so rather than looking broken.

### `coverage`

The rule in these projects is coverage above 95% **on the code you changed**, and it was
unverifiable. A coverage run produces a project-wide percentage, which barely moves when fifty
uncovered lines are added to twenty thousand covered ones — so the rule was followed by
assumption, which is to say not followed.

```jsonc
{}                          // uncommitted changes vs HEAD
{"since": "main"}           // the whole branch
{"path": "src/Mcp"}
{"project": true}           // the whole-project figure, for context
```

```jsonc
{
  "report": {"file": "coverage/clover.xml", "generated_at": "28/08/2026 18:48", "stale": false},
  "since": "HEAD", "changed_executable_lines": 306, "covered": 23, "uncovered": 283,
  "percent": 7.5,
  "files": [{"file": "src/Pramnos/Mcp/Tools/CoverageTool.php", "covered": 0,
             "uncovered": 174, "uncovered_lines": [48, 50, 51, "…"]}],
  "verdict": "283 of 306 changed lines are not covered by any test — 7.5%."
}
```

That is a real first run: the tool pointed at its own author's work, reporting 7.5%. A number
that can fail is the point.

- **It reads `coverage/clover.xml` and runs nothing.** `./dockertest --coverage` holds a lock —
  two concurrent runs corrupt the shared test databases — and it could not run from inside the
  container it would have to start. Produce the report, then ask.
- **A report older than the code is called stale.** Reading a stale one is worse than reading
  none: it reports the previous version of a file as covered, and the line numbers have moved
  underneath it.
- **Lines that cannot be executed are not counted against you.** Blank lines, closing braces,
  comments and property declarations are absent from the report entirely, and counting them as
  uncovered would turn every honest change into a failure.
- **A whole file the report does not mention is a different thing, and is named.** A new class
  under a measured root is absent because *no test ever loaded it* — PHPUnit never saw a line, so
  it is not "nothing executable changed", it is 0%. Those appear under `unmeasured` and in the
  verdict. (Skipped in silence, a change consisting entirely of new untested classes reported
  100%, which is the worst answer a coverage gate can give.) Files outside the measured roots —
  guides, stubs, tests — are still skipped quietly, because a warning that fires on every
  markdown file is a warning nobody reads.
- **A container's paths are joined to project-relative ones.** Clover records the path the test
  run saw — `/var/www/html/src/…` — and getting that join wrong reports every line as
  unmeasurable, which is a silent pass.

`{"project": true}` is available and deliberately not the default: it is the number that made
the rule unverifiable in the first place.

### `changelog-add`

The only tool here that writes, and the only one that needed to be: the rule is one post per
day with every section listed at the top under a count, so adding an entry means appending the
section, rebuilding the list from the headings, and getting the count and its plural right.

```jsonc
{"title": "Two rules that could not be checked", "body": "…", "categories": ["Testing"]}
{"title": "…", "body": "…", "preview": true}      // see the result, write nothing
{"title": "…", "body": "…", "replace": true}      // rewrite an existing section
```

Four things it refuses to do:

- **It never edits the summary list** — the list is *derived* from the `##` headings, every
  time. A hand-maintained list drifts from what it describes, and the drift is invisible.
- **It refuses a duplicate title** unless asked to replace one. Two sections with one name make
  a summary entry that points at whichever the reader finds first.
- **It verifies before writing.** If the rebuilt list does not have exactly one entry per
  section, nothing is written and it says so — that mismatch is the failure it exists to
  prevent, and producing it silently would be worse than hand-editing.
- **It will not write into an installed package.** An entry under `vendor/` is edited into
  oblivion by the next `composer update`. A development checkout — a symlink, or a git tree — is
  the framework's own history, so that counts.

A new post gets the frontmatter, the `# 28 August 2026` heading, and `<!-- more -->` in the
right place: everything above the fold is the excerpt the blog index shows, so the summary
belongs above it and the sections below.

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

The application's own files, registered only when present:

| URI | File |
|---|---|
| `file://CLAUDE.md` | `ROOT/CLAUDE.md` |
| `file://README.md` | `ROOT/README.md` |
| `file://app/app.php` | `ROOT/app/app.php` |
| `file://docs/<name>.md` | every markdown file directly inside `ROOT/docs/` |

The last row is **discovered, not listed**, and that is the point of it. A project's own notes —
a request log, a decisions file, whatever that project calls it — are exactly the documents
somebody wants in context from the first message, and they were never going to be named in the
framework. `docs/` is where a project puts them, and a directory scan cannot go out of date.

Top level only, and markdown only: a recursive scan picks up a vendored copy of somebody else's
manual, and a resource list nobody reads is the same as no resource list.

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

## Serving it over HTTP, to somebody else's assistant

Everything above is the **internal** server: `mcp:serve` over stdio, twenty tools that read
coverage reports, run the style checker and query the local database, for an assistant working
alongside you on this machine. None of it should ever answer a stranger.

> **What "public" means here, because the word does double duty.** It means *reachable over
> the network from off this machine* — as opposed to the stdio server, which requires a shell.
> It does **not** mean unauthenticated and it does not mean open. Every call to this endpoint
> carries a bearer token that `UnifiedAuthMiddleware` has validated, every tool declares a
> scope that token must hold, and the tool list is built per request from what that token
> reaches — a caller sees nothing they cannot call. `PublicRegistry` is named for the first
> sense, not the second. "Externally reachable, always authenticated, always scoped" is the
> accurate long form.

The public endpoint is a separate thing that happens to speak the same protocol.

```php
// A ServiceProvider, or app/providers.php
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\Tools\SearchTool;

PublicRegistry::add(new SearchTool());
```

That is the whole of it. `init` scaffolds `POST /mcp` when the `authserver` feature is on.

### `whoami`, the one tool the framework offers publicly

The endpoint used to answer with an **empty** tool list until an application registered
something, and an empty tool list is indistinguishable from a broken one: a client that
connects, authenticates and receives nothing cannot tell working wiring from a wrong token
from scopes nobody granted.

So one tool ships registered — when the `authserver` feature is on, which is what scaffolds
the endpoint in the first place. With no endpoint there is nothing to smoke-test, and
registering it anyway would put `mcp` into the `scopes_supported` served from
`/.well-known/oauth-authorization-server`, announcing an MCP endpoint on installations that
do not have one.

It asks for the scope `mcp`, and answers with the id and scopes of the token the call arrived
with:

```json
{ "user_id": 4242, "authenticated": true, "scopes": ["mcp", "orders.read"], "server": "pramnos-mcp" }
```

It discloses nothing the caller did not already present — no name, no email address. This is
a production endpoint whose answers travel, and an id is what identifies a row in a log.

It is also the first thing to reach for when a tool is "not showing up": if the scope that
tool needs is missing from this answer, that is the whole diagnosis.

An application that wants even this gone withdraws it in its own provider, which runs after
the framework registers it:

```php
PublicRegistry::remove('whoami');
```

### Offering the diagnostic tools — the ones that replace SSH

`db-inspect` answers *what is in the data*. The rest of the questions somebody
opens a shell for — is anything broken, what migrations are pending, does the schema
match, what is failing and how often — are answered by tools that already exist on the
internal server. One deliberate call offers them:

```php
use Pramnos\Mcp\McpServiceProvider;

McpServiceProvider::offerDiagnostics($app);
```

Eleven tools, behind **three separate scopes**, because they disclose different things:

| Scope | Tools | What it discloses |
|---|---|---|
| `mcp:diagnostics` | `status`, `migration-status`, `schema-drift`, `list-tables`, `query-schema`, `route-list`, `model-inspect` | Structure and state — names and shapes, no rows, no free text |
| `mcp:logs` | `log-errors`, `log-analytics`, `request-debug` | What has been happening, **as free text** |
| `mcp:db_read` | `db-inspect` | Rows, with the denial list applied |

Three rather than one because reading the schema, reading the logs and reading the rows
are different questions. An installation granting the first must not silently be granting
the third.

`offerDiagnostics()` takes the application, and without one still offers the readers that
need none — the log tools work when nothing boots, which is exactly when somebody wants
them. Narrow it with the second argument:

```php
McpServiceProvider::offerDiagnostics($app, ['status', 'migration-status']);
```

#### Before you grant `mcp:logs`

**Log lines are free text, and free text is not redacted.**
[`PersonalDataRegistry`](Pramnos_Security_Guide.md#personal-data-and-the-denial-list)
withholds *columns*. It cannot see an email address inside a stack trace, a request body
captured by the debugger, or a token somebody logged in a URL. `mcp:diagnostics` and
`mcp:db_read` have boundaries; `mcp:logs` has the same exposure as handing somebody the
log directory, and is worth granting on that understanding rather than by analogy with
the other two.

#### What is deliberately not offered, and why

Not taste — each omission is a different reason:

| Not offered | Why |
|---|---|
| `changelog-add` | It **writes files**. A public endpoint that edits the repository on a production box is a different risk class, and no incident needs it. |
| `coverage`, `find-tests` | They read test artefacts a deployment does not have. |
| `framework-docs`, `find-symbol`, `console-commands`, `api-docs`, `theme-info`, `pramnos-check` | They answer questions about the **codebase**, which whoever is asking has checked out locally. Over HTTP they disclose source structure and buy nothing. |

None of them is blocked. If your installation disagrees, one line offers any of them:

```php
use Pramnos\Mcp\ScopedTool;

PublicRegistry::add(ScopedTool::wrap(new FrameworkDocsTool(), 'mcp:diagnostics'));
```

#### Why a wrapper rather than a `requiredScope()` on each tool

Because the property that protects this endpoint is that a development tool is **not**
publicly registrable. Adding the method to the diagnostic tools would make every one of
them offerable from then on, and a twenty-first tool would reach the internet by being
written rather than by being chosen.

`ScopedTool` keeps the decision at the call site, where it shows up in a diff. It also
makes the scope a property of *this exposure* rather than of the tool, so the same reader
can sit behind different scopes on different installations without either of them editing
the framework.

### Offering a capability: the short way

Most of what an application wants to offer is a name, a sentence and a closure. Writing a class
for three lines of logic is the reason capabilities do not get offered at all, so there is one
call that needs no class:

```php
use Pramnos\Mcp\PublicRegistry;

PublicRegistry::offer(
    name:        'station-health',
    scope:       'user',
    description: 'Report the last successful stream check for a station.',
    input:       ['station_id' => 'integer', 'verbose' => 'boolean?'],
    handler:     fn (array $in) => Station::health((int) $in['station_id']),
);
```

That is a complete, authenticated, scope-gated MCP tool.

**The five arguments, and what each is really for:**

| | |
|---|---|
| `name` | What a model calls. Unique within the endpoint. |
| `scope` | The OAuth scope a token must hold. There is no default — see below. |
| `description` | **One sentence, and it is all a model reads when deciding whether to call this.** It is not documentation for a person; it is the entire basis of the decision. "Report the last successful stream check for a station" gets called correctly. "Station tool" does not get called at all, or gets called for everything. |
| `input` | A compact spec, or full JSON Schema. |
| `handler` | Anything callable. It receives the decoded input and returns anything JSON-serialisable. |

**The input spec.** `['station_id' => 'integer', 'verbose' => 'boolean?']` is the same document as
fourteen lines of nested arrays, and the fourteen lines are where people stop. A trailing `?`
marks a parameter optional; everything else is required — required-by-default is the safer
mistake, because a model omitting something the tool needs gets a clear refusal, where a tool
quietly running without it produces a wrong answer nobody questions.

Anything the short form cannot say is written as ordinary JSON Schema, and there is no wall to
hit at either end:

```php
// One awkward parameter among the shorthand
input: [
    'query' => 'string',
    'mode'  => ['type' => 'string', 'enum' => ['fast', 'slow']],
],

// Or the whole thing spelt out — a spec with a top-level `type` passes through untouched
input: ['type' => 'object', 'properties' => [/* … */], 'required' => ['id']],
```

### Offering a capability: the long way

Implement `ScopedMcpTool` when the tool has state, needs constructor injection, or is worth unit
testing on its own:

```php
use Pramnos\Mcp\ScopedMcpTool;

class StationHealthTool implements ScopedMcpTool
{
    public function __construct(private StationRepository $stations)
    {
    }

    public function name(): string          { return 'station-health'; }
    public function description(): string   { return 'Report the last stream check.'; }
    public function requiredScope(): string { return 'user'; }

    public function inputSchema(): array
    {
        return PublicRegistry::schema(['station_id' => 'integer']);
    }

    public function execute(array $input): mixed
    {
        return $this->stations->health((int) $input['station_id']);
    }
}

PublicRegistry::add(new StationHealthTool($stations));
```

`PublicRegistry::schema()` is available to a class too — the compact form is not tied to the short
door.

Both doors lead to the same registry and obey the same rules. Neither is the "real" one.

### Three things worth getting right

**The scope is not decoration.** It is the only thing standing between a capability and every
person with a token. Pick the narrowest scope that makes sense, and if none of the existing scopes
fits, that is a signal the capability needs a scope of its own rather than a borrowed one.

**A tool runs as the person, not as the application.** The token identifies somebody, and
`User::getCurrentUser()` is that person inside a handler. Scope every query by them. The endpoint
having authenticated the caller is not the same as the handler having checked that this caller may
see this row — the first is *who*, the second is *what*.

**Return data, not prose.** A handler returning `"Station 4 was last checked at 14:02"` gives a
model a sentence to re-read to somebody. Returning `['station' => 4, 'checked_at' => '…']` gives it
something to reason with, combine with another answer, and phrase itself. Structure survives
translation, summarising and being asked a follow-up; a sentence does not.

### Why two registries and not one list with a flag

A shared collection with an "expose this" flag means every tool written from then on is public
until somebody remembers to mark it otherwise. The failure is silent and remote: a twentieth
development tool is added, and it is answering over HTTP before anybody notices it is reachable.

So public exposure is **a type, not a flag**. A tool must implement `ScopedMcpTool`, which adds one
method:

```php
public function requiredScope(): string;
```

A tool that does not implement it cannot be added to `PublicRegistry`, and no configuration file
can override that. A tool that implements it but returns an empty scope is refused at registration
with an exception rather than skipped — a tool quietly dropped at boot is absent at run time for a
reason nobody can see.

`ScopedTool` is the door through that, and it does not weaken the rule: it is code somebody
writes, naming the tool and choosing its scope, rather than a flag on the tool itself. The
difference is what happens to the *next* tool written — with a flag it is exposed by default
until somebody remembers otherwise; with a wrapper it is not offered until somebody says so in
a diff. An empty scope is still refused through the wrapper, because the refusal lives in
`PublicRegistry` and the wrapper adds no exemption.

### Authentication is the one this server already does

The endpoint sits behind `UnifiedAuthMiddleware`, which validates the bearer token, loads its
scopes from `usertokens` and resolves the user. Nothing about OAuth is reimplemented here.

An unauthenticated call gets `401` and a header:

```
WWW-Authenticate: Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource"
```

That header **is** the discovery mechanism. An MCP client is expected to call blind, be refused,
read the document it is pointed at, find the authorization server and come back with a token. A
bare `401` ends the conversation — the client has nowhere to go and a person has to configure it
by hand.

### Only what the token reaches, and the guarantee is structural

The server is built **per request**, holding only the tools the caller's scopes reach. A tool the
caller may not use is not in that server at all, so naming it directly answers *unknown tool*.

That is stronger than filtering the list and checking again on the call: there is one decision
rather than two that can disagree, a client that never read the list gains nothing by guessing,
and the refusal does not confirm that the tool exists.

Scopes resolve through `Scopes::resolveInheritedScopes()`, so a parent scope satisfies a tool
asking for a child — the same rule the router applies, rather than a second one that drifts from
it. A same-origin session's wildcard `*` reaches everything, exactly as it does elsewhere. A token
whose scopes cannot be read reaches **nothing**: an unreadable scope list means "I do not know what
this caller may do", and the safe reading of that is not "everything".

### `SearchTool`, and why it is the one worth shipping

It needs no code from the application beyond the `Registry::register()` calls it already makes for
its own search box — see the [Search guide](Pramnos_Search_Guide.md).

That works because `Search\Registry` was built for a box that several kinds of user share. Each
source declares a `permission`; each row is scoped by a `filter` callable that receives the current
user; **both fail closed**, and a dropped source leaves no trace rather than an empty group, because
an empty group named "Invoices" tells somebody who may not see invoices that invoices exist. An MCP
caller is a signed-in person with a token, so the question is the one the search box already asks
and the answer is scoped by the same code.

Its grouped-not-ranked shape carries through, and suits a language model better than a flat list:
"5 users, 3 orders" is a fact it can reason about, where a merged ranking invites it to treat
position as meaning.

Two returns are deliberately distinguishable. An empty term gives `total: 0` and no groups — never
the first page of everything. An installation with **no registered sources** says so in a `note`,
because a model told "0 results" concludes the thing does not exist and tells the person so.

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
