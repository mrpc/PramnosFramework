---
use_cases:
  - Calling a language model (OpenAI, DeepSeek, Gemini, Mistral) from an application
  - Letting a model answer with the application's MCP tools
  - Keeping data of one kind away from a provider that may not have it
  - Plugging an application's plans, quotas or consent into model calls
  - Working out what model calls cost, which failed, and why
  - Giving users conversations with a model that remember them
  - Scheduling a question to a model every morning, as its owner
  - Asking users' agreement before their data goes to a provider
  - Turning an attached Word, OpenDocument or email file into text for a model
---

# Language models

`Pramnos\Llm` calls a language model, lets it use tools, logs every call with what it cost, and
gives an administrator one screen — `/admin/Ai` — for keys, models, prices and the log. What the
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

## Providers and connections

Four are built in, all through the OpenAI-compatible `/chat/completions`: `openai`, `deepseek`,
`gemini` and `mistral`. Each keeps its own settings, so several stay configured at once:
`llm_<provider>_api_key`, `_base_url`, `_model`, `_temperature`, `_max_tokens`, `_reasoning`.
`llm_provider` chooses the one in use. Everything is edited on `/admin/Ai`; keys are stored
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

### Features

An application names its uses of a model, and each can have its own provider, model, temperature,
budget and reasoning switch, set on `/admin/Ai`:

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

The loop: the model answers `tool_calls`, each is run, its result goes back, until it answers in
words. The **last round forbids tools** and tells the model not to claim anything no tool did —
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
more rounds.

## Policies

Each defaults to "allow, record nothing", and is a class named in `app.php` (built once per
process) or set at runtime with `Llm::use()`:

```php
'llm' => [
    'limits'  => App\Llm\PlanLimits::class,     // Pramnos\Llm\Policy\Limits
    'consent' => App\Llm\Consent::class,        // Pramnos\Llm\Policy\Consent
    'router'  => App\Llm\Router::class,         // Pramnos\Llm\Policy\Router
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
logging; `llm:prune` keeps 90 days. `created_at` is written and compared in UTC with its offset
(`CallLog::instant()`), so the windows hold whatever zone PHP or the database session is in.

Prices are USD per million tokens in three buckets — `cache_hit`, `cache_miss`, `output` —
because a cached token can cost a fiftieth of an uncached one; an entry may add `off_peak` with
`peak_utc` hours. They are a setting (`llm_prices`), edited on `/admin/Ai` and seeded from
`Pricing::SEED`. Costs are estimates and the screen says so; `Billing\OpenAiCosts` reads what
OpenAI actually billed, by project, with an Admin key, and works out the balance from the top-ups
recorded beside it (`new OpenAiCosts(projects: ['llm_openai_other_project'])` reports more
projects).

## The administration screen

`/admin/Ai` (ability `admin.ai`, usertype 90) is the one place these settings live: the provider
in use, each provider's key, model and parameters, a key test, the features, the prices, OpenAI
billing, and the call log with the problems on their own. An application puts its own settings
on the same screen rather than a second one beside it:

```php
'llm' => ['admin_sections' => [App\Llm\PlanLimitsSection::class]],
```

A `Pramnos\Llm\Admin\Section` has a `key()` and a `title()`, `render()`s its fields (escaped HTML,
no `<form>` and no button — the screen adds both, with the session's token), and `save(array
$input): ?string` stores what was posted, answering what is wrong or `null`. A section whose
`render()` throws shows the error in its card instead of taking the screen down.

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
`ai_routines`. A table that already exists under its name is left as it is; `tags` and `extra` are
added to an existing `chat_conversations` and `chat_messages`.

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
| `systemPrompt(Turn)`, `context(Turn)` | the instructions, and what the model is told about the person; what it remembers is added after |
| `tools(Turn)` | this answer's tools; the memory's and the schedule's are added by the framework |
| `due(Turn)`, `verify(Turn)` | what an answer must end with, and what it is checked against — see `askWithTools()` |
| `writes()` | the tools that change something: a later answer is told which an earlier one called |
| `question(Turn, $q)` | the question as the model reads it, with what the caller passed in `extra` worked in |
| `answered(Turn, $message)` | after it is stored: attach what the answer produced, and decorate the message |
| `present(Scope, $messages)` | stored messages, decorated for the screen |
| `acting(Scope, $run)` | a queued or scheduled answer, run inside whatever the application's tools need set up |
| `heldRefusal($tag)` | said when a conversation holds data of a tag nowhere available may take |
| `routineRefusal(Scope)` | why this person may not schedule questions, or null |
| `conversationUrl($id)` | where a conversation is read, for a notification |
| `rounds()`, `turns()` | tool rounds per answer (16), turns sent back (30) |

A `Chat\Turn` carries the scope, the conversation, whether it is a scheduled run, the messages,
the documents attached in the conversation, the caller's `extra`, and `bag` — the application's,
for the length of the turn, for what its tools wrote.

A turn sends back the last `turns()` turns, never starting on an answer, each earlier answer
followed by a note of the tools that wrote in it; without the note, a later answer sees no result
for a change it made and says it changed nothing. A note the model copies into its reply is taken
out. A conversation whose earlier answers carried data of a tag (`tags`, see
[Data rules](#data-rules)) is moved to a connection that may take it before anything is asked,
or refused with `heldRefusal()`. Only an answered turn is stored — question and answer together.

An attachment is a document's words: `ask(…, attachment: ['name' => 'release.docx', 'text' => …])`.
`Pramnos\Llm\DocumentText::from($name, $bytes)` reads `.docx`, `.odt`, `.txt`, `.md` and `.eml`
(the email's subject, sender and words, and the documents it carries); `pictures()` takes a
document's photos, and `keepPictures($name, $bytes, $store)` keeps them through
`$store(string $path, string $filename): ?string` — the application's media library — and lists
their addresses after the words.

### Memory

The model is given `remember`, `forget`, `search_conversations` and `read_conversation`, and told
what it remembers in every prompt. It remembers only when asked to by the conversation — nothing
extracts memories behind the person's back — and at most `memory.max` entries of `memory.chars`
characters. `Chat\Memory` lists and forgets them for a screen. Searching is case-insensitive; on
MySQL it also ignores accents, as its collation does, and on PostgreSQL it does not.

### Asking in the background

An answer with tools can take a minute. `Chat\Asks::start($scope, $conversationId, $question,
$attachment, $extra)` writes a waiting row and queues a `chat_ask` task, which the queue worker
answers without being registered — as its author, through `acting()` — and marks answered or
failed. The screen follows it with `find($scope, $askId)`. `Asks::onAnswered(callable)` is told
each answer with the job, `extra` included — where else the answer should go.

### Questions on a schedule

`Chat\Routines` keeps questions asked again daily, weekly or monthly, at a time on the owner's
clock: `create($scope, ['title', 'prompt', 'cadence', 'at', 'day', 'timezone'])` or from a
template in `chat.routine_templates`, `mine()`, `setActive()`, `delete()`. The model can schedule
one itself (`schedule_task`), except from a scheduled run. `llm:routines`, every minute, asks
what is due as its owner into the routine's own conversation, moves its next run on whether it
worked or not, and tells the owner with `Chat\RoutineReady` — or the class
`chat.routine_notification` names, constructed with the title, whether it worked, why not, and
the link.

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
