<?php

namespace Pramnos\Console\Make;

/**
 * Loads scaffolding stub templates and performs {{ token }} substitution.
 *
 * Looks for templates at <stubsDir>/<name>.stub (the framework's
 * scaffolding/templates/ directory by default). Falls back to minimal
 * embedded skeletons when a file is absent, so commands work even in
 * environments where the scaffolding directory is missing.
 *
 * @package PramnosFramework
 */
class StubRenderer
{
    private string $stubsDir;

    /**
     * @param string $stubsDir Absolute path to the templates directory.
     *                         Pass an empty string to auto-resolve via ScaffoldingHelper.
     */
    public function __construct(string $stubsDir = '')
    {
        $this->stubsDir = $stubsDir !== ''
            ? $stubsDir
            : \Pramnos\Application\ScaffoldingHelper::resolveScaffoldingDir()
              . DIRECTORY_SEPARATOR . 'templates';
    }

    /**
     * Render a named stub template with {{ token }} substitution.
     *
     * Loads <stubsDir>/<stubName>.stub and replaces every {{ key }} occurrence
     * with the corresponding value from $tokens. Falls back to the embedded
     * skeleton for known stub names when the file is missing.
     *
     * @param string               $stubName Stub identifier without extension (e.g. 'middleware')
     * @param array<string,string> $tokens   Substitution map: key → replacement string
     * @return string Rendered content ready to be written to a file
     */
    public function render(string $stubName, array $tokens): string
    {
        $file    = $this->stubsDir . DIRECTORY_SEPARATOR . $stubName . '.stub';
        $content = file_exists($file)
            ? (string) file_get_contents($file)
            : $this->getFallbackStub($stubName);

        foreach ($tokens as $key => $value) {
            $content = str_replace('{{ ' . $key . ' }}', (string) $value, $content);
        }
        return $content;
    }

    /**
     * Return the minimal embedded skeleton for a stub name.
     *
     * These are intentionally lean — just enough for the generated file to be
     * syntactically valid and immediately runnable. The on-disk .stub files in
     * scaffolding/templates/ provide the richer, fully-documented versions.
     */
    public function getFallbackStub(string $name): string
    {
        return match ($name) {
            'middleware' =>
                "<?php\nnamespace {{ namespace }};\n\n"
                . "use Pramnos\\Http\\MiddlewareInterface;\n"
                . "use Pramnos\\Http\\Request;\n\n"
                . "/**\n * {{ class }} Middleware\n *\n * @package {{ namespace }}\n */\n"
                . "class {{ class }} implements MiddlewareInterface\n{\n"
                . "    public function handle(Request \$request, callable \$next): mixed\n    {\n"
                . "        \$response = \$next(\$request);\n"
                . "        return \$response;\n    }\n}\n",

            'event' =>
                "<?php\ndeclare(strict_types=1);\nnamespace {{ namespace }};\n\n"
                . "/**\n * {{ class }} Event\n *\n * @package {{ namespace }}\n */\n"
                . "class {{ class }}\n{\n"
                . "    public function __construct(\n"
                . "        // TODO: add public readonly properties for event payload\n"
                . "    ) {}\n}\n",

            'listener' =>
                "<?php\ndeclare(strict_types=1);\nnamespace {{ namespace }};\n\n"
                . "use Pramnos\\Event\\ListenerInterface;\n\n"
                . "/**\n * {{ class }} Listener\n *\n * @package {{ namespace }}\n */\n"
                . "class {{ class }} implements ListenerInterface\n{\n"
                . "    public function handle(mixed ...\$args): mixed\n    {\n"
                . "        return null;\n    }\n}\n",

            'migration' =>
                "<?php\nnamespace {{ namespace }};\n\n"
                . "use Pramnos\\Database\\Blueprint;\n"
                . "use Pramnos\\Database\\Migration;\n"
                . "use Pramnos\\Database\\SchemaBuilder;\n\n"
                . "/**\n * {{ class }} Migration\n *\n * @package {{ namespace }}\n */\n"
                . "final class {{ class }} extends Migration\n{\n"
                . "    public \$description = '{{ description }}';\n"
                . "    public bool \$transactional = false;\n\n"
                . "    public function up(): void\n    {\n{{ up_body }}\n    }\n\n"
                . "    public function down(): void\n    {\n{{ down_body }}\n    }\n}\n",

            'seeder' =>
                "<?php\nnamespace {{ namespace }};\n\n"
                . "use Pramnos\\Database\\Seeder;\n\n"
                . "/**\n * {{ class }} Seeder\n *\n * @package {{ namespace }}\n */\n"
                . "class {{ class }} extends Seeder\n{\n"
                . "    protected string \$table = '{{ table }}';\n\n"
                . "    public function run(): void\n    {\n"
                . "        for (\$i = 1; \$i <= {{ count }}; \$i++) {\n"
                . "            \$this->insert(\$this->table, [\n{{ fields }}\n            ]);\n"
                . "        }\n    }\n}\n",

            'controller' =>
                "<?php\nnamespace {{ namespace }};\n\n"
                . "use Pramnos\\Application\\Controller;\n\n"
                . "/**\n * {{ class }} Controller\n *\n * @package {{ namespace }}\n */\n"
                . "class {{ class }} extends Controller\n{\n"
                . "    public function __construct(?\\Pramnos\\Application\\Application \$application = null)\n    {\n"
                . "        \$this->addAuthAction(['edit', 'save', 'delete']);\n"
                . "        parent::__construct(\$application);\n    }\n\n"
                . "    public function display(): string\n    {\n"
                . "        \$view = \$this->getView('{{ view }}');\n"
                . "        return \$view->display();\n    }\n}\n",

            'model' =>
                "<?php\nnamespace {{ namespace }};\n\n"
                . "use Pramnos\\Application\\Model;\n\n"
                . "/**\n * {{ class }} Model\n *\n * @package {{ namespace }}\n */\n"
                . "class {{ class }} extends Model\n{\n"
                . "    protected \$_dbtable = '{{ table }}';\n"
                . "    protected \$_primaryKey = '{{ primaryKey }}';\n}\n",

            'test' =>
                "<?php\nnamespace Tests\\Unit;\n\n"
                . "use PHPUnit\\Framework\\TestCase;\n\n"
                . "/**\n * {{ class }}Test\n *\n * @package Tests\\Unit\n */\n"
                . "class {{ class }}Test extends TestCase\n{\n"
                . "    public function testInstantiation(): void\n    {\n"
                . "        \$instance = new \\{{ namespace }}\\{{ class }}();\n"
                . "        \$this->assertInstanceOf(\\{{ namespace }}\\{{ class }}::class, \$instance);\n"
                . "    }\n}\n",

            'controller_test' =>
                "<?php\nnamespace Tests\\Feature;\n\n"
                . "use PHPUnit\\Framework\\TestCase;\n"
                . "use Pramnos\\Testing\\TestClient;\n\n"
                . "/**\n * {{ class }}Test Feature Test\n *\n * @package Tests\\Feature\n */\n"
                . "class {{ class }}Test extends TestCase\n{\n"
                . "    public function testDisplayRouteReturnsSuccessfulResponse(): void\n    {\n"
                . "        \$client = new TestClient();\n"
                . "        \$response = \$client->get('/{{ route }}');\n"
                . "        \$response->assertSuccessful();\n"
                . "        \$response->assertSelectorExists('body');\n"
                . "    }\n}\n",

            default => '',
        };
    }
}
