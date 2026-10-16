<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Controllers\AiController;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Llm;

/**
 * `/admin/Ai/settings`: the one place an installation's language-model settings live.
 *
 * What has to hold: every theme has the screen and something links to it; a key is never put
 * back on the page and an empty field keeps it; each form saves only its own part; and an
 * application's own sections are shown and saved there rather than on a second screen.
 */
#[CoversClass(AiController::class)]
class AiScreenTest extends LlmTestCase
{
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Every theme has both views, the navigation reaches the screen, a new project gets the
     * wrapper that puts it on an address, and the screen never prints a key.
     */
    public function testTheScreenExistsAndIsReachable(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3);

        foreach (self::THEMES as $theme) {
            foreach (['ai', 'call'] as $view) {
                $this->assertFileExists($root . '/scaffolding/themes/' . $theme . '/views/ai/' . $view . '.html.php', $theme . ' ' . $view);
            }
            $source = (string) file_get_contents($root . '/scaffolding/themes/' . $theme . '/views/ai/ai.html.php');
            // A key field is never given a value
            $this->assertDoesNotMatchRegularExpression('/name="(api_key|admin_key)"[^>]*value=/', $source, $theme);
            $this->assertSame(1, substr_count($source, 'name="api_key"'), $theme . ': one key field, with a fixed id');
            $this->assertStringContainsString("'ai'", (string) file_get_contents($root . '/scaffolding/themes/' . $theme . '/views/partials/admin_breadcrumb.html.php'));
        }

