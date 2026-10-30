---
use_cases:
  - Calling a language model (OpenAI, DeepSeek, Gemini, Mistral) from an application
  - Letting a model answer with the application's MCP tools
  - Keeping data of one kind away from a provider that may not have it
  - Plugging an application's plans, quotas or consent into model calls
  - Working out what model calls cost, which failed, and why
  - Giving users conversations with a model that remember them
  - Scheduling a question to a model every morning, as its owner
  - Sending an organisation a scheduled AI report its members subscribe to, by email too
  - Seeing what each scheduled AI task or report costs
  - Keeping a table or list an AI answer produced, to open or act on later
  - Handing part of an answer to a subagent with its own context, tools and budget
  - Exporting an AI result to CSV or PDF, or pinning a chart to the dashboard
  - Reading the fields of an invoice or form with a model, checked before saving
  - Sharing an AI conversation or one of its results with a colleague
  - Letting the AI propose changes to records that a person approves before they happen
  - Collecting feedback on AI answers and reviewing the bad ones
  - Making the AI remember and update what each user, or the whole team, tells it
  - Letting the AI explain, in plain words, a change in the code behind "it worked yesterday"
  - Giving people a page to see, pause and receive their scheduled AI tasks and reports
  - Handing a leaving member's scheduled AI tasks to someone else
  - Asking users' agreement before their data goes to a provider
  - Turning an attached Word, OpenDocument or email file into text for a model
  - Adding an Ask AI page, widget or SPA screen to an application
  - Letting administrators ask questions about the installation's own figures
---

# Language models

`Pramnos\Llm` calls a language model, lets it use tools, logs every call with what it cost, and
gives an administrator one screen — `/admin/Ai/settings` — for keys, models, prices and the log. What the
framework cannot decide for an application (who pays, who agreed, where data may go) is a policy
the application supplies.

Enable the `llm` feature — `php pramnos init --features=auth,llm` for a new project; an existing
one adds `'llm'` to `features` in `app/app.php` and runs `migrate`. It creates `llm_calls`, puts **AI** in the admin
menu and schedules `llm:prune` daily. A project that existed before needs the admin wrapper
`src/Admin/Controllers/Ai.php`, which `init` writes for a new one:

```php
namespace App\Admin\Controllers;

class Ai extends \Pramnos\Application\Controllers\AiController
{
}
```

## Asking

```php
use Pramnos\Llm\Client;
use Pramnos\Llm\Connection;

$answer = (new Client(new Connection(feature: 'chat')))
    ->about(['purpose' => 'chat', 'organization_id' => $orgId])
    ->ask('You answer in one sentence.', [['role' => 'user', 'content' => $question]]);

echo $answer['ok'] ? $answer['reply'] : $answer['says'];
```

Both `ask()` and `askWithTools()` answer an array:

