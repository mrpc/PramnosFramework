---
use_cases:
  - Making a write fail on purpose in a test
  - Finding why a green filtered run proves nothing
  - Avoiding a test that re-implements the code it tests
  - Using @codeCoverageIgnore correctly
---

# Pitfalls: tests that pass for the wrong reason

Part of the [testing guide](Pramnos_Testing_Guide.md).

## Making a write fail is harder than it looks

Four scaffolders carry the same guard:

```php
if (!file_put_contents($filename, $stub)) {
    throw new \Exception("Cannot write middleware file: $filename");
}
```

The obvious way to cover it is to put a directory where the file should go, so the write cannot
succeed. It does not work, and the reason is worth knowing before spending an afternoon on it:

- **`file_exists()` is true for a directory.** Every one of these methods checks "already there"
  before it writes, so the directory trips *that* guard and the write is never attempted.
- **`chmod 000` does not stop root.** The suite runs as root in its container, where an unwritable
  file is still writable and an unwritable directory is still enterable.
- **Making the parent a file works only if the parent does not exist yet.** `@mkdir($dir, 0777,
  true)` fails on a path whose component is a file, the failure is discarded, and the write then
  fails for real — but `src/Middleware` and its three siblings already exist in any tree the suite
  has run in once.

Which leaves a read-only mount, a full disk or a quota: real production conditions, and none of them
producible from a test here. So the guards stay and carry `@codeCoverageIgnoreStart` with that
reason written next to them, the same way `Database::prepareInput()`'s missing-extension branch does.

**Mark a branch ignored only after failing to reach it, and write down what you tried.** The comment
is the part that matters: without it the next reader cannot tell an unreachable branch from one
nobody got round to, and the annotation becomes a way of hiding work rather than recording a fact.

What the tests assert instead is the guard that *does* fire — an occupied path is refused, on all
four, with the message naming the kind of file. That is the more valuable assertion anyway: it
is what stops `create:model` from overwriting an existing model and reporting success.

## An application's `applicationInfo` cannot be set by a property default

`Application::__construct()` assigns `$this->applicationInfo = self::loadApplicationInfo(APP_PATH .
'/app.php')`, so a test double written the obvious way —

```php
class TestApplication extends \Pramnos\Application\Application
{
    public $applicationInfo = [];      // ← overwritten before any test sees it
}
```

— comes back holding the fixture's namespace, not the empty array. A test written that way
supplies a namespace without meaning to, and the scaffolders' `: 'App'` fallback for an
unconfigured project is unreachable by construction.

Assign it after construction instead:

```php
$application = new TestApplication();
$application->applicationInfo = ['theme' => 'default'];   // non-empty, names no namespace
```

Note the value: `[]` would work here too, but a project whose `applicationInfo` is genuinely empty
is a different case from one that has settings and no namespace, and it is the second that the
fallback is written for.

## A warning is an exception here, but only because a test made it one

`MediaObject::addImage()` wraps `copy()` in `try { … } catch (\Exception $ex)`. That looks like
dead code — `copy()` warns and returns `false`, it does not throw — and it is not, because the test
that covers it installs a handler first:

```php
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_WARNING);

try {
    $media->addImage($sourceDirectory, 'test_media_module');
} finally {
    restore_error_handler();
}
```

`ErrorException` extends `Exception`, so the `catch` fires and the arm is reachable. This is the
established way to cover a warning-shaped failure in this suite, and it is worth knowing before
"fixing" one of these guards into a return-value check: the guard is right, the arm is tested, and
rewriting it breaks the test that was covering it.

Two details that follow:

- **`@` does not stop a custom handler.** PHP 8 calls the handler regardless; it is the handler's job
  to consult `error_reporting()`. So `@copy(…)` inside the source does not protect the caller from a
  handler the test installed.
- **The arm is only reachable while the handler is installed.** Which is why the neighbouring
  `unlink()` guard is still uncovered: reaching it needs a copy that succeeds and a delete that
  fails, and as root in a container the second does not happen.