        // Assert
        $this->assertStringContainsString("\$admin('Ai')", (string) file_get_contents($root . '/src/Pramnos/Application/Application.php'));
        $init = (string) file_get_contents($root . '/src/Pramnos/Console/Commands/Init.php');
        $this->assertStringContainsString('src/Admin/Controllers/Ai.php', $init);
        $this->assertStringContainsString('class Ai extends FrameworkAiController', $init);
    }

    /**
     * The action feeds the screen everything it renders, the key excepted.
     */
    public function testTheActionFeedsTheScreen(): void
    {
        // Arrange
        $this->configure(['features' => ['chat' => 'Ask AI'], 'admin_sections' => [PlanSection::class, BrokenSection::class]]);
        $this->setting('llm_openai_api_key', 'sk-secret');
        $this->setting('llm_feature_chat_model', 'gpt-4o');
        $_GET = ['show' => 'problems'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->settings();
        $view = $controller->view;

        // Assert
        $this->assertSame('ai', $view->name);
        $this->assertSame('', $view->shown, 'the main template');
        $openai = array_values(array_filter($view->providers, static fn (array $p): bool => $p['id'] === 'openai'))[0];
        $this->assertTrue($openai['configured']);
        $this->assertStringNotContainsString('sk-secret', (string) json_encode($view->providers), 'the key never reaches the view');
        $this->assertSame('gpt-4o', $view->features[0]['values']['model']);
        $this->assertTrue($view->problems);
        $this->assertSame([true], $controller->log->askedProblems);
        $this->assertJson($view->prices);
        $this->assertSame('plans', $view->appSections[0]['key']);
        $this->assertStringContainsString('This section failed: render broke', $view->appSections[1]['html'], 'one broken section does not take the screen');
        $_GET = [];
    }

    /**
     * General settings: the provider, the timeout capped, and logging.
     */
    public function testTheGeneralFormSaves(): void
    {
        // Arrange
        $_POST = ['section' => 'general', 'provider' => 'mistral', 'timeout' => '900'];
        $controller = $this->controller();

        // Act
        $controller->save();

        // Assert — an unchecked box is logging off
        $this->assertSame(['llm_provider' => 'mistral', 'llm_timeout' => '300', 'llm_log' => '0'], $controller->stored);
        $this->assertSame(['Saved.'], $controller->messages);
        // Back to the settings, which are no longer the screen's first page.
        $this->assertStringEndsWith('Ai/settings', $controller->redirectedTo[0]);

        // An unknown provider is refused
        $_POST = ['section' => 'general', 'provider' => 'nope'];
        $refused = $this->controller();
        $refused->save();
        $this->assertSame(['There is no provider called nope.'], $refused->errors);
        $this->assertSame([], $refused->stored);
    }

    /**
     * A provider's key: encrypted when given, kept when empty, removed only when asked.
     */
    public function testAKeyIsEncryptedKeptOrRemoved(): void
    {
        // Arrange / Act — a new key
        $_POST = ['section' => 'key', 'provider' => 'deepseek', 'api_key' => ' sk-new '];
        $given = $this->controller();
        $given->save();
        $_POST = ['section' => 'provider', 'provider' => 'deepseek', 'model' => 'deepseek-chat',
            'temperature' => '0.3', 'max_tokens' => '4000', 'reasoning' => '1'];
        $params = $this->controller();
        $params->save();

        // Assert
        $this->assertStringStartsWith('enc:v1:', $given->stored['llm_deepseek_api_key']);
        $this->assertSame('sk-new', \Pramnos\Security\Encrypter::decrypt($given->stored['llm_deepseek_api_key']));
        $this->assertSame('0.3', $params->stored['llm_deepseek_temperature']);
        $this->assertSame('4000', $params->stored['llm_deepseek_max_tokens']);
        $this->assertSame('1', $params->stored['llm_deepseek_reasoning']);
        $this->assertArrayNotHasKey('llm_deepseek_api_key', $params->stored, 'the parameters form never touches the key');

        // Empty: kept
        $_POST = ['section' => 'key', 'provider' => 'deepseek', 'api_key' => ''];
        $kept = $this->controller();
        $kept->save();
        $this->assertSame([], $kept->stored);
        $_POST = ['section' => 'provider', 'provider' => 'deepseek', 'max_tokens' => '0'];
        $zero = $this->controller();
        $zero->save();
        $this->assertSame('', $zero->stored['llm_deepseek_max_tokens']);
        $this->assertSame('0', $zero->stored['llm_deepseek_reasoning']);

        // Removed; an unknown provider's key refused
        $_POST = ['section' => 'key', 'provider' => 'nope', 'api_key' => 'x'];
        $nokey = $this->controller();
        $nokey->save();
        $this->assertSame(['There is no provider called nope.'], $nokey->errors);
        $_POST = ['section' => 'key', 'provider' => 'deepseek', 'clear_key' => '1', 'api_key' => 'ignored'];
        $removed = $this->controller();
        $removed->save();
        $this->assertSame('', $removed->stored['llm_deepseek_api_key']);

        // A temperature out of range, an unknown provider
        $_POST = ['section' => 'provider', 'provider' => 'deepseek', 'temperature' => '5'];
        $hot = $this->controller();
        $hot->save();
        $this->assertSame(['The temperature is a number from 0 to 2.'], $hot->errors);
        $_POST = ['section' => 'provider', 'provider' => 'nope'];
        $unknown = $this->controller();
        $unknown->save();
        $this->assertSame(['There is no provider called nope.'], $unknown->errors);
    }

    /**
     * Each feature's fields are stored, normalised; an unknown provider is refused.
     */
    public function testFeaturesSave(): void
    {
        // Arrange
        $this->configure(['features' => ['chat' => 'Ask AI']]);
        $_POST = ['section' => 'features', 'features' => ['chat' => ['provider' => 'openai', 'model' => ' gpt-4o ',
            'temperature' => 'warm', 'max_tokens' => '-1', 'reasoning' => 'yes'], 'undeclared' => ['model' => 'x']]];
        $controller = $this->controller();

        // Act
        $controller->save();

        // Assert
        $this->assertSame([
            'llm_feature_chat_provider' => 'openai', 'llm_feature_chat_model' => 'gpt-4o', 'llm_feature_chat_temperature' => '',
            'llm_feature_chat_max_tokens' => '', 'llm_feature_chat_reasoning' => '',
        ], $controller->stored);

        $_POST = ['section' => 'features', 'features' => ['chat' => ['provider' => 'nope']]];
        $refused = $this->controller();
        $refused->save();
        $this->assertSame(['There is no provider called nope.'], $refused->errors);
    }

    /**
     * Prices are validated before they are stored, and can go back to the seed.
     */
    public function testPricesAreValidated(): void
    {
        // Arrange / Act
        $_POST = ['section' => 'prices', 'prices' => '{"m": {"cache_hit": 1}}'];
        $bad = $this->controller();
        $bad->save();
        $_POST = ['section' => 'prices', 'prices' => '{"m": {"cache_hit": 1, "cache_miss": 2, "output": 3}}'];
        $good = $this->controller();
        $good->save();
        $_POST = ['section' => 'prices', 'reset' => '1'];
        $reset = $this->controller();
        $reset->save();

        // Assert
        $this->assertSame(['These need cache_hit, cache_miss and output as numbers: m.'], $bad->errors);
        $this->assertSame([], $bad->stored);
        $this->assertArrayHasKey('m', json_decode($good->stored['llm_prices'], true));
        $this->assertSame(['llm_prices' => ''], $reset->stored);
    }

    /**
     * OpenAI billing: the Admin key as any other key, and top-ups that must each read.
     */
    public function testOpenAiBillingSaves(): void
    {
        // Arrange / Act
        $_POST = ['section' => 'openai', 'admin_key' => 'sk-admin', 'topups' => "2026-10-01 50\n\n2026-10-05 20", 'project' => 'proj_x'];
        $good = $this->controller();
        $good->save();
        $_POST = ['section' => 'openai', 'topups' => "fifty dollars"];
        $bad = $this->controller();
        $bad->save();
        $_POST = ['section' => 'openai', 'clear_key' => '1', 'topups' => ''];
        $cleared = $this->controller();
        $cleared->save();

        // Assert
        $this->assertSame('sk-admin', \Pramnos\Security\Encrypter::decrypt($good->stored['llm_openai_admin_key']));
        $this->assertSame('proj_x', $good->stored['llm_openai_project']);
        $this->assertSame(['Each top-up is a date and an amount on its own line: 2026-10-01 50'], $bad->errors);
        $this->assertSame('', $cleared->stored['llm_openai_admin_key']);
    }

    /**
     * An application's section saves through its own code, and an unknown one is refused.
     */
    public function testAnApplicationSectionSaves(): void
    {
        // Arrange
        $this->configure(['admin_sections' => [PlanSection::class]]);
        $_POST = ['section' => 'plans', 'tokens' => '1000'];
        $controller = $this->controller();

        // Act
        $controller->save();
        $_POST = ['section' => 'plans', 'tokens' => 'many'];
        $refused = $this->controller();
        $refused->save();
        $_POST = ['section' => 'nothing'];
        $unknown = $this->controller();
        $unknown->save();

        // Assert
        $this->assertSame('1000', PlanSection::$saved);
        $this->assertSame(['Saved.'], $controller->messages);
        $this->assertSame(['A number, please.'], $refused->errors);
        $this->assertSame(['Nothing on this screen is called nothing.'], $unknown->errors);
    }

    /**
     * A card that only shows is drawn in every theme with no form and no Save button, beside a
     * section that saves, which keeps both; and a post naming the card is refused, since there is
     * nothing of it to save.
     */
    public function testACardThatOnlyShowsHasNoForm(): void
    {
        // Arrange
        $this->configure(['admin_sections' => [SpendCard::class, PlanSection::class]]);
        $controller = $this->controller();
        $controller->settings();
        $assigned = get_object_vars($controller->view);
        $_POST    = ['section' => 'spend'];
        $posted   = $this->controller();

        // Act
        $posted->save();
        $pages = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $dir  = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/ai';
            $view = new \Pramnos\Application\View(new \Pramnos\Application\Controller(), $dir, 'ai');
            foreach ($assigned as $name => $value) {
                if (!in_array($name, ['name', 'shown'], true)) {
                    $view->$name = $value;
                }
            }
            ob_start();
            \Closure::bind(function () use ($dir): void {
                include $dir . '/ai.html.php';
            }, $view, \Pramnos\Application\View::class)();
            $pages[$theme] = (string) ob_get_clean();
        }

        // Assert
        foreach ($pages as $theme => $html) {
            $card = substr($html, (int) strpos($html, 'id="section-spend"'));
            $card = substr($card, 0, (int) strpos($card, '</section>'));
            $this->assertStringContainsString('data-spend', $card, $theme);
            $this->assertStringNotContainsString('<form', $card, $theme . ': no form');
            $this->assertStringNotContainsString('Save', $card, $theme . ': no Save button');
            $this->assertStringContainsString('name="section" value="plans"', $html, $theme . ': the section beside it keeps its form');
        }
        $this->assertSame(['Nothing on this screen is called spend.'], $posted->errors);
    }

    /**
     * Testing a key says what the provider said; an unknown provider is refused.
     */
    public function testTestingAKeySaysWhatHappened(): void
    {
        // Arrange
        $this->setting('llm_openai_api_key', 'sk-o');
        $this->answer([[401, []]]);
        $_POST = ['provider' => 'openai'];
        $controller = $this->controller();

        // Act
        $controller->check();
        $this->answer([[200, []]]);
        $works = $this->controller();
        $works->check();
        $_POST = ['provider' => 'nope'];
        $unknown = $this->controller();
        $unknown->check();

        // Assert
        $this->assertStringStartsWith('OpenAI refused the key (HTTP 401)', $controller->errors[0]);
        $this->assertSame(['OpenAI answered. The key works.'], $works->messages);
        $this->assertSame(['There is no provider called nope.'], $unknown->errors);
    }

    /**
     * One call in full, or back to the list when it is not there.
     */
    public function testOneCallInFull(): void
    {
        // Arrange
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();
        $controller->log->found = ['call_id' => 0, 'model' => 'm'];

        // Act
        $controller->call();
        $missing = $this->controller();
        $missing->call();

        // Assert
        $this->assertSame('call', $controller->view->shown);
        $this->assertSame('m', $controller->view->call['model']);
        $this->assertSame(['That call is not in the log.'], $missing->errors);
    }

    /**
     * Below the floor nothing is shown, saved or tested.
     */
    public function testBelowTheFloorNothingHappens(): void
    {
        // Arrange
        $_POST = ['section' => 'general', 'provider' => 'openai'];
        $controller = $this->controller(refused: true);

        // Act
        $shown = $controller->settings();
        $controller->save();
        $controller->check();
        $call = $controller->call();

        // Assert
        $this->assertNull($shown);
        $this->assertNull($call);
        $this->assertNull($controller->view);
        $this->assertSame([], $controller->stored);
        $this->assertSame([], $controller->messages + $controller->errors);
    }

    /**
     * Both views render in every theme with real data, escape what they print, and never
     * print a key.
     */
    public function testEveryThemeRenders(): void
    {
        // Arrange
        $providers = [[
            'id' => 'deepseek', 'label' => 'DeepSeek', 'configured' => true, 'api_key_url' => 'https://k.example',
            'base_url' => '', 'default_url' => 'https://api.deepseek.com', 'model' => 'deepseek-chat',
            'models' => ['deepseek-chat' => 'Chat'], 'temperature' => '', 'max_tokens' => '', 'reasoning' => false,
            'consent' => true, 'place' => 'somewhere', 'balance' => ['balance_infos' => [['total_balance' => '3', 'currency' => 'USD']]],
        ]];
        $screen = [
            'providers' => $providers, 'selected' => 'deepseek', 'timeout' => 0, 'logging' => true,
            'features' => [['name' => 'chat', 'label' => 'Ask <AI>', 'values' => ['provider' => 'deepseek', 'model' => '', 'temperature' => '', 'max_tokens' => '', 'reasoning' => '1']]],
            'prices' => '{}', 'day' => ['calls' => 2, 'failed' => 1, 'tokens' => 30, 'cost' => 0.5, 'unpriced' => 1, 'ms' => 3],
            'month' => [], 'byModel' => [['model' => 'm', 'calls' => 2, 'cost' => 0.5, 'unpriced' => 1]],
            'calls' => [['call_id' => 9, 'created_at' => 'now', 'purpose' => 'chat', 'provider' => 'deepseek', 'model' => 'm',
                'tokens' => 30, 'cost' => null, 'error' => 'refused <the> key', 'http_status' => 401, 'finish_reason' => null]],
            'problems' => false, 'openai' => ['ok' => true, 'month_usd' => 1.5, 'meter_month_usd' => 1.4, 'balance_usd' => 10.0],
            'appSections' => [['key' => 'plans', 'title' => 'Plans', 'html' => '<input name="tokens">']], 'canEncrypt' => false,
        ];
        $call = ['call' => ['call_id' => 9, 'model' => 'm', 'error' => 'x', 'messages' => [['role' => 'user', 'content' => '<b>hi</b>']],
            'usage' => ['total_tokens' => 3], 'tokens' => 3, 'cost' => 0.1, 'system_prompt' => 'S', 'reply' => 'R']];

        foreach (self::THEMES as $theme) {
            // Act
            $page = $this->render($theme, 'ai/ai.html.php', $screen);
            $one  = $this->render($theme, 'ai/call.html.php', $call);

            // Assert
            $this->assertStringContainsString('Ask &lt;AI&gt;', $page, $theme);
            $this->assertStringContainsString('refused &lt;the&gt; key', $page, $theme);
            $this->assertStringContainsString('name="section" value="plans"', $page, $theme);
            $this->assertStringContainsString('key:generate', $page, $theme . ': warns when keys cannot be encrypted');
            $this->assertStringContainsString('1 not priced, not in the total', $page, $theme);
            $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $one, $theme);
        }
    }

    /**
     * The application's sections are drawn through a real View.
     *
     * The variable was `sections`, which is also View's own protected property: assigned from
     * the controller it went through `__set`, and the template — running in View's scope — read
     * the real, empty property. Every section saved and none was ever shown. A stand-in `$this`
     * cannot see that; only View can.
     */
    public function testTheSectionsAreDrawnThroughARealView(): void
    {
        // Arrange — the controller's own assignments, onto a real View
        $this->configure(['admin_sections' => [PlanSection::class]]);
        $controller = $this->controller();
        $controller->settings();
        $fed  = $controller->view;
        $dir  = dirname(__DIR__, 3) . '/scaffolding/themes/plain-css/views/ai';
        $view = new \Pramnos\Application\View(new \Pramnos\Application\Controller(), $dir, 'ai');
        $assigned = get_object_vars($fed);
        $this->assertNotEmpty($assigned, 'the sweep found nothing to check');
        foreach ($assigned as $name => $value) {
            if (!in_array($name, ['name', 'shown'], true)) {
                $view->$name = $value;
            }
        }

        // Act — the template, in View's scope, as display() runs it
        ob_start();
        \Closure::bind(function () use ($dir): void {
            include $dir . '/ai.html.php';
        }, $view, \Pramnos\Application\View::class)();
        $html = (string) ob_get_clean();

        // Assert
        $this->assertStringContainsString('Plan limits', $html);
        $this->assertStringContainsString('name="section" value="plans"', $html);
    }

    /**
     * Render one view with a stand-in `$this`.
     *
     * @param array<string, mixed> $properties
     */
    private function render(string $theme, string $relative, array $properties): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/' . $relative;
        $view = new #[\AllowDynamicProperties] class ($properties) {
            /** @param array<string, mixed> $properties */
            public function __construct(private array $properties)
            {
            }

            public function __get(string $name): mixed
            {
                return $this->properties[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->properties[$name]);
            }

            /** insert() and the other helpers: nothing to add. */
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };

        ob_start();
        try {
            (function () use ($path): void {
                include $path;
            })->call($view);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The real controller registers its actions as authenticated, and its write actions as
     * needing the session's token; its own seams answer what the screen reads.
     */
    public function testTheRealControllerIsWired(): void
    {
        // Arrange
        $controller = new AiController();
        $seam       = static fn (string $method, mixed ...$args): mixed => (new \ReflectionMethod($controller, $method))->invoke($controller, ...$args);

        // Assert
        $this->assertInstanceOf(CallLog::class, $seam('log'));
        $this->assertSame('mistral', $seam('connection', 'mistral')->provider());
        $this->assertNull($seam('openAiCosts'), 'no Admin key, no billing call');
        $this->assertContains('save', $controller->actions_auth);
        $this->assertContains('save', (new \ReflectionProperty($controller, 'writeActions'))->getValue($controller));
    }

    /** A controller whose view, settings, log, flash and usertype check are recorded. */
    private function controller(bool $refused = false): object
    {
        return new class ($refused) extends AiController {
            public ?object $view = null;

            /** @var array<string, string> */
            public array $stored = [];

            /** @var list<string> */
            public array $messages = [];

            /** @var list<string> */
            public array $errors = [];

            /** @var list<string> */
            public array $redirectedTo = [];

            public object $log;

            public function __construct(private bool $refused)
            {
                // Not parent::__construct(): it registers actions against an application this test does not have.
                $this->log = new class extends CallLog {
                    /** @var list<bool> */
                    public array $askedProblems = [];

                    /** @var array<string, mixed>|null */
                    public ?array $found = null;

                    public function recent(int $limit = 25, bool $problemsOnly = false): array
                    {
                        $this->askedProblems[] = $problemsOnly;

                        return [];
                    }

                    public function summary(int $hours = 24): array
                    {
                        return ['calls' => 0, 'failed' => 0, 'tokens' => 0, 'cost' => 0.0, 'unpriced' => 0, 'ms' => 0];
                    }

                    public function byModel(int $hours = 24): array
                    {
                        return [];
                    }

                    public function find(int $callId): ?array
                    {
                        return $this->found;
                    }
                };
            }

            protected function requireMinUserType($type): bool
            {
                return $this->refused;
            }

            public function &getView($name = '', $type = '', $args = array())
            {
                $this->view = new #[\AllowDynamicProperties] class ((string) $name) {
                    public string $shown = '';

                    public function __construct(public string $name)
                    {
                    }

                    public function display(string $template = ''): string
                    {
                        $this->shown = $template;

                        return '';
                    }
                };

                return $this->view;
            }

            protected function log(): CallLog
            {
                return $this->log;
            }

            protected function openAiCosts(): ?array
            {
                return null;
            }

            protected function store(string $name, string $value): void
            {
                $this->stored[$name] = $value;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo[] = (string) $url;
            }
        };
    }
}

/** An application's plan limits, as a section of the screen. */
final class PlanSection implements \Pramnos\Llm\Admin\Section
{
    public static string $saved = '';

    public function key(): string
    {
        return 'plans';
    }

    public function title(): string
    {
        return 'Plan limits';
    }

    public function render(): string
    {
        return '<input name="tokens">';
    }

    public function save(array $input): ?string
    {
        if (!ctype_digit((string) ($input['tokens'] ?? ''))) {
            return 'A number, please.';
        }

        self::$saved = (string) $input['tokens'];

        return null;
    }
}

/** An application's card that only shows: spend per plan, nothing to save. */
final class SpendCard implements \Pramnos\Llm\Admin\Card
{
    public function key(): string
    {
        return 'spend';
    }

    public function title(): string
    {
        return 'Spend per plan';
    }

    public function render(): string
    {
        return '<p data-spend>$12.40</p>';
    }
}

/** A section whose render throws. */
final class BrokenSection implements \Pramnos\Llm\Admin\Section
{
    public function key(): string
    {
        return 'broken';
    }

    public function title(): string
    {
        return 'Broken';
    }

    public function render(): string
    {
        throw new \RuntimeException('render broke');
    }

    public function save(array $input): ?string
    {
        return null;
    }
}
