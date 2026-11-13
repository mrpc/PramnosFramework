---
use_cases:
  - Calling a language model (OpenAI, DeepSeek, Gemini, Mistral, Claude) from an application
  - Getting an answer in a given JSON shape, or reading the fields of an invoice or form
  - Capping what one answer may spend, and falling back when a provider is down
  - Keeping data of one kind away from a provider that may not have it, and choosing a model per question
  - Plugging an application's plans, quotas or consent into model calls
  - Measuring a prompt, model or tool change against eval fixtures, and failing CI on a regression
  - Working out what model calls cost, which failed, and why
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

The language-model pages:

- **Language models** (this page) — asking, providers and connections, policies, evals, the call log.
- [Tools for a model](Pramnos_LLM_Tools_Guide.md) — the registry and skills, MCP servers, parallel and once-only calls, subagents, the web.
- [Conversations](Pramnos_LLM_Conversations_Guide.md) — the assistant, the context's zones, memory, artifacts, approvals, sharing.
- [The person's own](Pramnos_LLM_Person_Guide.md) — a profile, a calendar, a journal, and manners when reaching out.
- [Scheduled tasks](Pramnos_LLM_Scheduled_Tasks_Guide.md) — questions, reminders and reports on a schedule.
- [Screens for conversations](Pramnos_LLM_Screens_Guide.md) — the JSON API, the page, the SPA components.
- [The administrators' analyst](Pramnos_LLM_Analyst_Guide.md) — questions about the installation's own figures.

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
fenced code block around the JSON is read. Elsewhere, DeepSeek and Claude for two, the schema
becomes the arguments of one tool named `$name`, which the model is asked to call.

`JsonSchema::errors($value, $schema)` is the check. It covers `type` (one, or a list),
`required`, `properties`, `items`, `enum`, `minimum`/`maximum`, `minLength`/`maxLength` and
`minItems`/`maxItems`, and nothing else in a schema. A number written as text (`"5"`) counts as a
number.

### Reading fields out of a document

`Pramnos\Llm\Extractor` reads named fields out of a document — an invoice, an order, a form — and
checks them before anybody uses them. It never writes: the application shows what was read for a
person to confirm, or hands it to an [approved change](Pramnos_LLM_Conversations_Guide.md#changes-the-model-proposes-and-a-person-approves).

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

A file is read for its text by `DocumentText` (docx, odt, txt, md, csv, tsv, eml); a PDF where `pdftotext`
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

**Going on from where it stopped.** In a conversation, an answer cut at its budget or its rounds
is remembered as cut: the next question's history names the calls it had already made, with
their arguments (`Conversations::whereItStopped()`). "Go on" starts from there, instead of
reading everything again.

Set the token figure from what an answer costs today, not from a round number: every round sends
the whole conversation again, so an answer of six rounds over a 25,000-token prompt spends
150,000 tokens before its output.

## Providers and connections

Five are built in. Four go through the OpenAI-compatible `/chat/completions` — `openai`,
`deepseek`, `gemini` and `mistral` — and `anthropic` (Claude) through Anthropic's Messages API,
by its own transport (see below). Each keeps its own settings, so several stay configured at once:
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
`/models` are chat models), `default_model`, `default_max_tokens` (the answer budget when nothing sets one; 2 000), `models` (the curated list and what each is for),
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

A model name a provider has retired is sent as the name that answers in its place
(`Providers::RENAMED`), wherever it was saved, and prices saved under the old name still cost it.
DeepSeek's `deepseek-chat` is sent as `deepseek-flash`. `deepseek-reasoner` is not renamed,
because with reasoning off its thinking would be lost: set `deepseek-flash` with reasoning on
instead.

### Claude (`anthropic`)

Set `llm_anthropic_api_key` to a key from the Claude Console
(<https://platform.claude.com/settings/keys>) — the API is billed in its own credits, not by a
Claude.ai subscription — and choose `anthropic` as the provider. The models offered are
`claude-opus-5-5` (the default), `claude-sonnet-5-5` and `claude-haiku-5-5`, with a context window
of a million tokens.

`Transport\Anthropic` translates the client's requests and answers both ways, so the tool loop,
budgets, fallback, streaming and costing work as they do for the other providers:

- the system prompt is the request's `system`; a system note the client adds later in the
  conversation (last round, a correction) goes as text in the user's turn it follows;
- tools go as `input_schema`, calls come back as `tool_calls`, results go back as `tool_result`
  blocks in one user turn;
- a forced tool call goes as `tool_choice: auto` — the current models refuse forced tool use with a
  400 — and the client already says in its prompt which tool it expects;
- the models always think and take no temperature; the reasoning setting is
  `output_config.effort`, `low` with reasoning off and `high` with it on. Thinking is not sent back
  in later rounds: a thinking block replayed after the client has trimmed the history near the
  window is a 400, and none at all is always valid;
- every request asks for the prompt cache, and what it saved is costed at the cache price — the
  Claude models are in `Pricing::SEED`; an installation whose `llm_prices` was already saved adds
  them on `/admin/Ai/settings`, or their calls show no cost;
- the default answer budget is 16 000 tokens rather than 2 000, because thinking spends from it.

A structured answer (`askForJson()`) goes through a tool rather than `output_config.format`, which
refuses schemas with bounds such as `minimum` or `maxLength`.

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

Only a transport with a `chatStream()` method streams; `Anthropic` reads the Messages API's own
events (`content_block_delta`, `message_delta`). `OpenAiCompatible` has one too: it sends
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

On the screen, a feature's Model is a list: the models of the provider the row names, or of the
installation's provider when the row names none, and changing the row's provider changes the list.
"— (the provider's)" leaves it to the provider's own setting, and a saved model that is in no list
stays offered as it is.

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
  [analyst](Pramnos_LLM_Analyst_Guide.md#the-administrators-analyst) says it on purpose — so fill a tenant in only when the
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
- **Decided once a conversation, not once a question.** The decision is a model call of its own,
  so a conversation keeps the tier it was given for `Conversations::CASCADE_HOLD` (4) questions,
  within the hour, then is decided again. `'llm' => ['cascade_hold' => 1]` decides every question,
  at that cost; a conversation's first question, which has no id yet, is always decided.

### Models a person may choose

Beside the tiers, an installation can let people pick the model their Ask questions go to. The
card **Models people may choose** on `/admin/Ai/settings` keeps the choices
(`Chat\ModelChoices`, the setting `llm_model_choices`). Each choice has a name, a provider, a
model, reasoning on or off, and a floor:

| Field | |
|---|---|
| Lowest usertype | who reaches it, when no ability is named |
| Or ability | when set, `AdminAccess::allows($user, ability, usertype)` decides — the access rules in `permissions` or `mixed` mode, the usertype in `usertype` mode |

Reasoning is the provider's own switch (see [Providers and connections](#providers-and-connections)):
on, the provider's `efforts['on']` is sent; off, `efforts['off']`.

The Ask composer, on the conversation page and the analyst's, shows a picker when the person
reaches at least one choice: **Automatic** first, then their choices, cheapest first. A change is
posted to `modelchoice` (`Chat\Web\ChoosesModel`) and kept with their account as the user detail
`askmodel`. Each question checks the kept choice again (`ModelChoices::chosen()`): a key that is
unknown, or behind a floor the person no longer reaches, is Automatic. `Conversations::connection()`
follows the choice too, so consent and "is it connected" are asked of the provider that will
answer.

**Automatic** picks among the choices the person reaches, when there are two or more and the
cascade is `ModelCascade`. `ModelCascade::choose()` asks the cheapest of them to name one, with the
list in cost order. So the dearest it can reach is the dearest the person's role allows: an
administrator whose floor stops at a plain model never lands on a reasoning one, while a
super-administrator can. The cost order is what a million tokens in and a million out cost in
[the prices](#the-call-log-and-costs). An unpriced model comes last, as if dearest, and a reasoning
choice comes after the same model without reasoning. The pick is held for
`Conversations::CASCADE_HOLD` questions, like a tier, and decided again once the person no longer
reaches it. A pick that fails (refused, or down after its retries and fallback) is answered on the
cheapest choice, which is told it answers in the stronger model's place. With fewer than two
choices, or another cascade, Automatic is what it was without choices: the tiers, or the
assistant's own connection. A `Conversations` given a `Client` of the caller's own ignores the
person's choice.

The calls carry `model_choice` in their context.

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

## Evals: measuring a change before it ships

`Pramnos\Llm\Eval\Suite` asks a set of questions, each with the tool calls a good answer makes,
and scores the answers. `llm:eval` compares the scores with a baseline and exits 1 on a
regression, so a CI step stops on a prompt, model or tool change that made things worse.

A case is a JSON file, one case each or a list of them:

```json
{
  "name": "orders this month",
  "user_query": "How many orders did we get this month?",
  "available_tools": ["count_orders", "list_users"],
  "tool_results": {"count_orders": {"count": 42}},
  "expected_tool_calls": ["count_orders"],
  "expected_arguments": {"count_orders": {"period": "this_month"}},
  "expected_final_state": {"answer_contains": ["42"]}
}
```

`available_tools` names tools of the set the suite is given (the assistant's own, with their real
descriptions and schemas) or defines them in place as `{"name": {"description", "parameters"}}`.
No tool runs: each answers its `tool_results` entry, or `ok`. So a case reads nothing, changes
nothing, and gets the same results every time; only the model varies. A case may carry its own
`system` prompt.

| Score, per case | |
|---|---|
| `tool_accuracy` | expected tools called, over the larger of expected and called: an unexpected call costs as much as a missing one |
| `argument_fidelity` | expected arguments the best call of each tool had; `"Ann"` matches `"ann "`, `"5"` matches `5`, a list or object must match whole |
| `final_state` | the answer holds every `answer_contains` text, ignoring case |
| `rounds` | the rounds the answer took |

| Gate (`Report::regressions()`) | Fails when |
|---|---|
| `tool_accuracy`, `argument_fidelity` | the mean falls by more than 5 points of 100 (`Report::ACCURACY_DROP`) |
| `rounds` | the mean rises by more than a fifth (`Report::ROUNDS_RISE`): the same answers costing more |
| `skill_used`, `assertions` | the mean, over the cases that score it, falls by more than 5 points |

```bash
# tests/llm-eval/setup.php returns ['system' => …, 'tools' => …, 'client' => fn () => new Client(…)]
./yourapp llm:eval tests/llm-eval --setup=tests/llm-eval/setup.php --baseline=tests/llm-eval/baseline.json --write-baseline
./yourapp llm:eval tests/llm-eval --setup=tests/llm-eval/setup.php --baseline=tests/llm-eval/baseline.json
```

Keep the baseline in the repository beside the cases: a saved report in the directory is not
read as a case. An improvement is taken by writing it again, and a regression is seen in review.
The run asks a real provider and costs what its questions cost, so a CI job runs it when prompts,
tools or models change, by hand or on a schedule, and not on every push. A CI has no saved
settings, so `setup.php` gives the client its key from the environment:
`'client' => fn () => new Client((new Connection('openai'))->with(['api_key' => getenv('OPENAI_API_KEY')]))`.

```yaml
on:
  workflow_dispatch:
  schedule:
    - cron: '0 5 * * 1'
jobs:
  evals:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer install --no-interaction
      - run: ./yourapp llm:eval tests/llm-eval --setup=tests/llm-eval/setup.php --baseline=tests/llm-eval/baseline.json
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
```

In code, `(new Suite($cases))->run($systemPrompt, $tools)` returns the `Report`; compare two with
`$report->regressions($baseline)`.

### Assertions graded by a judge

Some questions cannot be checked mechanically, such as "names a cohort" or "proposes a win-back
email". A case can carry `assertions`, which are plain-language checks, and the model grades them
as a judge in one call per case. `expected_output`, if present, is shown to the judge as what a
good answer looks like. The score `assertions` is the share met, and the ones not met are listed
in `unmet`. Files in the public Agent Skills shape are read as they are:

```json
{"skill_name": "crm", "evals": [
  {"id": "churn", "prompt": "Who is drifting away?", "expected_output": "A cohort, and an action for it",
   "assertions": ["Names a cohort", "Proposes a win-back email"]}
]}
```

Each entry becomes a case named `<file>#<id>`, with `prompt` as its `user_query`. Grading costs one
more call per case with assertions.

### A skill's own evals

A [skill folder](Pramnos_LLM_Tools_Guide.md#skills-with-a-method-skill-files) can keep its cases in
`evals/*.json`, and `llm:eval` asks them as a conversation would, with the setup's tools grouped
into skills:

```bash
./yourapp llm:eval --skills --setup=tests/llm-eval/setup.php                    # every skill with evals/
./yourapp llm:eval --skill=crm-loyalty --setup=tests/llm-eval/setup.php --write-baseline
```

A case that names no `available_tools` is offered all of them: the tools of no skill, `use_skill`,
and through it the skills' own tools. `use_skill` really runs, so turning a skill on hands over its
instructions, while the tools it adds answer their canned results as usual. A case that names no
`expected_skill` is expected to turn on its own skill, and the score `skill_used` says whether it
did, because a skill the model never turns on is text nobody reads. Each skill is compared with its
own `evals/baseline.json`, and `skill_used` and `assertions` are gated like accuracy, over the
cases that score them. A baseline written before these scores existed does not fail on them. The
setup may give a `context` (`['userid' => …]`) for tools with permissions.

A skill's cases are asked with 16 rounds, as a conversation is, unless `--rounds` says otherwise.
Plain fixtures keep 6. A case that stopped at the limit, either forced to answer in its last round
or still calling tools, is marked `(hit the round limit)` and its scores are of a cut answer. A
tool with no `tool_results` answers `ok`, which a model may take as a reason to keep looking, so
give the tools a case needs a realistic result.

**Noise.** The judge's grades, and a model's choices, vary from run to run, often by 15 to 30 points
on a single case, while the mean is steadier. `--repeat=N` asks every case N times and scores the
mean (`Report::average()`). An assertion appears as unmet with how many runs missed it, and that is
what a baseline should be written from before the 5-point gate means anything. `-v` prints what
each case called and the assertions it missed.

## The call log and costs

`llm_calls` holds every call — failures especially, each with a sentence in `error`. The cost is
frozen at write time from the unit prices, so correcting a price does not restate history, and
it is `null` for a model nobody priced: a total adding noughts for it would be confidently low.
`CallLog::summary()` and `byModel()` say how many calls were not priced. `llm_log = 0` stops
logging; `llm:prune` keeps 90 days. `created_at` is written and compared in UTC
(`CallLog::instant()`, which is `Database\Instant::utc()`): with its offset on PostgreSQL, and as
the bare UTC wall clock on MySQL and MariaDB, which drops an offset. Either way the windows hold
whatever zone PHP or the database session is in, and a value read back is UTC.

Every call of one turn — an answer, a degraded fallback to another tier, the summary fold of an
old conversation, and the memory learned from it — carries the same `llm_calls.trace_id`
(`Conversations::trace()`, a fresh one per `ask()`/`report()` and per conversation in
`learnQuiet()`'s own batch). `CallLog::trace($id)` reads them back together, oldest first — "why
did this answer cost what it cost" is usually not one row.

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