| key | |
|---|---|
| `ok` | whether there is a reply |
| `reply` | the model's words |
| `says` | why not, in a sentence somebody can act on, when `ok` is false |
| `usage` | the provider's usage object, summed over the rounds |
| `cost` | in USD; `null` when any round's model has no price |
| `calls` | the tools called, `['name' => …, 'arguments' => …]`, in order |
| `context` | the last round's prompt tokens: the conversation as the model saw it |
| `finish` | the provider's `finish_reason` |
| `tags` | the data tags whose results reached the model (see [Data rules](#data-rules)) |
| `provider` | the provider that answered — not the one it started on, if the answer moved |
| `limited`, `consent` | present when a limit or a consent policy refused |

A `200` with no words in it is a failure with a reason: the usual one is a reasoning model that
spent the whole output budget thinking (`finish_reason: length`).

`about()` sets what lands on the log row: `purpose` (so the log can answer *what is costing
this*), `userid` (the signed-in user when not given), `organization_id`, and anything else.

### An answer in a given shape

`Client::askForJson($systemPrompt, $messages, $schema, $name)` asks for an object of a JSON
schema and returns it decoded and checked, as `data`:

```php
$answer = $client->askForJson('Classify the message.', $messages, [
    'type' => 'object',
    'properties' => ['topic' => ['type' => 'string', 'enum' => ['billing', 'support', 'sales']], 'urgent' => ['type' => 'boolean']],
    'required' => ['topic'],
], 'classification');

$answer['ok'] ? $answer['data']['topic'] : $answer['says'];
```

Where the provider takes `response_format: json_schema` (its entry says `'json_schema' => true`:
OpenAI, Gemini, Mistral), the answer is asked for in that shape, with no tool. An answer that does
not fit is sent back once with what is wrong, and a second one that does not fit is a failure. A
fenced code block around the JSON is read. Elsewhere, DeepSeek for one, the schema becomes the
arguments of one tool named `$name`, which the model is made to call.

`JsonSchema::errors($value, $schema)` is the check. It covers `type` (one, or a list),
`required`, `properties`, `items`, `enum`, `minimum`/`maximum`, `minLength`/`maxLength` and
`minItems`/`maxItems`, and nothing else in a schema. A number written as text (`"5"`) counts as a
number.

### Reading fields out of a document

`Pramnos\Llm\Extractor` reads named fields out of a document — an invoice, an order, a form — and
checks them before anybody uses them. It never writes: the application shows what was read for a
person to confirm, or hands it to an [approved change](#changes-the-model-proposes-and-a-person-approves).

```php
$fields = [
    'supplier' => ['type' => 'text', 'required' => true],
    'issued'   => ['type' => 'date', 'required' => true],
    'total'    => ['type' => 'number', 'required' => true, 'description' => 'Including VAT'],
    'currency' => ['type' => 'enum', 'options' => ['EUR', 'USD']],
    'vat'      => ['type' => 'text', 'pattern' => '/^EL\d{9}$/'],
];
$read = (new \Pramnos\Llm\Extractor($client))->fromFile('invoice.docx', $bytes, $fields, 'a supplier invoice');
// ['ok' => true, 'data' => [...], 'missing' => [], 'invalid' => [], 'says' => '']
```

The model answers through one tool whose arguments are the fields — not prose to be parsed — told to
leave out what the document does not say rather than guess. Each field is then checked: a required
one is there; a `number` is a number as people write it (`1.240,50` and `1,240.50` both read); a
`date` is a real date written `YYYY-MM-DD`; an `enum` is one of its options; a `pattern` matches.
What fails is reported by field in `missing` / `invalid` and kept out of `data`.
`Extractor::check($values, $fields)` runs the same checks on what a person corrected.

A file is read for its text by `DocumentText` (docx, odt, txt, md, eml); a PDF where `pdftotext`
(poppler-utils) is installed on the server, and otherwise the answer says that is what it needs. The
document goes to the provider the client is connected to: pass a `Client` of a feature and
connection that may receive it.

### An answer cut off at the length limit

When a model stops at its token budget with text already written (`finish_reason: length`), the
client asks it to continue where it stopped, up to twice (`Client::CONTINUATIONS`), and joins the
parts. Still cut off after that, the answer is kept with a line under it saying so and returned
with `truncated: true` — a scheduled report is never emailed stopping mid-sentence as though it
were whole. Raise the budget in the AI settings for longer answers.

### What one answer may spend

The rounds (`askWithTools(..., $rounds)`) cap how many times a model may call tools; a turn budget
caps what one answer costs over all of them, in dollars and in tokens (prompt and output together):

```php
$client->withTurnBudget(maxCost: 0.10, maxTokens: 200_000)->askWithTools(...);

// or for every answer, in app/app.php
'llm' => ['turn_budget' => ['cost' => 0.10, 'tokens' => 200_000]],
```

Once either is passed, the next round is the last: no more tools, and the model is told
(`Client::OVER_BUDGET`) to answer from what it has, say what is left undone and offer to go on.
The answer comes back with `over_budget: true`. An answer over its budget is not sent back for
correction by `$verify`, since that would spend more. With neither set there is no cap but the
rounds.

Set the token figure from what an answer costs today, not from a round number: every round sends
the whole conversation again, so an answer of six rounds over a 25,000-token prompt spends
150,000 tokens before its output.

## Providers and connections

Four are built in, all through the OpenAI-compatible `/chat/completions`: `openai`, `deepseek`,
`gemini` and `mistral`. Each keeps its own settings, so several stay configured at once:
`llm_<provider>_api_key`, `_base_url`, `_model`, `_temperature`, `_max_tokens`, `_reasoning`.
`llm_provider` chooses the one in use. Everything is edited on `/admin/Ai/settings`; keys are stored
encrypted (they need `APP_KEY`) and never printed back.

An application adds providers, overrides fields of the built-in ones, and names its default:

```php
// app/app.php
'llm' => [
    'default'   => 'deepseek',
    'providers' => [
        'openai'        => ['default_model' => 'gpt-4.1-mini'],            // merged over the built-in
        'openai_shared' => ['label' => 'OpenAI, shared project',           // a provider of its own
                            'base_url' => 'https://api.openai.com/v1',
                            'token_param' => 'max_completion_tokens'],
    ],
],
```

What an entry can say: `label`, `base_url`, `api_key_url`, `models_path`, `balance_path`,
`token_param` (`max_tokens` or `max_completion_tokens` — the wrong one is a 400),
`reasoning_param` (`thinking` for DeepSeek's switch, `effort` with `reasoning_models` and
`efforts` for OpenAI-style), `supports_temperature`, `cache_key_param`, `model_pattern` (which of
`/models` are chat models), `default_model`, `models` (the curated list and what each is for),
`requires_consent` and `place`, and `transport` — a `Pramnos\Llm\Transport\Transport` class for a
provider that does not speak the OpenAI shape.

A `Connection` is one provider with its key, model and parameters. `with([...])` replaces fields
for one call and returns a new connection — an organisation's own key:

```php
$theirs = (new Connection('openai'))->with(['api_key' => $key, 'own' => true]);
```

`own` is what `ownKey()` reports to a limits policy: usually a call nobody else pays for.
`check()` asks the provider whether the key works and says what is wrong in words;
`models()` reads the provider's catalogue (falling back to the curated list); `balance()` and
`hasCredit()` read the balance where a provider publishes one (DeepSeek does).

### When a provider is busy or down

A request answered with a timeout, 429, 500, 502, 503, 504, 529 or no answer at all
(`Client::PASSING`) is asked again after 2 seconds, then 5 (`Client::RETRIES`). An error the
request itself caused, such as a 400, is not asked again, because the same request gets the same
answer. Still failing, the answer goes to the application's fallback provider:

```php
'llm' => ['fallback' => 'mistral'],
```

It goes there only where that provider may take it: it has a key, it is agreed to where agreement
is asked for, it is within the limits, and the rule of every data tag the answer read or its
conversation holds accepts it. The payload is rebuilt for that provider (its model, its token
parameter, none of the first provider's own fields), and the answer goes on and is logged there.
Without a fallback, or where it may not, the failure is returned as before, with its reason.

### Streaming an answer

`$client->onText(fn (string $soFar) => …)` is told the answer's text while the model writes it:
each time more arrives, the whole of it so far. A round that calls tools starts again from
nothing. An answer cut off at the length limit and continued goes on from where it was. The
answer `ask()` and `askWithTools()` return is the same as without streaming: its tool calls and
usage are put together from the stream's pieces. `onText(null)` stops it.

Only a transport with a `chatStream()` method streams. `OpenAiCompatible` has one: it sends
`stream: true` with `stream_options.include_usage` and reads the server-sent events. A provider
that refuses that request with a 400 or 422 is asked again the ordinary way, and one that answers
with a plain body is read as one. A transport of the application's own without `chatStream()`
answers as before, all at once.

### Features

An application names its uses of a model, and each can have its own provider, model, temperature,
budget and reasoning switch, set on `/admin/Ai/settings`:

```php
'llm' => [
    'features' => ['chat' => 'Ask AI', 'writer' => 'The article writer'],
    'follows'  => ['writer' => 'chat'],   // left empty, `writer` follows `chat`
],
```

`new Connection(feature: 'writer')` then applies them. A model or reasoning switch set for one
provider is not asked of another when an answer moves.

## Tools

```php
use Pramnos\Llm\Tools;
use Pramnos\Mcp\PublicRegistry;

$tools  = Tools::fromMcp(PublicRegistry::visibleTo(['mcp']), skip: ['whoami']);
$answer = (new Client())->askWithTools($systemPrompt, $messages, $tools, rounds: 6);
```

A tool is a plain array — `name => ['description', 'parameters' => JSON schema, 'run' => callable]`
— so an application's own closures sit beside the MCP ones. `run` gets the decoded arguments; a
result that is not a string is JSON-encoded; what it throws is told to the model as an error
rather than ending the answer. Results are cut at 20,000 characters.

**Arguments are checked before a tool runs.** A call whose arguments are not a JSON object, or do
not fit the tool's `parameters` (`JsonSchema::errors()`), is not run. The model is told what is
wrong in place of the result (`Error: send was not run: to: is required. …`) and calls again,
corrected, in the next round. A tool therefore never runs on arguments it does not take: a
missing required field, a value outside its `enum`, a list where an object belongs. Such a call
is not run at once with others either.

**A long answer makes room for itself.** Each round's prompt is counted by the provider
(`prompt_tokens`). Past `Client::ROOM` (70%) of the connection's `context_window`, the answer's
tool results before the last two are replaced with a note naming their tool
(`Client::SET_ASIDE`). The calls stay, and the model can call a tool again if it needs that result.
An answer that reads a lot therefore goes on rather than failing at the window.

The loop: the model answers `tool_calls`, each is run, its result goes back, until it answers in
words. **Text the model writes in a round that also calls a tool is part of the reply**: the
reply is every such text, in order, then the last round's, a blank line apart. A model can write
its whole answer and call a tool once more in the same turn, and its last round is then a
postscript. The same texts are also returned apart: `final` is the last round's alone, and `steps`
the texts written beside tool calls. A chat stores `final` as the message and the `steps` in its
`extra`, and the bundled chat screens fold them into a closed "What it did on the way", so lines
such as "Let me check…" are neither the start of the answer, nor in its copy, nor in the history
the next question replays. The call log records that text beside the `tool calls:` line. The
**last round forbids tools** and tells the model not to claim anything no tool did —
without it a model that spent every round researching writes as if the work were done. Two more
handles for tasks that must end in a write:

```php
$due = ['create_draft' => fn (array $calls) => !in_array('create_draft', array_column($calls, 'name'), true)];
$verify = fn (string $reply, array $calls) => str_contains($reply, 'saved') && $calls === []
    ? 'Nothing was saved. Call create_draft, or say it was not done.'
    : null;

$client->askWithTools($prompt, $messages, $tools, 6, $due, $verify);
```

A **due** tool is forced on the round before the last when its test holds. **verify** reads the
answer against the calls made; once, a correction is sent back with a required call and three
more rounds. The rejected answer, and the text written before it, are dropped: the reply
is the one written after the correction.

### Declaring tools once: the registry

`ToolRegistry` holds tools with the metadata the runtime reads. It hands them to the client per
person, and per skill in use:

```php
use Pramnos\Llm\ToolRegistry;

$registry = (new ToolRegistry())
    ->registerMcp(new ExportCsvTool(), ['idempotent' => true, 'permissions' => ['admin.reports'], 'skills' => ['reporting'], 'latency_tier' => 'slow'])
    ->register('send_email', $send, ['writes' => true, 'skills' => ['mail']], 'Send an email', $schema)
    ->add($assistantTools)                       // tools already in the client's shape
    ->describeSkill('reporting', 'Reports and exports of the site\'s figures');

$tools = $registry->tools(['userid' => $userId], skills: ['reporting']);
```

| Metadata | Default | Read by |
|---|---|---|
| `writes` | false | held after untrusted reads, made once per answer, gated by a `GrantGate` |
| `idempotent` | false | with `writes` false, the tool runs at once with others |
| `parallel` | `!writes && idempotent` | given explicitly, over the default |
| `untrusted` | false | the result is data, not instructions |
| `gated` | false | needs a grant though it does not write |
| `permissions` | none | every ability, through `AdminAccess::allows()` unless the registry is given its own check |
| `skills` | none | offered only while one of its skills is in use; a tool with none is always offered |
| `tags` | none | data tags, as `Tools::tag()` |
| `cost_weight`, `latency_tier` | `low`, `fast` | kept on the tool's `meta` |

**Skills, a few at a time.** Offer the tools of no skill, the `use_skill` tool, and a line in the
prompt naming the skills there are:

```php
public function tools(Turn $turn): array
{
    $context = ['userid' => $turn->rightsOf()];

    return $this->registry->tools($context, skills: []) + $this->registry->skillTool($context);
}

public function systemPrompt(Turn $turn): string
{
    return '…' . "\n\n" . $this->registry->manifest(['userid' => $turn->rightsOf()]);
}
```

The model calls `use_skill` with a skill's name, and the skill's tools are offered from the next
round. Any tool may do the same through an `expands` callable, which takes the call's arguments
and returns tools to add. A skill with no tool this person may use is not offered, and neither
are its tools. Tools added during an answer run in the answer, never on a parallel worker,
because a worker builds only the answer's first tools.

### Tools from other MCP servers

The framework is also an MCP **client**. An application connects to external MCP servers, and
their tools reach the model like any other:

```php
// app/app.php
'llm' => ['mcp_servers' => [
    'github' => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-github'], 'env' => ['GITHUB_TOKEN' => '…']],
    'docs'   => ['url' => 'https://mcp.example.com/mcp', 'headers' => ['Authorization' => 'Bearer …'], 'skills' => ['docs']],
]],
// or the file other MCP clients read: {"mcpServers": {…}} in the same shape
'llm' => ['mcp_servers' => ROOT . '/app/mcp.json'],
```

```php
use Pramnos\Llm\Mcp\Servers;

$tools = Servers::tools();                    // github__search_issues, docs__search, …
Servers::register($registry);                 // or into a ToolRegistry, with their metadata
```

A server is either `command` (with `args`, `env`, `cwd`), run as a process and spoken to over
stdio, or `url` (with `headers`), spoken to over the Streamable HTTP transport, which answers in
JSON or in server-sent events. `timeout` is seconds per call (30). Any other key, such as
`permissions`, `skills` or `gated`, goes on each of its tools as registry metadata.

- **Every tool from another server is `untrusted`.** What it returns was written by somebody
  else, and arrives as data, not instructions.
- A tool the server marks `readOnlyHint` is idempotent, so through a registry it runs at once with
  others. Any other tool is taken to **write**, which means it is held after untrusted reads,
  made once per answer, and gated by a `GrantGate`. The safe assumption for a tool nobody
  described is that it changes something.
- Names are `{server}__{tool}`, cut to what a provider accepts (`Mcp\Client::nameFor()`).
- Each server is connected once per process and its tools are listed once. A server that cannot
  be reached is logged and left out, so a service that is down costs its own tools and not the
  answer. A result the server marks as an error reads `Error: …`, like a failed tool of our own.

One server on its own is `new Mcp\Client(new StdioTransport([...]))` or
`new Mcp\Client(new HttpTransport($url, $headers))`, with `listTools()`, `callTool()` and
`tools($prefix)`.

### A change is made once per answer

A tool marked `'writes' => true` runs once per answer, tool and arguments. Called again with the
same arguments, in any key order, it is not run. The model is told it was done and what it
returned then (`Idempotency::DONE`), so a model that loses track, or is corrected and does it
again, does not send the same email twice. Other arguments are another change.

```php
$client->answering('ask:' . $askId)->askWithTools(...);   // a name for the answer
```

Without a name each answer is its own, and only a repeat within it is caught. A queued question
is named by its ask, so if it is answered again after a crash it does not repeat what it already
changed. The key is taken by an insert into `pramnos.llm_tool_idempotency` before the tool runs,
so two processes cannot both make the change. A result starting with `Error:` gives the key back
so the change can be tried again. Keys are kept a day (`Idempotency::KEEP`). Without the table,
every call runs.

### Tools that run at once

A model often asks for several reads in one round — three figures, two queries. A tool marked
`'parallel' => true` says it reads and changes nothing and depends only on its arguments and the
person asking. When a round asks for two or more of those and for no tool that `writes`, they run
at once:

```php
// app/app.php
'llm' => ['parallel_tools' => true],              // or ['timeout' => 60], seconds
```

```php
// the application's orchestrator: a pool for the calls, beside its other daemons
protected function buildDesiredProcesses(): array
{
    return [
        ...$this->queuePool('llm-tools', ['types' => ['llm_tool'], 'floor' => 1, 'ceiling' => 4, 'args' => ['--sleep', '1']]),
    ];
}
```

Each call becomes a row of `pramnos.llm_tool_runs` and a queued `llm_tool` task. The answer works
through the rows itself while the pool's workers take tasks; whichever claims a row first runs
that call. So an answer never waits for a worker that is not there: with no pool, or a pool
asleep, the calls run one after another as before, and a slow call, such as a long query, runs
beside the others instead of ahead of them. Results reach the model in the order it asked for
them. A call a worker took and did not finish within the timeout is said to the model
(`ParallelTools::TOO_LONG`). The round's rows are deleted once read.

A worker has no closures. It builds the tools again (`Conversations::toolsFor()`) from the
assistant of the space, the person, the conversation and `extra`, as the answer did, and runs the
one named, as the answer's author (`Assistant::acting()`). That is why a parallel tool must not
depend on the turn's messages or write to its `bag`. A worker runs only tools marked parallel.

The analyst's tools are marked parallel. A `Client` used directly takes a runner of its own:
`$client->inParallel(fn (array $calls, callable $runHere): array => …)`, results in the order of
`$calls`.

### Subagents: a task with a context of its own

A `Subagent` is a task handed to a model with its own instructions, its own tools and nothing of
the conversation that asked. It always gives back a `SubagentResult`, and never throws.

```php
use Pramnos\Llm\Subagent;

$research = new Subagent(
    'You compare suppliers. Read their price lists and say which is cheapest for each item.',
    tools: $supplierTools,
    rounds: 8,
    client: (new Client(new Connection(null, 'research')))->withTurnBudget(maxCost: 0.10),
    artifacts: $artifacts,          // optional: lets it draft artifacts
);

$result = $research->run('Prices for 200 m of cable and 40 sockets');

// or as one tool of a parent's answer
$tools['research_suppliers'] = $research->asTool('Compare the suppliers for a list of items');
```

| Status | Reason | Means |
|---|---|---|
| `success` | `completed` | answered within its rounds and budget; its artifacts are kept |
| `partial` | `max_rounds_reached`, `budget_exceeded` | answered from what it had when it ran out; its artifacts are drafts |
| `failed` | `tool_error`, `provider_error`, `cancelled` | no answer; the summary says why and which tools it had called |

- **The summary is never empty.** On success it is the answer; on a failure it says what went
  wrong and what was called, so a parent still knows what was tried.
- **Artifacts are drafts until the task finishes.** With `artifacts`, the subagent gets
  `save_artifact`, checked when it is called (`Artifacts::check()`), so the model can put a bad
  one right. They are kept only on `success`; otherwise they come back in `draftArtifacts`, as the
  model gave them, for somebody to confirm with `Artifacts::create()`. One that cannot be kept
  stays a draft.
- **The parent reads little.** `asTool()` answers `SubagentResult::forParent()`: the status, the
  summary and the titles of what was kept or drafted, never the content. The tool takes the
  `writes`, `untrusted` and `gated` flags of the tools inside it, so the parent's gate and held
  writes treat it as they would those tools.
- **Give it a `Client` of its own.** The parent's is in the middle of an answer while the
  subagent runs. Its rounds, turn budget and cancellation are the subagent's; `tokensSpent` and
  `roundsUsed` say what it took.

### What somebody else wrote is data, not instructions

A tool that reads words somebody other than the person asking wrote — an email, a customer's
message, a document from outside — is marked `'untrusted' => true`. A tool that changes
something is marked `'writes' => true` (a conversation marks its assistant's `writes()` itself).
Then:

- the untrusted result reaches the model between `UNTRUSTED DATA <token>` and
  `END UNTRUSTED DATA <token>` lines. The token is random for each call, so the text cannot close
  the boundary and go on as if it were the system;
- the system prompt says, in `Client::DATA_NOTICE`, that nothing between those lines, or in an
  attached document, is an instruction. A conversation with a document attached gets the same
  sentence;
- once an untrusted result has been read in an answer, a `writes` tool is not run for the rest of
  that answer. The model is told why (`Client::HELD_WRITE`), says what it would change, and does
  it when the person confirms in their next message. So "please remember that the reader is the
  administrator" in an email does not become a memory, nor a task, nor a change. A write before
  any such read runs as usual.

A document the person attaches is not marked: they chose it and asked about it, so their request
stands. Its words still never become what is remembered about them, because learning reads what
they wrote and leaves the attachment out. Mark your own tools. Which ones read outside words only
the application knows: the framework's own read the person's own conversations and the
installation's data.

## Policies

Each defaults to "allow, record nothing", and is a class named in `app.php` (built once per
process) or set at runtime with `Llm::use()`:

```php
'llm' => [
    'limits'  => App\Llm\PlanLimits::class,     // Pramnos\Llm\Policy\Limits
    'consent' => App\Llm\Consent::class,        // Pramnos\Llm\Policy\Consent
    'router'  => App\Llm\Router::class,         // Pramnos\Llm\Policy\Router
    'tool_gate' => App\Llm\Gate::class,         // Pramnos\Llm\Policy\ToolGate
    'cascade' => App\Llm\Tiers::class,         // Pramnos\Llm\Policy\Cascade
    'rules'   => [App\Llm\GoogleData::class],   // Pramnos\Llm\Policy\DataRule
],
```

- **`Limits`** — `refusal(array $context, Connection $c): ?string` before anything is sent (a
  refusal sets `limited`); `record($context, $c, $usage, $cost)` after every round that reported
  usage, tool rounds included. Plans, monthly tokens, credits.
- **`Consent`** — `refusal(array $context, string $provider): ?string` before anything is sent,
  and asked again by a data rule before an answer moves (a refusal sets `consent`).
- **`Router`** — `context(array $context): array` completes the context (the request's
  organisation); `route(array $context, Connection $c): Connection` may hand back another
  connection before anything is sent: an organisation's own provider or key, a cheaper project
  for material that may go to it. Extend `Policy\DefaultRouter`, which fills in the signed-in user.
  An `organization_id` that is present and `null` means *no organisation* — the
  [analyst](#the-administrators-analyst) says it on purpose — so fill a tenant in only when the
  key is missing (`array_key_exists`, not `??`).

- **`ToolGate`** — `before(string $name, array $arguments, array $tool, array $context): ?string`
  before every tool call the model asks for. A refusal is told to the model in place of the
  result, worded as "Not done: …", and the call never runs, alone or among calls run at once.
  The default lets every call run. See [approvals given in advance](#approvals-given-in-advance).

### A model for each question: tiers

An application can answer routine questions on a cheap, fast model and the hard ones on a
stronger, dearer one. Each tier is a [feature](#features), so its provider and model are set on
the administration screen:

```php
'llm' => [
    'features' => ['chat' => 'Ask AI', 'chat_frontier' => 'Ask AI, for the hard questions'],
    'tiers'    => ['fast' => 'chat', 'frontier' => 'chat_frontier'],
    'cascade'  => \Pramnos\Llm\Policy\ModelCascade::class,
],
```

Before each question in a conversation, the `Cascade` policy names the tier it needs, and the
question is answered on that tier's connection. `ModelCascade` asks the fast tier with one short
call (`askForJson()`, `{"tier": "fast"|"frontier"}`) on the question and the four messages before
it. *fast* is for routine questions, lookups, figures and short tasks. *frontier* is for long
reasoning, delicate personal or emotional conversation, and writing where the wording matters.
Override `ModelCascade::guidance()` to say what needs the frontier in your application, or
implement `Policy\Cascade` to decide without a model.

- **The frontier tier down.** When the frontier tier cannot answer (a refusal, a provider that
  stays down after its retries and fallback), the question is answered on the fast tier. That
  tier is told it answers in the stronger model's place (`Conversations::DEGRADED`) and should
  say so if the question needs more.
- **No decision changes nothing.** With no cascade (the default `NoCascade`), with `tiers` not
  naming both, or with the fast tier unable to decide, the question goes to the assistant's own
  connection, as before.
- A `Conversations` given a `Client` of the caller's own is not cascaded: that choice is the
  caller's.
- A conversation holding data of a tag moves within the tier as it does without tiers.
- The decision is logged with the purpose `cascade`, and the tier's calls carry `tier` in their
  context.

### Approvals given in advance

`Policy\GrantGate` lets a gated call run only within a `ToolGrant`: an approval for one tool, with
typed limits on its arguments. Gated are the tools that write, and any marked `'gated' => true`.

```php
use Pramnos\Llm\Policy\GrantGate;
use Pramnos\Llm\Policy\Grants\{DomainMatcher, MaxNumberMatcher, ToolGrant};

Llm::use('tool_gate', new GrantGate([
    new ToolGrant(
        'send_email',
        ['to' => new DomainMatcher('example.com'), 'cc' => new DomainMatcher('example.com')],
        free: ['subject', 'body'],
        userId: 7,
        expiresAt: time() + 86400,
    ),
    new ToolGrant('refund', ['amount' => new MaxNumberMatcher(100.00)], free: ['order_id']),
]));
```

**Every argument is accounted for.** A call is allowed only when each argument it carries is
accepted by its matcher, or is named in `free`. An argument the grant does not name refuses the
call, so a `to` within the domain does not cover a `cc` outside it, or a `bcc` nobody thought of.
An argument the call leaves out is nothing to check. A grant holds for its tool, its person
(`userId`, or anybody) and until `expiresAt`.

The matchers read the decoded value, never a pattern over the call's JSON, which spacing, key
order or an escape would get past:

| Matcher | Accepts |
|---|---|
| `DomainMatcher('example.com', …)` | every address at one of the domains: a list, `a@x, b@y`, or `Name <a@x>`; anything that is not an address refuses |
| `MaxNumberMatcher(100.0)` | a number, or numeric text, no greater than the limit |
| `PrefixPathMatcher('/var/safe/')` | a path under the directory once `.` and `..` are worked out |
| `OneOfMatcher('draft', 'review')` | one of the values exactly, or a list of them |

A call no grant covers is told `GrantGate::NEEDS_APPROVAL`: the model says what it would do and
does it when the person confirms. An application whose grants are stored, or depend on the
person, overrides `GrantGate::grants(array $context)`; one that gates other tools overrides
`gated()`. An argument matcher of its own implements `Grants\ArgumentMatcher`.

`Llm::listen(callable)` is told about every call after it is logged — for what an application
keeps per call beside the log, such as what an article cost to write. A listener that throws is
logged and does not end the answer.

### Data rules

Some data may only go to some providers — figures from Google's APIs that an application's terms
keep away from one provider, say. A tool marks its result with a tag, always or by what it
returned:

```php
Tools::tag($tools, 'analytics_report', 'google');
Tools::tag($tools, 'articles', 'google', fn (string $result, array $args) => ($args['article_id'] ?? 0) > 0);
```

and a `Policy\DataRule` for the tag says which connections `accepts()` it and offers an
`alternative()` (asking consent for it is the rule's job). When a tagged result comes back on a
connection that does not accept it, the answer **moves** to the alternative before the result is
sent anywhere — with the output budget it started on, and without the first provider's own fields
in the history. With no alternative the model is told the rule's `refusalResult()` in place of the
result. `$answer['tags']` says which tags reached the model, so a conversation holding such data
can stay where it may go: `$client->moveFor('google')` moves before anything is asked, or
returns false when nothing may take it.

## The call log and costs

`llm_calls` holds every call — failures especially, each with a sentence in `error`. The cost is
frozen at write time from the unit prices, so correcting a price does not restate history, and
it is `null` for a model nobody priced: a total adding noughts for it would be confidently low.
`CallLog::summary()` and `byModel()` say how many calls were not priced. `llm_log = 0` stops
logging; `llm:prune` keeps 90 days. `created_at` is written and compared in UTC
(`CallLog::instant()`, which is `Database\Instant::utc()`): with its offset on PostgreSQL, and as
the bare UTC wall clock on MySQL and MariaDB, which drops an offset. Either way the windows hold
whatever zone PHP or the database session is in, and a value read back is UTC.

Prices are USD per million tokens in three buckets — `cache_hit`, `cache_miss`, `output` —
because a cached token can cost a fiftieth of an uncached one; an entry may add `off_peak` with
`peak_utc` hours. They are a setting (`llm_prices`), edited on `/admin/Ai/settings` and seeded from
`Pricing::SEED`. Costs are estimates and the screen says so; `Billing\OpenAiCosts` reads what
OpenAI actually billed, by project, with an Admin key, and works out the balance from the top-ups
recorded beside it (`new OpenAiCosts(projects: ['llm_openai_other_project'])` reports more
projects).

## The administration screen

`/admin/Ai/settings` (ability `admin.ai`, usertype 90) is the one place these settings live: the provider
in use, each provider's key, model and parameters, a key test, the features, the prices, OpenAI
billing, and the call log with the problems on their own. An application puts its own settings
on the same screen rather than a second one beside it:

```php
'llm' => ['admin_sections' => [App\Llm\PlanLimitsSection::class]],
```

A `Pramnos\Llm\Admin\Section` has a `key()` and a `title()`, `render()`s its fields (escaped HTML,
no `<form>` and no button — the screen adds both, with the session's token), and `save(array
$input): ?string` stores what was posted, answering what is wrong or `null`. A section whose
`render()` throws shows the error in its card instead of taking the screen down. A card that only
shows something — a month's spend per plan — implements `Pramnos\Llm\Admin\Card` instead: `key()`,
`title()` and `render()`, declared in the same list, drawn with no form and no Save button.

## Conversations

`Pramnos\Llm\Chat` keeps conversations: what was asked, what the model answered, the tools each
answer called and what it cost, what it remembers about each person, questions waiting in the
queue, and questions asked again on a schedule. What a conversation is *about* — its prompt, its
tools, what an answer must have done — is the application's, in an **assistant**:

```php
// app/app.php
'llm' => ['chat' => [
    'assistant' => App\Llm\Analyst::class,   // Pramnos\Llm\Chat\Assistant
    'memory'    => ['max' => 40, 'chars' => 300],
]],
```

```php
use Pramnos\Llm\Chat\Chat;

$chat   = Chat::conversations();               // the configured assistant, as the person signed in
$answer = $chat->ask(null, 'How did last week go?');

if ($answer['ok']) {
    $id = $answer['conversation_id'];          // continue it with $chat->ask($id, '…')
} else {
    echo $answer['says'];                      // why: scope, empty, missing, tags, consent, limit, provider
}

$chat->list();          // this person's conversations, latest first
$chat->find($id);       // one, with its messages, as the assistant presents them
$chat->delete($id);
```

The tables are `chat_conversations`, `chat_messages`, `chat_memory`, `chat_asks`, `ai_consents` and
`ai_routines`, with the installation's table prefix (`#PREFIX#chat_conversations` in a query of
your own). A table that already exists under its name is left as it is, except
for what the framework needs to write to it: `tags`, `extra` and `space` are added, and an
`organization_id` that refuses a null is made to take one — a conversation outside any
organisation, the analyst's always, is written with none. The `llm_tables` health check says
when an application's own tables still differ. 

### Whose conversations

A `Chat\Scope` is one person and, for an application that has them, the organisation they act
in. `Scope::current()` reads the request through the router's `context()`; a queued or scheduled
run builds it from its row. Inside an organisation only that organisation's rows are seen, and
outside one only the rows without one — a conversation written outside an organisation never
shows inside one.

### The assistant

Extend `Chat\BaseAssistant`, which answers every hook with a default:

| hook | |
|---|---|
| `connection(Scope)` | the connection answers go out on — the `chat` feature's by default |
| `systemPrompt(Turn)`, `context(Turn)` | the instructions, the same for every question; and what the model is told about the person, with what it remembers added after |
| `volatileContext(Turn)` | what is true only for this question, such as the page the person is on, sent just before it |
| `tools(Turn)` | this answer's tools; the memory's and the schedule's are added by the framework |
| `due(Turn)`, `verify(Turn)` | what an answer must end with, and what it is checked against — see `askWithTools()` |
| `writes()` | the tools that change something: a later answer is told which an earlier one called |
| `question(Turn, $q)` | the question as the model reads it, with what the caller passed in `extra` worked in |
| `answered(Turn, $message)` | after it is stored: attach what the answer produced, and decorate the message |
| `present(Scope, $messages)` | stored messages, decorated for the screen |
| `acting(Scope, $run)` | a queued or scheduled answer, run inside whatever the application's tools need set up |
| `heldRefusal($tag)` | said when a conversation holds data of a tag nowhere available may take |
| `routineRefusal(Scope, $adding)` | why this person may not have scheduled questions, or null — `$adding` true when one more becomes active (created or resumed), where a limit on active ones belongs |
| `timezone(Scope)` | the clock "every morning at 8" is meant on — the server's by default |
| `conversationUrl($id)` | where a conversation is read, for a notification |
| `rounds()`, `turns()` | tool rounds per answer (16), turns sent back (30) |

A `Chat\Turn` carries the scope, the conversation, whether it is a scheduled run, the messages,
the documents attached in the conversation, the caller's `extra`, and `bag` — the application's,
for the length of the turn: what `question()` puts in it reaches `tools()` and `answered()`.

A turn sends back the conversation, never starting on an answer, each earlier answer
followed by a note of the tools that wrote in it; without the note, a later answer sees no result
for a change it made and says it changed nothing. A note the model copies into its reply is taken
out. A conversation whose earlier answers carried data of a tag (`tags`, see
[Data rules](#data-rules)) is moved to a connection that may take it before anything is asked,
or refused with `heldRefusal()`. Only an answered turn is stored — question and answer together.

**A long conversation is summarised, not cut.** Once it passes `turns()` turns and half as many
again, its older part — whole exchanges, never an answer without its question — is folded into a
summary by one short call, kept on the conversation (`summary`, up to `summary_upto`), and sent
with the system prompt; what came after is sent as it was said. So a summary costs one call every
few turns, not every turn. **Or by size:** when the last answer left the window more than
`Conversations::FULL` (60%) full, all but the last exchange are folded, however few the messages,
because a handful of long ones fill it as surely as many short ones. A summary that cannot be written marks nothing: that turn sends the last
`turns()` only, and the next one tries again. `'llm' => ['chat' => ['summarize' => false]]` sends
the last `turns()` and leaves the start out.

**Housekeeping runs on the cheapest model.** The summary and memory learning go through
`Client::askCheaply()`: the cheapest model in the provider's list that has prices
([the call log and costs](#the-call-log-and-costs)), on the same provider and key; where that model fails, the call is asked again
on the connection's own.

An attachment is a document's words: `ask(…, attachment: ['name' => 'release.docx', 'text' => …])`.
`Pramnos\Llm\DocumentText::from($name, $bytes)` reads `.docx`, `.odt`, `.txt`, `.md` and `.eml`
(the email's subject, sender and words, and the documents it carries); `pictures()` takes a
document's photos, and `keepPictures($name, $bytes, $store)` keeps them through
`$store(string $path, string $filename): ?string` — the application's media library — and lists
their addresses after the words.

### The context, in three zones

`Chat\ContextAssembler` lays an answer's context out so a provider's prompt cache keeps as much
of it as it can from one question to the next:

| Zone | Sent as | Holds | Changes |
|---|---|---|---|
| 1, static | the system prompt | `systemPrompt(Turn)` | with the code only |
| 2, semi-static | a system message ahead of the history | `context(Turn)`, what is remembered, what is pinned, the summary | when one of those does |
| 3, dynamic | a system message just before the question | `Now: Friday 30 October 2026, 14:05 (Europe/Athens)`, `volatileContext(Turn)`, the notice for an attached document | every question |

A cache matches a request from its start. With the time and the page last, two questions in a
row share everything up to the one before. With them in the system prompt they would share
nothing, and every question would pay for its whole context again. So an assistant's
`systemPrompt()` holds nothing that changes between questions: no date, no time, no page, no
count. Those belong in `volatileContext()`. The time is added for every assistant, on its
`timezone()`.

Two checks watch it, both logged to the `llm` log:

- **The static prompt.** `checkPrefix()` keeps the hash of zone 1 per conversation. When it
  changes between two questions while neither the assistant's class nor the `llm` settings did,
  it logs `Cache invalidation: …` with the first line that differs, before the call is sent.
- **The hit ratio.** `cacheReport()` reads how much of an answer's prompt the provider served
  from its cache (DeepSeek's `prompt_cache_hit_tokens`, or OpenAI's `cached_tokens`). It logs
  when a question that followed others got less than `ContextAssembler::HIT_RATIO` (80%) of
  it. A prompt under `CACHE_FLOOR` tokens is not cached by providers and is not reported.

Changes made during an answer, such as an artifact updated, reach the model as that tool's result
and are in zone 2 from the next question. Moving zone 2 in the middle of an answer would break the
cache.

### Memory

The model is given `remember`, `forget`, `search_conversations` and `read_conversation`, and told
what it remembers in every prompt, each memory dated. At most `memory.max` entries of
`memory.chars` characters. `Chat\Memory` lists and forgets them for a screen.

**A memory is corrected, not doubled.** When what the person says changes something remembered,
the model passes its id as `replaces` and the memory is rewritten — the latest word wins, rather
than two memories disagreeing. In code, `Memory::keep($kind, $content, $replaces, $about,
$source)`; `remember()` is the same for a new one.

**Learning, in batches** — on by default; `'llm' => ['chat' => ['memory' => ['learn' => false]]]`
turns it off. A short call reads what the person said beside what is remembered, and answers a list
of changes: keep what they *stated* about themselves, their role or their work, or how they want
answers; correct what changed; forget what they took back. Not after every answer: once
`learn_every` (5) of the person's messages have gathered in a conversation, or when it has been
quiet for `learn_idle` seconds (900) — `llm:routines`, every minute, looks for those
(`Conversations::learnQuiet()`). The conversation remembers how far it was read (`learned_upto`);
an answer others read, a scheduled run and a conversation opened from a report are marked read, not
learned from, and so is one whose data no connection may take. At most ten changes a pass, within
the limits; what it cannot read changes nothing, and a failure never fails the answer. What it
keeps is marked `learned` and shown so on the memory list, where the person forgets it like any
other.

**What the person asks to be remembered is remembered at once.** "Remember that I moved to
Athens", "forget that": the model calls `remember` or `forget` in the same answer, with no batch to
wait for and no second call.

**An organisation's memory** — on by default (`'memory' => ['organization' => false]` turns it
off; `organization_max`, 40). Inside an organisation, the prompt also carries what the organisation
keeps about its shared work, read by everybody in that organisation and space and shown apart from
the person's own. **Read by all, written by few:** only an account that `Memory::mayShare()` — a
root account, or one given the `admin.ai.memory` ability — keeps, corrects or forgets it, and only
when it asks to (`remember` with `about: organization`, which is not even offered to anybody else).
Learning never writes it: one person's words, picked up without being asked, would otherwise reach
every prompt in the organisation. Outside an organisation there is nobody to share with, and the
option has no effect. Searching is case-insensitive; on
MySQL it also ignores accents, as its collation does, and on PostgreSQL it does not.

### Artifacts: tables, lists and texts kept from an answer

The model is given `save_artifact`, and uses it when an answer is a table of more than a few
rows, a list of records the person may want to act on, or a document they asked for. It gives
the data itself, not Markdown to be read back out of its answer:

| kind | fields | |
|---|---|---|
| `table` | `columns`, `rows` | up to 50 columns and 1,000 rows; a short row is padded, an object cell kept as JSON |
| `list` | `entity`, `ids`, `labels` | what the ids are (`user`, `device`…), up to 10,000 of them, each once; a label per id or none |
| `text` | `text` | Markdown, up to 50,000 characters |
| `chart` | `chart_type` (bar, line, pie, doughnut), `labels`, `series` (`label`, `data`) | up to 100 labels and 10 series, one number per label; drawn with `Html\Chart` and a table of the numbers |

What does not fit is refused with the reason, so the model can correct itself. An artifact is
kept in `chat_artifacts`, linked to the conversation and the answer it came with, carrying the
data tags the conversation held; the answer comes back with it as `artifacts`, on `ask()` and on
`find()`. It goes with its conversation, and with its owner's account on erasure. A scheduled
run is offered no such tool: it has no conversation to keep one in.

`Chat\Artifacts` is the same in code, within a `Scope` — a person reaches only their own:

```php
$artifacts = new \Pramnos\Llm\Chat\Artifacts($scope);
$made = $artifacts->create(['kind' => 'list', 'title' => 'Dormant buyers', 'entity' => 'user', 'ids' => [4, 9]]);
$artifacts->find($made['artifact_id']);   // with its content, or null when not theirs
$artifacts->list();                        // newest first, without content
$artifacts->delete($made['artifact_id']);
```

The page and the SPA component draw each one under its answer — a table, a list's first twenty
entries and how many more, a text as Markdown — each with `data-pf-artifact` and its id.
`Chat\Web\ArtifactView::html($artifact, $frameClass, $tableClass, $mutedClass)` is that markup,
escaped, for a view of an application's own. Without the table (migrations not run) there is no
tool and nothing to read, and the health check names it.

**Download, print, pin.** Under each artifact: *Download CSV* for a table, a list or a chart's
numbers — UTF-8 with a byte-order mark, so a spreadsheet opens it as it is, and a cell that starts
like a formula written as text, so a value somebody typed cannot run in the program that opens it;
*Print or save as PDF*, the artifact on a page of its own for the browser's print dialog; and *Pin
to dashboard*, which puts it on the person's **Pinned from Ask** card (`ai.pinned`), up to twelve,
as it was kept. `Artifacts::csv()`, `Artifacts::filename()`, `->pin($id, $on)`,
`Artifacts::pinned($user)` are the same in code. A pinned artifact is the result as it stood, not a
query run again: a figure that should stay current is a scheduled report.

**A workspace across conversations.** Besides `save_artifact`, the model is given tools that
work on what this person kept earlier, from any conversation:

| Tool | Does |
|---|---|
| `read_artifact` | reads one by `id`, or by `title`, preferring an exact title to one that only contains the words; at most `Artifacts::READ` characters |
| `update_artifact` | changes one: `mode` `replace` (its kind's fields anew), `append` (table rows, list ids and labels, or text at the end), or `patch` (a new `title`; `find`/`replace` in a text, exactly once; `remove` ids from a list; `remove_rows` from a table, numbered from 1) |
| `list_artifacts` | finds them by `kind`, `entity`, `tag` or words in the title (`query`), newest change first |
| `pin_artifact` | pins one, or takes it off (`pinned: false`) |

A change is checked whole, against the same limits as a new artifact. A list that would name an
id twice, or a table past 1,000 rows, is refused with the reason and left as it was. A chart is
changed only whole (`replace`), the entity of a list stays what it was, and the framework's own
kinds, such as a proposal, are not changed here. `->update($id, $mode, $input)`,
`->search($filters, $limit)` and `Artifacts::asText($artifact, $limit)` are the same in code.

**A pinned artifact is in front of the model in every question.** What this person pinned in
this space goes into the context of each question as it stands at that moment, after what is
remembered about them. Each pinned artifact gets up to `Artifacts::PINNED_EACH` characters and
all of them together up to `PINNED_ALL`, each with its id, so the model can read one whole. A
change made during an answer reaches the model as that tool's result, and is in the context from
the next question on. Changing the context in the middle of an answer would break the cached
prompt, so the next question is when it shows.

**Acting on a list.** A list's records can be acted on in bulk — put the people in a user group,
or whatever the application declared for that kind of record — from under the list on the page
and in the SPA component, or `GET /chat/artifacts/{id}/actions` and
`POST /chat/artifacts/{id}/actions/{key}` with `params`. What is offered, and who may run it, is
[Bulk actions](Pramnos_Bulk_Actions_Guide.md).

### Rating answers, and the queue of bad ones

Under every answer, on the page and in the SPA component, the person rates it 👍 or 👎. Pressing the
chosen one again takes it back, and rating again replaces it. The rating comes back on each message
as `rating` (1, -1 or null).

A bad answer carries a note of what was wrong, asked for after 👎 is pressed. On the page the first
press shows the note beside the button, and the next press, or Enter in the note, sends it. The
note is on view from the start when the page runs without JavaScript. The SPA asks for it in a
prompt.

On the page and in the side panel, `pf-chat.js` sends a rating in the background: the buttons
change in place, the conversation keeps its scroll position, and "✓ Thank you — noted." (or the
refusal) shows beside them for a moment. The action `rate/{id}` answers a request with
`Accept: application/json` with `{ok: true, rating, message}`, or `{ok: false, error}` and a 422.
Without the script, or when the answer is not JSON, the form posts and the page comes back with
the message flashed. Each form carries `data-pf-rating-value` (1 or -1), so the script knows what
a button means once its hidden `rating` holds 0, which takes the rating back. An application with
its own copy of `pf-chat.js` refreshes it with `php bin/pramnos project:resync --js`.

A bad answer waits in **Answers to review** (`/admin/Ai/reviews`, linked from the analyst's ⚙
menu) with the question it answered and the note, until a reviewer marks it reviewed — recorded in
the audit log as `ai.answer_reviewed`. A new verdict puts it back. The screen is for whoever reads
everybody's Ask conversations (`admin.ai.all`), since it shows their questions, and opens an
analyst conversation read only. `Chat\Ratings` is the same in code: `rate($scope, $messageId,
$rating, $note)`, `queue()`, `reviewed($messageId, $reviewer)`, `summary($days)`.

### Changes the model proposes, and a person approves

**Off by default.** `'llm' => ['chat' => ['propose_actions' => true]]` turns it on. Then the model
is given two tools, and only when the person has something they may change:

- `list_actions` — what this person may do, by kind of record: the
  [bulk actions](Pramnos_Bulk_Actions_Guide.md) the application declared and they are allowed;
- `propose_action` — one of them, on records by id, with its fields and a one-line reason.

A proposal **changes nothing**. It is kept as an artifact of kind `proposal` under the answer,
showing the action, how many records, its fields and the reason, with *Approve and do it* and
*Reject*. Approving runs it through `BulkActions::run()` as the person approving — their right to
it asked again, the audit log written, a long list queued — once; the artifact then shows what came
of it. Rejecting leaves it marked rejected. Only its owner settles it, and a scheduled run is
never given these tools, since nobody is there to approve.

What may be proposed is exactly what the application declared as a bulk action, so one
declaration serves list screens, Ask lists and the model alike. In code:
`(new Chat\Proposals($scope))->approve($artifactId)` / `->reject($artifactId)`; on a page of the
application's own, the `ActsOnLists` trait has the `approve/{id}` and `reject/{id}` actions and
`Web\BulkView::decision()` the buttons.

### Sharing a conversation or an artifact

A person shares one of their conversations or artifacts with others by username or email —
`Share` on the conversation page, under the open conversation and under each artifact, or the
API. The recipient is told (the bell, and a push where they have one), and finds it under
"Shared with you".

The analyst's conversations and artifacts go only to people at or above the analyst's floor.
Anybody below it is answered as somebody who does not exist ("Nobody here is called …"), so the
share neither tells them a conversation exists nor names its title.

In the analyst's space, the *With* field and a report's readers field suggest people as a name is
typed, asked from the API each time. The page names the address (`data-pf-people-url`, the
`people` action, or `GET /chat/people?q=` for a SPA). `SharePolicy::candidates($scope, $term)`
answers the active accounts at or above the floor whose username, email or name holds the term,
minus the person sharing, ten at most. `pf-utils.js` asks for the name after the last comma once
it is two characters long, after a 300 ms pause. A newer request cancels the one before it, an
answer is kept for its term, and an answer is shown only while the field still says that term.
Typing a whole name is one request. A person's own Ask suggests nobody, because there the
accounts are the site's users and a list of them is not one user's to read. An application whose
Ask is used among colleagues overrides `candidates()`.

**A conversation is shared as it stands.** The recipient sees the messages up to the moment it
was shared, not what is said after; sharing it again with the same person moves that point on.

**The recipient opens it by taking a copy.** The snapshot becomes a conversation of their own —
carrying the original's data tags, so a provider that may not receive that data is still not
asked about it, and each answer's artifacts — which they read on the ordinary screens and can go
on asking in, with their own rights. An artifact arrives the same way, inside a conversation of
its own. Opening it again returns to that copy. Withdrawing a share, or deleting what was
shared, stops it; a copy already taken stays the recipient's.

What a conversation shows was read with the rights of the person who asked, and the person
sharing chooses who sees it. `Chat\SharePolicy`, or the class `chat.share_policy` names, is where
an application narrows that:

| Method | Default |
|---|---|
| `refusal($scope)` | null: anybody signed in may share |
| `recipients($scope, $names)` | `name => userid` for each username or email that is somebody, never the person sharing |
| `link($share)` | `chat/shared/{id}`, or `Ai/shared/{id}` in the administration area for the analyst's |

`chat.share_notification` names a notification class of the application's own, constructed with
the words and the link. `Chat\Shares` is the same in code:

```php
$shares = new \Pramnos\Llm\Chat\Shares();
$shares->share($scope, Shares::CONVERSATION, $conversationId, ['maria', 'nikos@example.com']);
// ['shared' => ['maria'], 'unknown' => ['nikos@example.com']], or why not
$shares->sharesOf($scope, Shares::ARTIFACT, $artifactId);   // who has it
$shares->revoke($scope, $shareId);
$shares->withMe($userId);                                   // what others shared
$conversationId = $shares->open($shareId, $conversations->as($viewerScope));
```

A page of an application's own gets the actions with `Chat\Web\SharesConversations`
(`share/{id}`, `unshare/{share}`, `shared/{share}`) and the markup with `Chat\Web\ShareView`.

### Asking in the background

An answer with tools can take a minute. `Chat\Asks::start($scope, $conversationId, $question,
$attachment, $extra)` writes a waiting row and queues a `chat_ask` task, which the queue worker
answers without being registered — as its author, through `acting()` — and marks answered or
failed. The screen follows it with `find($scope, $askId)`. `Asks::onAnswered(callable)` is told
each answer with the job, `extra` included — where else the answer should go. A listener is
registered in the process that answers, so one a provider registers is missing from a worker that
boots no providers. An ask with `extra['conversations']` needs none: `run()` posts it back to its
conversation itself, once per ask.

While the worker writes the answer, `run()` streams it (`Conversations::streaming()`) and writes
the text so far to `chat_asks.partial`, at most every `Asks::PARTIAL_EVERY` seconds (0.5).
`find()` returns it as `partial`, and both screens show it as it grows: `pf-chat.js` and the SPA
look at the ask every 700 ms while there is text, instead of every few seconds. Once the ask is
answered or fails, the column is emptied, because the answer is kept with the conversation.
Without the column (migration `2026_10_29_000001_add_partial_to_chat_asks` not run), nothing is
streamed, and the answer arrives whole, as before. A summary folded or a memory learned beside the
answer is never streamed.

#### Stopping an answer

`Asks::cancel($scope, $askId)` stops a question that is still `waiting`: the row becomes
`cancelled`, and the worker, which looks before every round and before every tool
(`Conversations::stoppable()`, `Client::cancelWhen()`), stops there. A question stopped before the
worker reaches it is never sent to a provider; one stopped mid-answer pays for the call already
under way and no more. Nothing is kept: neither the question nor a part of the answer goes into a
conversation. `cancel()` is false for a question that is not this person's, or no longer waiting.

Both screens draw a Stop button while they follow a question. `pf-chat.js` posts to the page's
`cancel/{id}` (the `asked/` address with its last word changed, so the conversations page and the
analyst's page under `/admin/Ai` need nothing in their templates), with the form's hidden fields
for the session's token. The SPA calls `chatClient().cancelAsk(id)`, and `waitForAnswer()`
returns `{status: 'cancelled'}`. The button's words are the view's `stop` and `stopped`.

A `Client` used directly takes the same hook:

```php
$answer = $client->cancelWhen(fn (): bool => $stopRequested())->askWithTools(...);
// stopped: ['ok' => false, 'cancelled' => true, 'says' => Client::STOPPED, 'usage' => …, 'calls' => …]
```

### Scheduled tasks: questions, reminders, reports

`Chat\Routines` keeps every scheduled AI task in `ai_routines`. There are three kinds:

- **`ask`**, a question asked again into its owner's conversation;
- **`reminder`**, the owner's own words sent back as a notification with no model asked, so it
  costs nothing;
- **`report`**, asked without a conversation, with read-only tools, kept as a run and sent to its
  readers — see [Shared reports](#shared-reports).

```php
$tasks = new \Pramnos\Llm\Chat\Routines(new \Pramnos\Llm\Chat\Conversations(\Pramnos\Llm\Chat\Chat::assistant()));
$tasks->create($scope, ['title' => 'Morning', 'prompt' => 'What should I know today?', 'cadence' => 'daily', 'at' => '08:00']);
$tasks->create($scope, ['kind' => 'reminder', 'title' => 'Invoice', 'prompt' => 'Send the invoice to Acme',
    'cadence' => 'once', 'in_minutes' => 60, 'conversation_id' => $asked]);
$tasks->create($scope, ['title' => 'Board pack', 'prompt' => '…', 'cadence' => 'monthly_weekday', 'nth' => 2, 'day' => 2, 'at' => '09:00']);
```

**Cadences:**

| Cadence | When |
|---|---|
| `daily` | Every day at `at` |
| `weekly` | Every week, on `day` 1 (Monday) to 7 (Sunday) |
| `monthly` | Every month, on `day` 1 to 28 |
| `monthly_weekday` | The `nth` (1–4, or 5 for the last) `day` of the month: the second Tuesday, the last Friday |
| `once` | At `run_at` (`YYYY-MM-DD HH:MM`), or `in_minutes` from now |

Times are on the task's clock, its `timezone`. A `run_at` is read on the asker's clock, and one
that has passed is refused. A template in `chat.routine_templates` fills in what a request leaves
out. `mine()`, `find()`, `setActive()` and `delete()` act within a person's scope. `runs($id)` is
a task's history: each run's outcome, content, cost and conversation, newest first.

**What the model can do.** In a conversation, never in a scheduled run (a task that schedules
tasks has no end), the model has four tools:

| Tool | What it does |
|---|---|
| `schedule_task` | A question asked again, or a reminder ("remind me in an hour about X": `cadence` `once`, `in_minutes` 60), linked to the conversation it was asked in, a first question's too, once that conversation exists (`Routines::linkMade()`). `kind` is required, and a call without it is refused: a model that left it out once turned a reminder into a question that cost a call |
| `update_scheduled_task` | Change one by id: its words, time or cadence, or pause and resume it |
| `list_scheduled_tasks` | This person's tasks, with when each runs next and any that stopped and why |
| `cancel_scheduled_task` | Delete one by id |
| `list_reports` | The shared reports here this person may receive, and whether they do |
| `subscribe_report`, `unsubscribe_report` | Receive a shared report by id, or stop. `email: true` sends the report itself by email too ("send it to me by email"); asked again, it turns email on or off on the existing subscription |

`schedule_task` also makes a report (`kind` `report`), shared with the organisation or the
installation (`owner`) when the person asks and the policy lets them, and told only when there is
something new (`notify` `relevant`).

**A duplicate is refused by the tool itself.** A task that already does the same thing (same
kind, same words or title, same time) comes back as an error that names its id and points to
`update_scheduled_task`. A description asking the model not to duplicate is one it can ignore.
`Routines::update($scope, $id, $changes)` is the same change in code, and `PUT …/routines/{id}`
in the chat API takes the same fields.

**How runs happen.** `llm:routines` runs every minute. A task runs with its own space's
assistant: the analyst's (space `admin`) with the analyst, not with the chat assistant the tick
was built with.

1. It **claims** what is due. One statement moves the next run on and holds the task, only if
   the task is still due as it was read, so a second tick or a second host claims nothing and no
   task runs twice.
2. It runs what it claimed there and then. With `'llm' => ['chat' => ['task_queue' => true]]` it
   queues each one instead, and a worker runs it, so a report that reads for a minute holds up no
   other.

**"Run now"** — the scheduled page's button, or `POST /chat/routines/{id}/run` — goes to the
worker, as a question in Ask does, whatever `task_queue` says (`Routines::runNow()`). The API
answers 202. The task is held as a claimed run is (`locked_until`), and says `running` until that
run has finished: the page shows "Running…" and offers no second run. The server-rendered page
reloads every 15 seconds while one is running, and the SPA reads the list again. A second press
meanwhile is refused ("It is already running."), and so is a run the policy would refuse, at once
rather than from the worker. Without a queue worker, `'llm' => ['chat' => ['answer_in_request' =>
true]]` runs it in the request. A queue that will not take it also gets the run in the request,
rather than none.

After each run:

- The run is recorded in `ai_task_runs` and the owner is told.
- A question tells the owner with `Chat\RoutineReady`, or the class `chat.routine_notification`
  names, constructed with the title, whether it worked, why not, and the link.
- A report's reader whose subscription asks for email (`subscribe($userId, $id, email: true)`,
  "Receive it by email too" on the scheduled page, `email` on the chat API's subscribe, or asking
  for it in Ask) gets the report itself by email:
  `RoutineReady::toMail()`, with its title, its words drawn from Markdown, and the link to ask
  about it. The notification class is given the report's words and that choice as its fifth and
  sixth arguments, and an application's own class written for four may ignore them. A failed run
  sends no email.
- Every model call a scheduled run makes is marked with its task, in `llm_calls.routine_id`. The
  analyst's spend shows the tasks' runs apart from conversations. The purpose stays the space's,
  because it is what routes the call.
- A reminder tells the owner with `Chat\TaskReminder`, or `chat.reminder_notification`, given the
  words and the link.
- Both go to the bell, and as a push where the owner has a browser subscribed. `'llm' => ['chat'
  => ['notify_via' => ['database']]]` keeps them to the bell; the list names the channels allowed
  (`database`, `push`).
- A one-off is done once it ran.
- A notification's link that was built with no site address, as in a cron run with no `APP_URL`,
  starts from the site's root rather than from the page the notification is opened on.
- Deleting a task removes its readers and the record of its runs.
- Times read back from the database are read as UTC, which is how they are written. MySQL answers
  them without an offset, so `next_run_local` showed a time shifted by PHP's zone.
  `Routines::utc()` does the reading.
- Three failed runs in a row pause a task, and the owner is told why. Resuming it starts the
  count again.

The question a run asks carries the day on the owner's clock.

**Who may read a shared report.** A report carries what was read with its maker's rights, so in
the administrators' space it is never sent to — nor readable by — somebody below the analyst's floor
(`'llm' => ['analyst' => ['floor' => 98]]`, else the administration's default usertype), whatever
its audience names, and the form offers only roles at or above it. `TaskPolicy::readerFloor($task)`
is where an application gated otherwise answers; Ask AI's own reports have no floor.

`'llm' => ['analyst' => ['reports_below_floor' => true]]` lets an application send analyst reports
lower: the form then offers every role, and a reader below the floor — somebody who cannot open
Ask — reads each run **read only**: its link opens the report as it was written, with nothing to
ask and nothing copied into an Ask they cannot use. A conversation or artifact shared with such a
person reads the same way (`Shares::snapshot()`), instead of becoming a conversation of their own.

**A report's email** carries the day it ran in its subject (`Weekly figures — 2026-10-28`), on
the task's clock, so a mailbox of daily reports with one title tells them apart:
`'llm' => ['chat' => ['report_date_format' => 'd/m/Y']]` sets the format, `''` leaves it out.
The day reaches the notification class as its seventh argument; a class of the application's own
written for six ignores it. A report can be emailed from the start: *Email the report to me too*
on the form (ticked), or `email: true` to `schedule_task`.

A chart in a report reaches the email as a small table of its labels and numbers
(`Markdown::forEmail()`): a mail client runs no script to draw it.

Each report's email has its own unsubscribe list, `askreport-{id}`: the link in it stops that
report's email and no other, and is the same switch as *Stop the email* on the page — turning the
email back on from the page, the form or Ask works at once.

**One's own report by email.** The Scheduled page offers *Email it to me* / *Stop the email* on each
of the person's own reports, as it offers *Receive it by email too* on a shared one (`mine()`
carries `email` per task). Where an application shows no notifications — no bell — a run's
database notification is never seen, and email is the only way its owner hears of it.

The schedule form shows only what applies (`data-pf-show-when`; everything without JavaScript):
the time fields for the cadence chosen, *For*, *Tell readers* and the email box for a report, the
readers for a report shared with an organisation or everyone.

**What the tasks cost** is read from `ai_task_runs.cost`, the price of each run's model calls
recorded once however many people receive the report (`Chat\TaskCosts`). It shows in two
places with nothing to configure:

- on `/admin/Ai`, a card listing each task's runs and cost over the last 30 days and their
  share of everything the call log priced, once any scheduled task has run;
- on the open task's card of the Scheduled page, its runs, total and each run's cost. A
  controller's `scheduledExtras()` that returns `task` markup replaces it.

`TaskCosts::byTask($since)` gives the same figures to an application's own screen. A run whose
calls were not priced is counted apart, not as zero; a reminder asks no model and costs nothing.

**`Chat\TaskPolicy`**, or the class `chat.task_policy` names, holds the rules around tasks. Every
method has a default, so an application overrides only what it has:

| Method | Default |
|---|---|
| `refusal($scope, $kind, $adding)` | The assistant's `routineRefusal()`. A reminder is asked as a run, never as adding, so it does not count against a limit on active tasks. An application that offers reminders without scheduled questions returns null for them |
| `scope($task)` | The maker, in the organisation and space it was made in |
| `timezone($task)` | The zone it was made in; a task about a site returns that site's |
| `stillAllowed($task)` | `true`. An application with organisations checks the maker is still a member: false pauses the task and tells its maker |
| `canManage($scope, $owner)` | `user` always; `organization` inside an organisation at usertype 80 or more; `global` at 90 or more |
| `canSee($userId, $task)` | Whether a person may read a shared report and subscribe: its maker; for an organisation's, a `member()` of it; then the readers its maker chose (`audience`), or — when none were chosen — a usertype no lower than the maker's, because a report runs with the maker's rights |
| `audienceUsers($names)`, `audienceNames($ids)` | The named readers: found by username or email (`name => userid`), and shown back by username |
| `member($userId, $orgId)` | `true`. An application with organisations answers here |
| `audience($task)` | The maker, and each subscriber `canSee()` still allows, checked at every run |
| `resourceLabel($task)` | `''`. What a task is about as people read it ("GeekDom", not "property 42"), for the notification's title and the report's question |
| `resourceExists($task)` | `true`. False pauses a report and tells its maker, without counting a failure |
| `link($task, $run)` | A report's run: `chat/report/{run}`. Otherwise the conversation |
| `onRan($task, $run)` | Nothing. For a message in another chat, or for charging; what it throws is logged |
| `maxFailures()` | 3 |

#### Shared reports

A report is a task of `kind` `report`. Its `owner` says who may read it:

| `owner` | Readers | Who may make one (default) |
|---|---|---|
| `user` | Its maker | Anybody |
| `organization` | Members of its organisation, by subscription | usertype 80 or more, inside an organisation |
| `global` | The whole installation, by subscription | usertype 90 or more |

```php
$tasks->create($scope, ['kind' => 'report', 'owner' => 'organization', 'notify' => 'relevant',
    'title' => 'Weekly bookings', 'prompt' => 'Bookings this week against last week, by property',
    'cadence' => 'weekly', 'day' => 1, 'at' => '08:00', 'resource_type' => 'property', 'resource_id' => 42]);
$tasks->subscribe($userId, $reportId);           // null, or why not
$tasks->reports($scope);                          // the shared reports here, with `subscribed` and `latest_run`
```

**How a report runs.** `Conversations::report()` asks once, as its maker, and keeps nothing as a
conversation:

- The assistant's write tools (`Assistant::writes()`), the memory and the scheduling tools are
  left out. What the maker told the assistant about themselves is not in the prompt either, since
  others read the result.
- The run is kept in `ai_task_runs`: content, cost, the model's steps, and who was told.
- The run is charged once, however many people read it.
- A failure tells the maker only. Readers get reports, not errors.

**Told only when it matters.** With `notify` `relevant`, the model decides through a
`report_relevance` tool. It is forced on the round before the last, because a decision asked for
only in words is one a model forgets. A run that decides there is nothing new tells nobody and is
still kept. A run with no decision tells everyone, so a report is never silent by omission.

**Who reads it.** Readers are re-checked at every run through `audience()`, so a member who lost
the right stops receiving it. The default `canSee()` is strict: a reader of lower standing than
the maker would see what the maker's rights read. An application with roles says here who may
read, such as the readers of the property the report is about.

**The maker chooses.** A shared report can name its readers, kept in `ai_routines.audience`:
everyone from a role up, people by name, or both. They are still only inside the organisation for
an organisation's report. The choice opens the report to people below the maker's standing, on
purpose: they read what the maker's rights read.

```php
$tasks->create($scope, ['kind' => 'report', 'owner' => 'global', … ,
    'audience_role'  => 'admin',                 // a role, as app.php's `roles` sets it
    'audience_users' => 'maria, nikos@example.com', // usernames or emails
]);
$tasks->create($scope, [… , 'audience' => ['min_usertype' => 90, 'users' => [7, 12]]]);
$tasks->update($scope, $id, ['audience_role' => '', 'audience_users' => '']);   // back to the default
```

The scheduled page offers both fields beside "For" when a report is shared, and the open report's
card lets its maker change them (`scheduledupdate` with `audience`). One person's task has no
readers to choose.

**Asking about a report.** A report's notification links to `chat/report/{run}`
(`ChatPageController::report()`, or `POST …/runs/{id}/continue`). That opens the run as a
conversation of the reader's own, holding the report, through `Routines::continueRun($viewer,
$runId)`. What they ask next runs with their own rights, not the maker's. Opening the same run
again continues the same conversation while it exists (kept per reader in the run's
`extra.opened`).

**Personal data.** `Routines::forUser($userId)` answers what is kept about a person: the tasks
they made, everywhere, and the reports they read.

**When a member leaves.** `ownedBy($orgId, $userId)` lists what a person has in an organisation:
their tasks, and the shared tasks they read. `transfer($orgId, $from, $to, $only = null)` hands
those tasks to another member:

- Each task keeps its schedule and history, but not its conversation.
- Each is checked again as the new owner's, and one they may not run stays paused, saying why.
- The leaver's subscriptions are removed.
- The new owner is told.

```php
// before removing a member, once somebody chose who takes over
$tasks->transfer($orgId, $leaving, $successor);
```

**Asking who takes over.** `handoverChoices($userId)` answers what a leaving person is asked,
organisation by organisation: their `shared` tasks (reports their organisation or the installation
receives), their `personal` ones, and the `successors` who may take them,
`TaskPolicy::successors($orgId, $leaving)`. By default that is the organisation's other active
members, and nobody outside an organisation. `handover($userId, [$orgId => ['to' => $id,
'personal' => true]])` performs the choice: shared tasks go to the successor, the person's own
only with `personal`, and a successor the policy does not offer moves nothing.

The account's delete page asks this and hands over before the erase (see the Authentication
Guide). An application whose member removal is its own flow does the same with two calls:

```php
// app/Controllers/OrganizationMembers.php — removing a member who was asked who takes over
$tasks   = new \Pramnos\Llm\Chat\Routines(new \Pramnos\Llm\Chat\Conversations(\Pramnos\Llm\Chat\Chat::assistant()));
$offered = $tasks->handoverChoices($leaving);        // draw a choice of successor per organisation
$tasks->handover($leaving, [$orgId => ['to' => (int) $_POST['successor'], 'personal' => !empty($_POST['personal'])]]);
// then remove the membership; what was not handed over stays the leaver's, paused by
// TaskPolicy::stillAllowed() at its next run if the application checks membership there
```

### Agreement to a provider

`Policy\StoredConsent` keeps who agreed to their data going to which provider, and checks it only
for a provider whose entry says `requires_consent`:

```php
'llm' => ['consent' => \Pramnos\Llm\Policy\StoredConsent::class],

$consent = new \Pramnos\Llm\Policy\StoredConsent();
$consent->give($orgId, $userId, 'deepseek');      // $orgId null in an application without organisations
$consent->withdraw($orgId, $userId, 'deepseek');
```

A call for a person needs that person's agreement; a background call with no person needs
anybody's in the organisation; a call with neither is refused — unless
`'consent_options' => ['allow_without_organization' => true]` says calls outside an organisation
carry nobody's data.

## Screens for conversations

Three ways to put conversations in front of people, all over the same hooks.

### The JSON API

One line in the application's API routes mounts every endpoint:

```php
\Pramnos\Llm\Chat\Api\Routes::register($router);

// An application's own controller, and paths of its own for some of them
\Pramnos\Llm\Chat\Api\Routes::register($router, App\Api\Chat::class, [
    'routines' => '/routines',
    'consent'  => '/ai/consent',
]);
```

| path (default) | |
|---|---|
| `GET /chat` | this person's conversations, and the limits a screen shows |
| `POST /chat` | ask — `content`, `conversation_id`, `attachments: [{name, data}]` with the bytes in base64; 202 with `ask_id`, or 200 with the answer under `answer_in_request` |
| `GET /chat/{id}`, `DELETE /chat/{id}` | one conversation with its messages; forget it |
| `GET /chat/artifacts` | this person's artifacts, newest first, without content |
| `GET /chat/artifacts/{id}`, `DELETE /chat/artifacts/{id}` | one artifact with its content; remove it |
| `POST /chat/{id}/share`, `POST /chat/artifacts/{id}/share` | share with `names` (a list, or one string of usernames/emails); `shared` and `unknown` |
| `GET /chat/{id}/shares`, `GET /chat/artifacts/{id}/shares` | who it is shared with |
| `DELETE /chat/shares/{id}` | stop one share |
| `GET /chat/artifacts/{id}/actions`, `POST /chat/artifacts/{id}/actions/{key}` | a list's bulk actions; run one with `params` — `done`, or `queued` for a long list |
| `POST /chat/artifacts/{id}/approve`, `POST /chat/artifacts/{id}/reject` | settle a change the model proposed; approving runs it |
| `POST /chat/messages/{id}/rating` | rate an answer: `rating` 1, -1 or 0, and `note` |
| `GET /chat/artifacts/{id}/csv`, `POST /chat/artifacts/{id}/pin` | an artifact's rows as CSV (`filename`, `csv`); pin it, or `pinned: false` |
| `GET /chat/shared` | what others shared with this person |
| `GET /chat/people?q=` | who matches what is typed in a name field, `{people: [{value, label}]}`: in the analyst's space, active accounts at or above its floor; nobody in a person's own Ask (`SharePolicy::candidates()`) |
| `POST /chat/shared/{id}/open` | open it: the `conversation_id` of this person's own copy |
| `GET /chat/asks/{id}` | a queued question: `waiting`, `answered` with its `conversation_id`, `failed` with `says`, or `cancelled` |
| `POST /chat/asks/{id}/cancel` | stop a question still `waiting`; 404 when there is none |
| `GET /chat/memory`, `DELETE /chat/memory/{id}` | what it remembers; forget one |
| `GET`/`POST /chat/routines`, `PUT`/`DELETE /chat/routines/{id}`, `POST /chat/routines/{id}/run` | scheduled tasks, with the templates, cadences, `kinds`, and the `owners` this person may use; `active` in a `PUT` pauses or resumes; `running` while a run waits or is under way; `run` queues one (202) |
| `GET /chat/routines/{id}/runs?limit=` | a task's runs, newest first: its own, or a shared report the person may read |
| `GET /chat/runs/{id}`, `POST /chat/runs/{id}/continue` | one run with its task; open it as a conversation of the person's own (`conversation_id`) |
| `GET /chat/reports` | the shared reports here the person may receive, with `subscribed` and `latest_run` |
| `POST`/`DELETE /chat/routines/{id}/subscribe` | receive a shared report (`email` in the body), or stop |
| `GET`/`POST`/`DELETE /chat/consent?feature=` | a feature's provider (`chat` by default), whether it needs agreement and whether it was given; agree; withdraw |

A refusal is `{status, error}`; one for want of agreement adds `consent_required` and `provider`,
so a screen can ask on the spot. The provider checked — whether it is set up, whether it needs
agreement — is the one the router sends this person to (`purpose` `chat`, or the feature's name),
so an organisation on its own provider or its own key is asked about that one.
`Conversations::connection()` answers it. Each decision an application makes is a protected method of
`Chat\Api\ChatController`, overridden in a subclass:

| hook | |
|---|---|
| `refusal($action)` | called first by every action; a response stops it — a plan that does not include it |
| `beforeAsk($scope, $input)` | after the agreement check: a limit, a charge |
| `storePicture($path, $filename)` | keep an attached picture and answer its address; without it pictures are not kept |
| `extra($input)` | what travels with the question to the assistant's `question()` |
| `limits($scope)` | the limits the screen shows — add this month's use |
| `routinesAnswer($scope, $answer)` | the scheduled-questions list — add a plan's limit on active ones |
| `scope()`, `conversations()`, `asks()`, `routineService()` | where everything comes from |

### The page, without a SPA

`init --features=…,llm` writes `src/Controllers/Chat.php`, extending
`Chat\Web\ChatPageController`, with views in each theme under `views/chat/`. `/chat` lists the
conversations, shows one, and asks. Answers are drawn by `Chat\Markdown::toHtml()`, which escapes
first; a table it draws carries `table table-sm` for Bootstrap and daisyUI and its spacing and
rules inline for any other theme, and scrolls on its own when wider than the answer. Under each, which tools it read and with what. The ⚙ holds the model, the limits, what it
remembers and the agreement; a bar shows how much of the model's context the last answer used.

With `assets/js/pf-chat.js` the page asks in place: Enter sends and Shift+Enter is a new line;
files are picked with 📎, dropped on the box or pasted, and held as chips (ten at most, 5 MB
together, of the kinds the server reads); the question shows at once with a thinking line —
the application's own lines after a few seconds, `'llm' => ['chat' => ['waiting_lines' =>
[…]]]`, and a promise after a minute — and when the answer is there the page's own HTML is
fetched and swapped in, without a reload. Every word the script says comes from the page, so it
is translated with the rest. Without JavaScript the page posts and refreshes itself until the
answer is there.

A question is a bubble on the right and an answer is text on the left, each under a line with
whose it is — "You", or the assistant's name (`'chat' => ['name' => …]`, `'analyst' => ['name' =>
…]`) — and when, in the reader's own zone: "today 22:50", or the date for another day. Under each
answer, ⧉ Copy puts its Markdown on the clipboard, not its HTML. Over each table in an answer,
⧉ Copy table puts its rows on the clipboard tab-separated, which a spreadsheet pastes as cells. On
Bootstrap the question's bubble also carries its colour inline, for an admin theme still on
Bootstrap 3, which has no `bg-body-tertiary`. A question asked in place is drawn
the same way at once.

#### How the page looks, as the application's own

An empty conversation is a greeting over one large composer, with the example questions under
it. Once a conversation is open, its title replaces the greeting and the composer stays at the
bottom of the view. The composer has 📎, the files held, a hint and a round send button. Typing
`/` at the start of the composer opens a menu of commands. ↑ and ↓ choose, Enter or Tab runs the
chosen command, and Escape closes the menu. While the menu is open, Enter never sends the half-typed
command as a question.

The application brands the page in `'llm' => ['chat' => […]]`, and the analyst's page in
`'analyst' => […]`:

```php
'chat' => [
    'name'        => 'Naya',                                   // over each answer
    'greeting'    => 'Hi, I am Naya. What shall we look at?',  // over the empty composer
    'placeholder' => 'Ask Naya…',
    'accent'      => '#7c3aed',                                // the send button, focus ring, chosen command
    'suggestions' => ['How did this week go?'],
    'commands'    => [
        ['name' => 'week', 'says' => 'This week in numbers', 'text' => 'How did this week go, against last week?'],
        ['name' => 'help', 'says' => 'How Naya works', 'url' => '/help/naya'],
    ],
],
```

Without a `greeting`, an assistant the application named (`name`) introduces itself: "Hi, I am
Naya. How can I help?". Without a name either, the line is "How can I help?". The framework's
default names ("Ask", "Analyst") belong to a feature, not a persona, so they introduce nobody
(`ChatLook::greeting()`). Without an `accent`, the theme's primary colour is used. The page's own
tools (⚙) sit in the top corner of the conversation column.

`Chat\Web\ChatLook::accent()` accepts only a CSS colour, so a stray `;` cannot add rules to the
`style` attribute. `ChatLook::commands()` puts the page's own commands first: `/new`,
`/scheduled` where the page has it, `/attach` and `/memory`. The application's follow, each with
`text` to put into the composer or a `url` to go to. A command with a name already taken, or with
neither `text` nor `url`, is left out.

The page is one column, the title and the answers' tools included, `--pf-chat-width` wide (1100px,
enough for a report's tables). An application changes the width by setting that property on
`.pf-chat`. The styles are in the theme's `components.css`, under *Ask*. They set shape and layout only, on
the same shared tokens as the other components, and the theme's own buttons, type and colours
still apply. `--pf-chat-accent` on the page (set from `accent`) overrides the theme's primary for
the page. An existing project picks the styles up with `./pramnos project:resync --css`, and the
script with `--js`. Its `views/chat/` are its own copies: compare them with the theme's, because
the composer, the greeting and the `/` menu need the new markup.

#### The scheduled page

`/chat/scheduled` (`ChatPageController::scheduled()`, linked from the conversations page, with a
view in each theme under `views/chat/scheduled.html.php`) shows a person what is scheduled for
them, without asking the model:

- **Their tasks:** what each is (question, reminder, report), when it runs and next, whether it is
  paused and why it stopped. Each has buttons to see its runs, run it now, pause or resume, and
  delete it.
- **The shared reports they may receive** (`Routines::reports()`), each with a button to receive
  it or stop.
- **One task's runs**, with `?task=`, newest first. A report's runs have an *Ask about it* link to
  `chat/report/{run}`.
- **A form to schedule one:**
    - what it is and, when the policy lets them share, for whom (`TaskPolicy::canManage()`);
    - whether readers are told every time or only when something is new;
    - a template;
    - the title and the words;
    - how often, at what time, the weekday or the day of the month, which weekday of the month,
      or a moment for a one-off.

A one-off further than ten years ahead is refused.

Its POST actions are `schedule`, `scheduledupdate` (`active`), `scheduleddelete`, `scheduledrun`
and `scheduledsubscribe` (`on`). Each carries the session's token and says what it did, or why
not, as a flash message.

The page is the trait `Chat\Web\SchedulesTasks`, so it can be served elsewhere. A controller
using it answers six hooks:

- `taskScope()`: whose tasks;
- `taskConversations()`: the conversations they are made over;
- `scheduledUrl($action)`: the page, or one of its actions;
- `taskReportUrl($runId)`, `taskChatUrl()` and `taskSignInUrl()`: where a report's run opens, the
  conversations, and where somebody not signed in is sent.
- `taskDenied()`, optional: true when the request stops here, already answered. Every action asks
  it first; `AiController` answers with the admin floor, as its other actions do.
- `scheduledExtras(?array $task): array`, optional: markup of the controller's own — `form`,
  printed inside the form before its button; `task`, on the open task's card. Printed as given, so
  escape what goes in.
- `scheduledCrumbs(?array $task): void`, optional: the page's breadcrumbs. By default these are
  the conversations, Scheduled (a link back to the list when a task is open) and the open task's
  title, named by `taskChatName()` ("Ask AI", or the analyst's name on `AiController`). An
  application whose shell draws its own trail overrides it to add nothing.
- `afterScheduled(array $task, array $input): void`, optional: called with the task just made and
  the whole form, the controller's own fields among it, before the redirect. Not called when the
  form was refused.
- `afterAudienceChanged(array $task, array $before): void`, optional: called when a report's
  readers were changed from its card, with the task as it is and as it was, to subscribe the
  people newly named, for example.
- `static audienceRoles(): array`, optional: the roles readers are chosen by, `role => usertype`,
  lowest first. An application whose roles differ from the framework's returns its own.

A flash message carrying a value is `t('… %s …', $value)`, so a translation catches the whole
message. `next_run_local` is in figures (`19/10/2026 09:00`), which read the same in any language.

The view reads the addresses from `$this->urls`. `AiController` serves the analyst's at
`/admin/Ai/scheduled`, with `/admin/Ai/report/{run}` for its reports. A report's link and an analyst
conversation's are built the same way, by `AnalystAssistant::areaUrl()`: the admin area's address
where the process has one, otherwise `admin/` under the site's — so a link made by the site's cron
still leads into the administration. The analyst's page links
there only where the application lets the analyst schedule, through its `routineRefusal()`. An
analyst that may schedule tells its model so, and counts times on the analyst's clock
(`Analyst::timezone()`).

#### Charts

An answer can carry a chart: a fenced block with the info string `chart` and one JSON object.

````markdown
```chart
{"type": "bar", "title": "Sign-ups per month", "labels": ["Jan", "Feb", "Mar"],
 "series": [{"name": "2025", "data": [12, 19, 7]}, {"name": "2026", "data": [15, 22, 30]}],
 "y": "people", "stacked": false}
```
````

The fields:

- `type` is `bar`, `hbar`, `line`, `area`, `pie` or `doughnut`.
- `series` holds numbers only, one per label. A pie or doughnut takes one series.
- `title`, `subtitle`, `x`, `y`, `stacked` and `min` are optional.

How it is drawn:

- `Chat\Markdown::toHtml()` turns a block that is a chart into `<div data-pf-chart="…">` with
  the code inside it. A block that is not a chart stays code.
- `pf-chat.js` draws it as SVG over the code: axes with round steps, a key, and each figure as
  a shape's tooltip.
- **Your own chart library:** set `window.PfChat = {chart: function (element, spec) { …; return
  true; }}` before `pf-chat.js` loads. Return false to leave a chart to the built-in drawing.
- Copy and export keep the original block.

The analyst's prompt tells the model when to chart and to keep the figures as text as well —
`Markdown::CHARTS`, through `AnalystAssistant::charts()`. Return '' from it for an analyst that
never charts. A chat assistant of your own adds `Markdown::CHARTS` to its prompt to chart.

Example questions sit above a new conversation, and a click puts one in the box, to send or to
change first:

```php
'llm' => [
    'chat'    => ['suggestions' => ['Which posts did best this month?', 'Draft a reply to this email']],
    'analyst' => ['suggestions' => ['Who signed up this week?', 'What failed in the queue today?']],
],
```

`pf-chat.js` is one of the framework's page scripts, which `composer update` does not place in
the web root: `php bin/pramnos project:resync --js --all` does, after an upgrade that brings a
page using a new one. Until then the page works as a plain form; the `framework_scripts` health
check and a warning in the log, the first time a page asks for the missing file, both name the
command. The hooks are the API's — `beforeAsk()`,
`storePicture()`, `extra()`, `scope()` — and a refusal comes back as the page's error message.

A question from anywhere on the site: include the widget in the layout. It is drawn only for
somebody signed in.

```php
<?php $this->insert('../partials/chat_widget'); ?>
```

### The SPA components

A Svelte SPA with the `llm` feature gets `components/llm/Chat.svelte`, `Scheduled.svelte` and
`AiConsent.svelte`, with `lib/llm/chat.js` (the API client and what the screens share),
`lib/llm/markdown.js` (`marked`, then DOMPurify) and their tests under `__tests__/llm/` — a
directory of their own, so they cannot land on an application's own chat screen or its tests.
An existing project takes them with `php bin/pramnos project:resync --spa-components --all`, which adds
`marked` and `dompurify` to package.json and leaves every component the project changed as it is
(see the [Console Guide](Pramnos_Console_Guide.md)). They are the project's files from then on.

```svelte
<script>
    import Chat from '../components/llm/Chat.svelte';
    import { go } from '../lib/router.js';

    let { route = null } = $props();
</script>

<Chat
    conversationId={Number(route?.query?.get('c')) || 0}
    onopen={(id) => go('/chat', id ? { c: id } : null, { replace: true })}
    scheduledHref="/scheduled"
    paths={{ routines: '/routines', consent: '/ai/consent' }}
/>
```

`Chat` keeps no address of its own: the open conversation comes in as `conversationId` and goes
out through `onopen(id)`. Everything else an application changes without editing the file:

| prop | |
|---|---|
| `client`, or `api` and `paths` | the API: `chatClient(api, paths)` from `lib/llm/chat.js` by default |
| `extra` | an object, or a function returning one, sent with each question; `extraUsed()` once it went |
| `canAsk`, `onRefusal(err)` | hold the Ask button; take a refusal — return `true` when the application said it itself |
| `suggestions` | questions that fill the box when pressed — they do not ask; shown under the composer of an empty conversation |
| `greeting`, `avatar` (snippet) | an empty conversation's line and picture over the composer; *How can I help?* without a greeting |
| `accent` | a CSS colour for the send button, the composer's focus and the chosen `/` command; the kit's primary without one |
| `commands` | the application's `/` commands, after the screen's own (`new`, `scheduled` with `scheduledHref`, `attach`, `memory`): `{name, says, text}` fills the box, `{name, says, url}` goes there, `{name, says, run}` calls `run()` |
| `toolLabel(tool)` | how a tool an answer read is named under it |
| `t` | the translator — every string goes through it, `lib/i18n.svelte.js`'s by default |
| `consentNotice(consent)` (snippet) | the agreement's wording — legal text the application owns |
| `question` (bindable), `placeholder` | what is in the box — a prompt another screen prepared — and its placeholder |
| `waitingLine(ms)` | a line under *Thinking*, asked each second of waiting; `null` shows nothing. By default a sentence after a minute |
| `onanswer(messages)` | told the conversation each time an answer has been read back — open what it produced |
| `formatDate`, `numberFormat` | how a conversation's date and the limits' figures are shown |
| `scheduledHref` | where `Scheduled` lives; without it there is no *Scheduled* link |
| `privacyUrl` | the privacy policy, linked from the consent notice; without it there is no link |
| `conversationHref(id)` | the conversations become links the application's router follows, and open in a new tab |
| `header` (snippet) | the title, in place of *Ask AI* |
| `message(message, change)` (snippet) | drawn under each answer; `change(patch)` updates the message and calls `onmessagechange(id, patch)` |
| `onmessagechange(id, patch)` | told when `change(patch)` updated a message, to persist it |
| `composer` (snippet) | beside the Ask button: a cost, what the question is about |
| `aside` (snippet), `asideOpen` (bindable) | a panel beside the conversation from `lg`, over it below that, closed with ✕ |

`AiConsent` wraps what needs agreement and draws nothing of it until agreement is given, when the
provider in use asks for it; its `consent` is bindable. `kind` names the feature asked about
(`chat` by default), `notice(consent)` replaces the wording and keeps the buttons.

`Scheduled` does for a SPA what `/chat/scheduled` does for a page:

- It lists, creates (from a template or by hand), pauses, runs and deletes questions, reminders
  and reports, with every cadence: once, daily, weekly, monthly, and a weekday of the month.
- A report offers whom it is for, from the `owners` the API says this person may use, and when
  readers are told.
- It shows a task's runs, and opens a report's run as a conversation of the reader's own through
  `continueRun`, then `onopen(id)`.
- It lists the reports shared with this person, to receive or stop.
- It opens a routine's conversation with `onopen(id)`.

## The administrators' analyst

**Where its screen is.** Its links — a report's notification, a conversation, a share — lead into
the administration area where the application has one, and otherwise to `admin/` under the
site's address. An application whose whole site is the administration (`/Ai`, `/Users`) says so
with `'llm' => ['analyst' => ['area' => '']]`, or names another place with `'area' => 'backoffice'`;
every link follows (`AnalystAssistant::areaUrl()`).


`/admin/Ai` — *Ask*, the AI item in the admin menu — answers questions about the installation
itself — how many signed up last month, which emails were opened, what failed in the queue, what
the models cost and for whom — from the installation's own tables. The settings are a link away,
at `/admin/Ai/settings`; an application whose settings are reached from its own menu hides the link
under Ask's heading by overriding `settingsLink()` to return `''`. Ask still opens the settings
while no key is set. Both sit behind one gate: the ability `admin.ai` and the usertype in the
application's `Admin\Controllers\Ai::$requiredUserType`. The page is the conversation page at
the analyst's own addresses, with everything `pf-chat.js` does there; its waiting lines are
`'llm' => ['analyst' => ['waiting_lines' => […]]]`.

**Nothing invites a question that can only fail.** Until a provider's key is set:
- *Ask AI* is left out of the user menu, and the widget draws nothing (`Chat::available()`, with
  the asking person's own routing);
- the admin header has no Ask, and the side panel no Ask tab (`Analyst::available()`);
- the admin menu has no *AI* item, and `/admin/Ai` opens the settings. The settings stay one
  click away from Settings (*AI: providers & keys*), where the key is set.

A menu item of an application's own does the same with `when:`, a closure asked each time the
menu is drawn:

```php
new NavItem('user.writer', 'Writer', $base . 'writer', NavSection::User, when: static fn (): bool => Chat::available());
```

An *Ask* link for the admin header, beside the search, drawn only for somebody who may open the
page:

```php
<?php echo \Pramnos\Llm\Analyst\Analyst::headerButton(); ?>
```

A new project's layouts have it; a project's own header takes that line.

### As JSON

The same analyst answers in the conversation API's shape under `/admin/Ai/api/` — `chat`,
`chat/{id}`, `asks/{id}`, `asks/{id}/cancel`, `memory`, `memory/{id}`, `consent` — for a screen of the application's
own, such as the SPA's `components/llm/Chat.svelte`:

```js
const client = chatClient(adminApi, {
    chat: '/admin/Ai/api/chat', asks: '/admin/Ai/api/asks',
    memory: '/admin/Ai/api/memory', consent: '/admin/Ai/api/consent',
});
```

It is the admin area, so it goes with the session's cookie; anything but GET needs the session's
token in the `X-CSRF-Token` header. A JSON body and a form are both read.

### What changed in the code

`recent_changes` reads what changed over a window — the application's git history (each commit's
date, message and the files it touched; a word narrows it) and the framework's own changelog days
from `vendor/` — so "this worked yesterday and not today" can be checked against a change rather
than guessed at. The model is told to answer in plain words: what changed for the person and when,
and whether it is likely the reason — never commit hashes, file names or code. A server deployed
without `.git` has no history to read, and the tool says so. `Analyst\Tools\ChangesTool` is the
class, for an application that wants it in an assistant of its own.

### Kept apart

An administrator's analyst conversations, what it remembers and its queued questions are in the
`admin` **space** (`Chat\Scope`'s third argument): never in their Ask AI list, nor the other way
round. They are outside any organisation, because the analyst reads the whole installation — the
call goes to the router with `organization_id` **null**, purpose `analyst`. The connection is the
`analyst` feature's when the application declares one (`'llm' => ['features' => ['analyst' =>
…]]`), otherwise the installation's. Agreement, when the provider asks for it, is the
administrator's own (a consent row with no organisation), asked on the page. The analyst runs no
scheduled reports.

### Other people's conversations, read only

`/admin/Ai/all` lists the other administrators' Ask conversations — owner, title, last change, how
many messages and what they cost, filtered by owner or a word in the title — for whoever has
`admin.ai.all`: root alone in usertype mode, as `admin.chat.all` is (`Analyst\Review::readsAll()`;
`AiController::readsAll()` is the seam to override). The Ask page links to it from the ⚙ under
its title, beside the settings — a reader with neither link sees no gear. One opens
as its owner sees it, under a banner saying whose it is: the questions, the answers, the tools each
answer used with their input, and the model calls made while it was answered — the call log's
rows, `/admin/Ai/call/{id}`, where what each tool returned is in the messages sent. The calls are
matched by time: those of the owner's analyst between one question and the next. The steps and
tools are folded, as on Ask's page, and `pf-chat.js` draws the answers' charts.

Nothing there writes: no question box, no delete, no memory, no agreement. Each view of somebody
else's conversation is recorded in the audit log as `ai.conversation_viewed`, the owner as its
target and the conversation as its object. Anybody else gets a 404.

The reader's own conversations are left out of the list and the owner filter
(`Review::list(['except' => $id])`, `Review::owners($id)`): they are in the Ask column already, where
they are not read only, and `/admin/Ai/all?c=` with one of them redirects there.

### Its name and picture

The analyst is called *Ask* unless the application gives it a name of its own:

```php
'llm' => ['analyst' => [
    'name'     => 'Naya',                     // the header's button, the side panel, the Ask page, the conversations
    'mentions' => ['naya'],                   // words that call it after @ in a conversation, beside @Ask
    'avatar'   => '/themes/main/img/ai.svg',  // its picture beside the name
]],
```

`Analyst::name()`, `mentions()` and `avatar()` read them, and every place the administration shows
the analyst asks them: the header's button and the side panel (its tab, title, and what a screen
reader hears — *Ask Naya about this page*), the Ask page's title, the conversation with it and its
*… is thinking*, the composer's `@` suggestion, and the *Open in …* card on an answer. In a
conversation `@Ask`, `@Naya` and each mention call it, as a word of its own and ignoring case, and
the mention is drawn as `@Naya`. Its conversation is kept as the bot `Ask` (`Ask::NAME`) whatever it
is called; the JSON gives it a `displayName` and an `avatar`. The model is told its name, so it
answers to it.

### Its living picture

The analyst's picture moves with what it does. The contract is one element and one attribute:

```html
<span class="pf-assistant-avatar pf-assistant--thinking" data-state="thinking" data-durations='{"success":1800,…}'>…the set's markup…</span>
```

The framework changes only `data-state` (and the `pf-assistant--{state}` class), to one of nine:
`idle`, `listening`, `thinking`, `talking`, `success`, `error`, `offline`, `greeting`, `notify`.
Every movement is the set's CSS reacting to `[data-state="…"]`. `success`, `greeting` and
`notify` go back to `idle` after their duration.

| where | when |
|---|---|
| the side panel and the Ask page | `greeting` the first time the panel opens in a session; `listening` while the reader writes; `thinking` from the question to the answer; `talking` as the answer arrives, then `success`; `error` when it fails; `offline` where it cannot answer |
| the header's button | the same, and `notify` when a scheduled task's result arrives |
| the conversations | its picture on its conversation and its answers; `thinking` at a question in its conversation, `talking` as an answer arrives |

`Assist\AvatarSets::render($state, $size)` draws it. Without a set it is the framework's own,
`pf` — a drop with eyes in the theme's accent, its CSS in every theme's `components.css`, still
under `prefers-reduced-motion`. A set of the application's own is its markup and its CSS:

```php
'llm' => ['analyst' => ['avatar_set' => [
    'markup' => APP_PATH . '/themes/admin/assets/naya/naya.svg',   // a file, the markup, or a callable
    'css'    => '/themes/admin/assets/naya/naya.css',              // linked once a page
    'states' => ['success' => 1800, 'greeting' => 2400],           // ms, for those that end by themselves
]]],

\Pramnos\Assist\AvatarSets::register('naya', $markup, '/assets/naya.css', ['greeting' => 2400]);
'llm' => ['analyst' => ['avatar_set' => 'naya']],
```

A plain picture (`avatar`) with no set is drawn in the same element and does not move.

Two properties belong to the contract, and a set respects them: `--pf-assistant-size` (24px) and
`--pf-assistant-color` (the theme's accent). Both are inherited — the element declares neither —
so a parent sizes or colours the picture: `.pf-ask { --pf-assistant-size: 20px; --pf-assistant-color: #fff; }`.
A set's stylesheet is outside any `@layer`, so it outranks layered utility classes; colouring
through the property avoids a fight.

The element carries its width and height inline as `var(--pf-assistant-size, 24px)`, with the size
asked for as the fallback. A page whose theme does not load `components.css` still draws the
picture at its size and not the width of the page, while a parent can still size it through the
property. The framework's own drop also carries its colours as SVG attributes: the text's colour
with white eyes. The stylesheet overrides both.

The set's stylesheet reaches a page once. `render()` puts its `<link>` before the first picture the
page draws. Where the picture is drawn inside a button or a link, `AvatarSets::stylesheet()` gives
the `<link>` to put before it; the side panel does so. A picture that is data rather than the
page — in JSON, a `<template>`, a script's props — is drawn with `render($state, $size, '', false)`:
no `<link>`, and the stylesheet is not counted as written, so whatever puts the data on the page
calls `stylesheet()` (the conversations dock does). A stylesheet on this server is linked with
`?v=` its file's time, so a cache or a CDN does not keep an old one.
`pfAssistant.state('thinking')` sets the state from the application's own script, and
`pf-assistant:state` (`detail.state`) is dispatched on the document at each change.

### What it reads

Every tool only reads, and answers figures over a window — `days` (30 by default, at most 366)
or `from`/`to`, grouped by `day`, `week` or `month`. A day is a day in the analyst's zone:
`'llm' => ['analyst' => ['timezone' => 'Europe/Athens']]`, or the application's default zone. The
prompt names it, and each answer's window says which it used.

| tool | |
|---|---|
| `users` | accounts by type, active and validated; sign-ups and sign-ins per period; never signed in |
| `sessions` | online now (last 15 minutes), people and guests, each hour of the last day |
| `organizations` | by type, active, created per period; the largest by active members (name and count) |
| `mailing_lists` | subscribers per list by status; subscriptions, confirmations and unsubscriptions per period |
| `email` | sent, opened (proxy-only apart), clicked and their rates, by list and period; subjects sent to at least three; the most clicked links without their query strings; and `history`, every message sent by status and module with the failures by module (`Email\SendHistory`, as the dashboard counts it) |
| `push` | sent, delivered and failed, by kind and status; the commonest failures |
| `queue` | tasks by state, waiting by kind, failures by kind with their first error line, run times |
| `llm_spend` | calls, tokens and cost by provider, model and purpose; scheduled tasks' runs apart, by task (`llm_calls.routine_id`); failures; spend per organisation (id and name) |
| `log-analytics`, `log-errors`, `status` | the MCP tools of the same names |
| `user` | one account the administrator names: type, state, dates — its email only with `include_email` |
| `audience_segments`, `audience_rules`, `audience_experiments` | segments with members and movement; rules with status, trigger and how they are doing, one with how many went through each step; A/B tests with their results — offered where their tables are, each behind its screen's ability (`admin.segments`, `admin.rules`, `admin.experiments`) |
| `audience_why` | why one account is or is not in a segment, or would or would not come into a rule — tagged `personal` |
| `audience_draft` | the one tool that writes, and only drafts: a segment comes back as its count and a link that opens the builder filled in, nothing saved; a rule is saved as a draft, which does nothing until an administrator switches it on |

### Personal data

The provider is a third party, so nothing an aggregate answers names a person, and every
result passes through `Analyst\Redactor` before the model reads it: email addresses become
`a***@d***.com`; tokens, payment card numbers (Luhn-checked, and not run into letters, as the
digits inside an id are), phone numbers (international, or
local with separators) and IP addresses become `[token]`, `[card]`, `[phone]`, `[ip]`. A number
without any separator is left alone — it is as likely an order number or a Unix time. A subject
sent to fewer than three recipients is left out, since a personalised one carries a name no mask
can recognise. The `user` tool's answer is tagged `personal`, so a [data rule](#data-rules) can
keep it from a provider that may not have personal data.

### Its own SQL, and files to download

For a question no tool answers, the analyst can write SQL — when the application turns it on:

```php
'llm' => ['analyst' => [
    'sql'         => true,   // `schema` and `sql`: one SELECT, up to 200 rows to the model
    'export'      => true,   // `schema` and `export_csv`: up to 100,000 rows to a CSV file
    'sql_seconds' => 30,     // how long a statement may run
    'schema'      => [       // what the catalogue cannot say
        'orders' => ['about' => 'One customer order.', 'columns' => ['status' => '1 open, 2 paid, 3 refunded']],
    ],
]],
```

`schema` lists the tables, or one table's columns with their keys, and the application's notes,
keyed without the table prefix. `sql` answers rows. `export_csv` writes them to a file. A list of
up to `export_inline` rows (100 by default) also comes back whole, and the model is told to show
it as a table beside the link. A longer one gives the model only the link, the count and five
rows. An optional `title` names the file, in Latin letters when it was written in Greek:
`7-energa-meli-2a91…`, saved as `energa-meli-<date>.csv`. The file has a UTF-8 byte-order mark, so a
spreadsheet reads non-Latin text. It is kept seven days outside the web root, in
`var/analyst-exports/`, and downloads from `/admin/Ai/export/<name>` only for the administrator
it was made for.

The link is built in the queue worker, which has no request to take a host from and often runs the
site's application, not the area's. So the question carries the area's URL as its request saw it
(`Desk::extra()`), and the link is built under it: absolute, and in the area, with nothing to
configure. Without one — a question not asked through a page — it falls back on the configured
prefix under `APP_URL`, or from the host's root (`AdminArea::address()`).

A statement is guarded four times, by `Analyst\ReadOnlySql`:

- it must be one SELECT or WITH with no writing keyword anywhere (`Security\ReadOnlyQuery`) —
  which also refuses `INTO OUTFILE`, `FOR UPDATE` and `LOCK IN SHARE MODE`;
- it may not call a function that waits, locks or reads the server's files — `SLEEP`,
  `BENCHMARK`, `GET_LOCK`, `LOAD_FILE`, `pg_sleep`, `pg_read_file`, advisory locks, `dblink`;
- it runs in a read-only transaction that is rolled back, so the database refuses a write the
  lexer missed;
- it stops after `sql_seconds` — `statement_timeout` on PostgreSQL, `max_statement_time` on
  MariaDB, `MAX_EXECUTION_TIME` on MySQL.

A statement without `LIMIT` is given one. Where the installation set `database_readonly_dsn`,
the statement runs through that account, as `db-inspect` does. Then the database's own
privileges are the boundary.

Rows go through the redaction like every tool's answer. `sql` and `export_csv` are tagged
`personal`, since a statement can read anybody's row.

### From any administration page

The admin header's **Ask** opens the analyst in a panel beside the page, about that page. A
question carries the screen and the record it shows, and the analyst gets the page's help as
background. With the `help` feature on, its `help` tool answers from the installation's help pages. See
[Administration help and the side panel](Pramnos_Admin_Assistant_Guide.md).

### The application's figures

```php
'llm' => [
    'admin_tools' => [App\Llm\PostsPublishedTool::class, new App\Llm\Credits($wallet)],
    'analyst'     => ['assistant' => App\Llm\Analyst::class],
],
```

`admin_tools` are `Pramnos\Mcp\McpToolInterface` — class names built with no arguments, or
instances — beside the framework's, redacted the same way. One that implements `ScopedMcpTool`
is served to MCP clients holding its scope as well (see the
[MCP guide](Pramnos_MCP_Guide.md#a-tool)). They should only read; the framework
cannot check that. An `Analyst\AnalystAssistant` subclass tells the model about the business
through `about()`, or changes the tools through `tools()`.

A `tools()` that cuts what some people may read decides by `$turn->rightsOf()`, not by
`$turn->scope->userId`. The two differ when others read the answer too — `@Ask` in a conversation
(see the [Conversations guide](Pramnos_Conversations_Guide.md)) — where `rightsOf()` is the
conversation's least privileged reader and `scope->userId` is who asked and pays.
`$turn->shared()` says the answer is read by others. There the framework leaves out the asker's
memory and `export_csv`, and tells the model which of the asker's tools it may not use:

```php
public function tools(\Pramnos\Llm\Chat\Turn $turn): array
{
    $reader = ToolAccess::user($turn->rightsOf());

    return array_filter(parent::tools($turn), static fn ($tool, $name): bool => ToolAccess::allows($reader, (string) $name), ARRAY_FILTER_USE_BOTH);
}
```

Whether it looks people up is `peoplePolicy()`. The framework's rule keeps to figures and opens
one account only when the administrator names it. Where finding and counting people by name is
ordinary administration, replace the rule rather than contradicting it in `about()`, so the model
gets one rule and not two that disagree:

```php
protected function peoplePolicy(): string
{
    return 'Finding and counting accounts by name is ordinary administration here; do it when asked.';
}
```

**The same analyst speaks over MCP.** `mcpInstructions()` is what the MCP endpoint sends with
`initialize`, and a remote assistant passes it to its model. It contains:

- `about()`;
- the analyst's time zone;
- `peoplePolicy()`;
- that masked values stay masked;
- which tool to reach for: `toolGuidance()`, or by default "schema and sql are the last resort".

Without the tool line, a model asked about the application's visits writes SQL against the schema
instead of calling the tool made for visits:

```php
protected function toolGuidance(): string
{
    return 'Visits, bookings and their counts: visits_report. Reach for sql only when no tool answers.';
}
```

An application that charges for the
analyst, or limits it, overrides `analystDesk()` on its `Admin\Controllers\Ai` and returns an
`Analyst\Desk` with its own `beforeAsk()`.