## Two ways to lose a row you just wrote

Neither is about the code under test.

**`MediaObject::load()` reads through the query cache.** It is `query($sql, true, 600, 'media')` — cached for ten
minutes, keyed on the SQL text. A test that writes a row and reads it back gets the cache unless it
flushes:

```php
\Pramnos\Framework\Factory::getDatabase()->cacheflush('media');
```

Worse in a class that recreates its tables per run, because `mediaid` restarts at 1 and the cached
row belongs to a *different test's* media — same id, same SQL, plausible-looking answer.

**The constructor loads only a positive id.** `new MediaObject($id)` calls `load($id)` when the
id is above zero; `0`, `''` and `null` give an empty object with no error and no warning, and
`->thumbnails` is the empty default. A test that passes an id it has not resolved yet (an
unset variable, a route segment that did not parse) gets the empty object, and an assertion that a
freshly written row came back empty is the symptom while the row is fine.

## `No such file or directory` from a database that worked under a filter

A class that connects fine on its own and fails in the full suite with a socket error is not a
flaky database. It is the singleton: whichever test ran before left
`Database::getInstance()` holding another lane's connection settings, and `Factory::getDatabase()`
hands that same object back rather than building one from the settings just loaded.

Take the connection from `Connection::fresh()` (see
[above](Pramnos_Testing_Databases_Guide.md#take-the-connection-from-connectionfresh-not-from-the-factory)), or drop the
reference first:

```php
Settings::loadSettings($this->settingsFixture());

$reference = &\Pramnos\Database\Database::getInstance();
$reference = null;                       // ← the part that is easy to leave out

$db = \Pramnos\Framework\Factory::getDatabase();
if (!$db->connected) {
    $db->connect();
}
```

The symptom is worth recognising because it points the wrong way: `No such file or directory` is a
socket path, so it reads as "the database is not running" — and under `--filter` the same class
passes, which reads as "the suite is interfering with itself" rather than "this class did not ask
for its own connection".

Wrap the connect in a `try`/`markTestSkipped()` as well. A class whose backend is genuinely absent
should skip, not error: the two lanes here mean every dual-backend class runs twice, and one of the
two may have nothing to talk to.

## A wrong-case namespace is a bug that only an uncovered branch can hide

A reference written `\pramnos\Logs\Logger` — lowercase `p` — looks harmless, because PHP
resolves class names case-insensitively. It is not:

- **If the class is already loaded**, PHP finds it in its own table and the call works.
- **If it is not**, the autoloader is asked for the literal string `pramnos\Logs\Logger`, and
  Composer's PSR-4 map is keyed on `Pramnos\` — a case-sensitive miss. `Error: Class
  "pramnos\Logs\Logger" not found`.

So whether it works depends on what else the process happened to load first. Inside a `catch`
it does the most damage: the line only runs when something has already gone wrong, and it turns
a handled failure into an unhandled one. A cache adapter's `catch` arms exist so that losing the
cache degrades the application rather than stopping it, and a wrong-case logger reference in them
raises `Class not found` instead. Tests that run those arms in processes where the class is
already loaded do not see it. That is the general shape:

**A wrong-case reference in a rarely-taken branch cannot be found by running the application.** It
needs either the branch executed in a cold process, or a grep:

```bash
grep -rn '\\pramnos\\' --include='*.php' src/     # should print nothing
```

Worth running after any bulk edit that touches namespaces, and worth remembering when a class that
demonstrably exists is reported as not found.

## A green filtered run is not evidence that your new test ran

Tests appended to an existing file, then `./dockertest --filter UsersControllerTest`, and
`OK`. That reads as "they pass", and it can mean they did not run at all.

A file can end with two classes — the `TestCase` and a probe subclass that exposes protected
methods — and an insertion "before the last closing brace" lands in the second one. A test
method on a class that is not a `TestCase` is just a method: PHPUnit never sees it, nothing
fails, and the run is green because the *other* tests are fine. Only the counts give it away:
the test and assertion totals do not move.

So when adding to an existing file:

- **Check the test count moved**, by the number of methods you added. `OK` on its own says nothing
  about the tests you just wrote.
- **Look at where the file ends.** A helper class, a probe subclass, a namespace block for function
  shims — several files here have all three after the `TestCase`, and "append before the last
  closing brace" puts your work in the wrong one.
- **A new test that has never failed has not been demonstrated to work.** Break it once on purpose
  if it went in green on the first run — the same rule as for any assertion, and this is the failure
  mode it catches.

## `@codeCoverageIgnore` must be the whole comment, or it does the opposite

php-code-coverage 11 matches these annotations by **exact string comparison**:

```php
if ($comment === '// @codeCoverageIgnoreStart' ||
    $comment === '//@codeCoverageIgnoreStart') {
```

So the natural thing to write —

```php
// @codeCoverageIgnoreStart — reached only when the socket is already closed
```

— matches nothing. Which would merely be a no-op, except for the `End` handler:

```php
if ($comment === '// @codeCoverageIgnoreEnd' || ...) {
    if (false === $start) {
        $start = $token[2];
    }
    $this->ignoredLines[$filename] = array_merge(…, range($start, $token[2]));
}
```

`$start` is **not reset after use**. A `Start` that failed to match leaves it holding the line of the
last one that *did*, and the next `End` then ignores everything from there to itself. In a file with
several annotations and explanations on the `Start` lines, whole regions disappear from the report —
and nothing warns you, because the numbers only get better.

The effect can be hundreds of statements across a codebase, and it does not always flatter: when
most of the accidentally-excluded code is covered, correcting the syntax makes the total go *up*.
The exclusions hide the size of the codebase as much as untested code.

So:

- **Put the reason on its own line**, above or below:

  ```php
  // Unreachable in the suite: the guard above is true for a directory.
  // @codeCoverageIgnoreStart
  ```

- **A docblock is different.** There the check is `str_contains($docComment->getText(), '@codeCoverageIgnore')`,
  so `* @codeCoverageIgnore Requires a live server` works fine. The exact-match rule is only for `//`
  comments.
- **Check it took effect.** An ignored line is *absent* from `clover.xml`, not present with a count
  of zero:

  ```bash
  grep -c 'line num="268"' coverage/clover.xml    # 0 means ignored
  ```

- **Audit with a grep**, because this fails silently:

  ```bash
  grep -rn '// *@codeCoverageIgnore' --include='*.php' src/ \
      | grep -vE '// @codeCoverageIgnore(Start|End)?$'
  ```

## Assert once on a derived value, not once per item found

A loop of assertions over whatever a method discovered —

```php
foreach ($this->directories() as $directory) {
    $this->assertDirectoryExists($directory);
}
```

— makes the test's contribution to the suite's assertion total depend on how much the checkout
happens to have: two identical runs report different totals when one finds a directory the other
does not.

Nothing fails, and that is the cost. The assertion total is the cheapest signal there is for "did
this change add assertions or quietly lose some" — the same signal that catches tests landing in
the wrong class, above — and a variable total is a signal you cannot read.

Assert the property instead of iterating:

```php
$this->assertNotEmpty($directories, 'nothing was found, so the filter proves nothing');
$this->assertSame(
    $directories,
    array_values(array_filter($directories, 'is_dir')),
    'a directory that does not exist was returned'
);
```

Two assertions, always. And note the first one: **a loop over an empty array asserts nothing**, so a
test that iterates discovered data needs a non-empty check whether or not it iterates. PHPUnit says
so — *"This test did not perform any assertions"* — but only when the loop is the *whole* test.

## A test that re-implements the code under test will pass when the code changes

The sharpest version of "a test that replaces a method is not a test of it", because it can have a
good reason and a name that says it is doing the opposite.

`ApiAdmin::search()` caps the omnibox limit at twenty — an endpoint that took the number from the
request would be a denial-of-service endpoint with a friendly name. A test of that cap whose probe
overrides `search()` looks like this:

```php
public function search(): mixed
{
    if (($denied = $this->guard('search')) !== null) {
        return $denied;
    }

    // a copy of the line under test
    $this->searchLimit = min(20, max(1, (int) Request::staticGet('limit', 5, 'get', 'int')));

    return Response::json([]);
}
```

The reasoning is sound — running six real searches would assert the registry's behaviour rather
than the cap. The consequence is not: **the test asserts its own arithmetic.** Change the source to
`min(500, …)` and it still passes, because the source is never called.

Record from *below* the thing under test, not in place of it. That is why the controller has one
protected line —

```php
protected function searchRegistry(string $term, int $perSource): array
{
    return \Pramnos\Search\Registry::query($term, $perSource);
}
```

— and the probe overrides that instead. The real `search()` runs, the real cap computes, and the
number asserted is the one the endpoint produced. It also lets the test assert that the term
comes from `q` and that the default is five.

**The test to run on your own probes:** for each method it overrides, ask whether the source could
change and the test still pass. If the override contains a copy of any expression from the source,
the answer is yes.

## When the gap is in the environment, fix the environment

Some branches have no covered line for a reason that is not about the tests at all.

**APCu.** The migration fingerprint cache prefers APCu and falls back to a marker file. APCu is the
path that runs in production — it is the reason the cache exists — and it needs two things in the
test image: the extension, and `apc.enable_cli=1`, because the setting defaults to `0` for the CLI.
The `Dockerfile` sets both. Each CLI process gets its own empty cache, so nothing carries between
tests.

**A fetchable address.** `OutboundUrl` refuses every private, loopback, link-local and reserved
address, which is the whole point of it — so nothing a container ordinarily reaches is fetchable,
and its socket path cannot open. The temptation is to widen the guard for tests: change `self::` to
`static::` so a subclass can override `isPublicAddress()`, or add a flag. **Do not.** A guard that a
test can relax is a guard an application can relax by accident.

Give the environment a legitimate address instead. `docker-compose.yml` puts the test container on a
second network in `203.0.113.0/24` — TEST-NET-3 from RFC 5737, reserved for documentation and routed
nowhere — and PHP's `FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE` does not exclude the documentation
blocks, so an address in it reads as public while the container holding it can still only reach
itself. Apache is already listening, so `http://203.0.113.2/…` is a real fetch of a real file over a
real socket, with the guard untouched.

The tests that need it skip when it is absent, so a checkout run outside this compose file does not
fail on an address it cannot dial:

```php
$probe = @fsockopen('203.0.113.2', 80, $errno, $errstr, 2);
if ($probe === false) {
    $this->markTestSkipped('The outbound fixture address is not configured on this host.');
}
```

The general rule: **before deciding a branch is untestable, ask whether it is the environment that
cannot reach it.** An extension that is not installed, a SAPI setting that defaults off, an address
family a container does not have — all three look exactly like unreachable code, and none of them
is.

## `loadFromConfig()` only adds — reset first

`FeatureRegistry::loadFromConfig()` enables what you pass and **never disables anything**, so

```php
FeatureRegistry::loadFromConfig([]);        // a no-op, not "no features"
```

leaves whatever an earlier test enabled still enabled. Invisible in production, where it runs once
at boot; in a suite it means a test asserting "refused without the `devpanel` feature" can pass
for the wrong reason, or fail for one.

```php
FeatureRegistry::reset();                       // clears known *and* enabled
FeatureRegistry::loadFromConfig(['devpanel']);  // then declare exactly what this test wants
```

`reset()` exists for this and says so in its docblock. Two things to remember with it:

- after a reset the next call re-registers the built-in defaults, so `getKnown()` is enough to
  have a registry that knows every feature and enables none;
- **restore what the suite runs with in `tearDown()`.** The registry is process-wide, so a test
  that leaves it empty makes the next test's `isEnabled('auth')` answer false, and that test fails
  for a reason that has nothing to do with it.
