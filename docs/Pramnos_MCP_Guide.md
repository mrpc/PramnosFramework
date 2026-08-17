---
use_cases:
  - Letting an AI assistant explore this application's schema, routes and migrations
  - Making the framework's own documentation reachable from inside a project
  - Exposing an application-specific capability to an assistant as an MCP tool
  - Registering a project file as a resource an assistant can read
  - Working out why an assistant reimplemented something the framework already ships
---

# MCP server

The framework ships an **MCP** (Model Context Protocol) server, launched with
`php <cli> mcp:serve`. It speaks JSON-RPC 2.0 over stdio, which is what an assistant such
as Claude Code expects, and it exposes two kinds of thing:

- **tools** — callable capabilities, five of which introspect the application and one of
  which reads the framework's own guides;
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
discovers the server. `pramnos init` writes it; `project:resync` restores it.

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
| `framework-docs` | **How the framework works** — see below |

The first five are **application introspection**: they answer *what exists in this
project*. They need a database or an application to answer at all, and two of them are
skipped when no connection is configured.

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
