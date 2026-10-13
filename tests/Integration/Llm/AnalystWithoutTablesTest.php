<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Tests\Support\ReusesProbeDatabase;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * An installation without a feature has no table for it: every analyst tool says so rather
 * than failing, so the model tells the administrator instead of guessing.
 */
#[CoversClass(\Pramnos\Llm\Analyst\Tools\AdminTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\UserTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\UsersTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\SessionsTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\OrganizationsTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\MailingListsTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\EmailTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\PushTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\QueueTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\LlmSpendTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\Window::class)]
class AnalystWithoutTablesTest extends LlmTestCase
{
    use ReusesProbeDatabase;

    private const PROBE = 'pramnos_analyst_bare_probe';

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;

        return $db;
    }

    /** A database of its own holding only the `llm` tables. */
    private function boot(string $type, string $host, int $port, string $user): void
    {
        $admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $llm = [];
        foreach (glob(ROOT . '/database/migrations/framework/llm/*.php') ?: [] as $file) {
            preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m);
            $llm[] = 'Pramnos\\Framework\\Migrations\\Llm\\' . $m[1];
        }

        $db = $this->openProbe($admin, self::PROBE, static fn (string $name): Database => self::connection($type, $host, $port, $user, $name), $llm);
        $admin->close();

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        parent::tearDown();
    }

    /** Only the `llm` tables exist here: the rest say they are not kept; a bad window is said. */
    #[DataProvider('databases')]
    public function testNothingToRead(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $tools = Analyst::tools();

        // Act
        $answers = [];
        foreach (['users', 'sessions', 'organizations', 'mailing_lists', 'email', 'push', 'queue'] as $name) {
            $answers[$name] = json_decode(($tools[$name]['run'])([]), true);
        }

        // Assert
        $this->assertNotEmpty($answers, 'the sweep found nothing to check');
        foreach ($answers as $name => $answer) {
            $this->assertFalse($answer['available'], $name);
            $this->assertStringStartsWith('This installation keeps no ', $answer['says'], $name);
        }
        $this->assertSame('This installation keeps no user accounts.', ($tools['user']['run'])(['userid' => 2]));
        $this->assertSame('Error: a date is YYYY-MM-DD: "soon" is not.', ($tools['llm_spend']['run'])(['from' => 'soon']));
        $this->assertSame(0, json_decode(($tools['llm_spend']['run'])([]), true)['calls']);
    }
}
