<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\CacheClear;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the cache:clear console command.
 *
 * cache:clear flushes the application cache, either entirely or a single
 * category via --category. It delegates the actual work to the cache adapter
 * (Cache::getInstance()->clear()). Its command-level contract is:
 *
 *  - A successful clear of everything prints "Cache cleared." and exits SUCCESS.
 *  - A successful clear of a named category prints "Cache category '<x>' cleared."
 *    and exits SUCCESS.
 *  - When the adapter returns false (cache disabled / nothing to clear), the
 *    command prints a "Nothing cleared" comment and still exits SUCCESS.
 *  - When the adapter throws, the command reports the error and exits FAILURE.
 *
 * The adapter call is isolated behind the protected CacheClear::clearCache()
 * seam so these tests never touch the real static Cache singleton (which would
 * require a live cache backend). Each test injects a test double subclass that
 * overrides clearCache() with a controlled behaviour, exactly mirroring the
 * production delegation.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(CacheClear::class)]
class CacheClearTest extends TestCase
{
    // =========================================================================
    // Infrastructure
    // =========================================================================

    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /** @var CacheClear|null The command built by the last makeTester() call. */
    private ?CacheClear $lastCommand = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }


    /**
     * `cache:clear` is scoped to this installation, so a global flush has to be
     * asked for by name.
     *
     * The default used to flush the whole backend — which, since `cache:clear`
     * runs on most deploys, quietly emptied every co-tenant installation's
     * sessions and caches on every release.
     */
    public function testAllOptionFlushesTheEntireBackend(): void
    {
        // Arrange
        $seen = null;
        $tester = $this->makeTester(function (string $category) use (&$seen): bool {
            $seen = $category;
            return true;
        });

        // Act
        $exit = $tester->execute(['--all' => true]);

        // Assert — the global seam was used, and the message says so
        $this->assertSame(0, $exit);
        $this->assertSame('__ALL__', $seen, 'the global flush seam must be the one called');
        $this->assertStringContainsString('Entire cache backend flushed', $tester->getDisplay());
    }

    /**
     * --all and --category ask for opposite things; accepting both would make
     * the outcome depend on argument order.
     */
    public function testAllAndCategoryAreMutuallyExclusive(): void
    {
        // Arrange
        $called = false;
        $tester = $this->makeTester(function () use (&$called): bool {
            $called = true;
            return true;
        });

        // Act
        $exit = $tester->execute(['--all' => true, '--category' => 'news']);

        // Assert — refused before anything was cleared
        $this->assertSame(1, $exit);
        $this->assertFalse($called, 'nothing may be cleared when the request is contradictory');
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
    }

    /**
     * Build a CommandTester around a CacheClear whose clearCache() seam is
     * replaced by the supplied callable. The callable receives the category
     * string and either returns a bool or throws — this lets each test drive a
     * single branch of execute() without any real cache backend.
     *
     * @param callable(string):bool $clearImpl
     */
    private function makeTester(callable $clearImpl): CommandTester
    {
        // Anonymous subclass overriding only the cache-adapter seam.
        $command = new class($clearImpl) extends CacheClear {
            /** @var callable(string):bool */
            private $clearImpl;

            public function __construct(callable $clearImpl)
            {
                $this->clearImpl = $clearImpl;
                parent::__construct();
            }

            /** @var bool Whether the global flush seam was used. */
            public bool $flushedEverything = false;

            protected function clearCache(string $category): bool
            {
                return ($this->clearImpl)($category);
            }

            protected function flushEverything(): bool
            {
                $this->flushedEverything = true;
                return ($this->clearImpl)('__ALL__');
            }
        };
        $this->lastCommand = $command;

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        $found = $app->find('cache:clear');
        return new CommandTester($found);
    }

    // =========================================================================
    // Success paths
    // =========================================================================

    /**
     * With no --category and a successful adapter clear, the command must flush
     * everything, report the generic "Cache cleared." message and exit SUCCESS.
     * This is the default invocation users run most often.
     */
    public function testClearsAllCategoriesSuccessfully(): void
    {
        // Arrange — adapter reports success; capture the category it was given
        $seen  = null;
        $tester = $this->makeTester(function (string $category) use (&$seen): bool {
            $seen = $category;
            return true;
        });

        // Act
        $exitCode = $tester->execute([]);
        $output   = $tester->getDisplay();

        // Assert — clean exit and the "everything" message
        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('Cache cleared for this installation.', $output);

        // Assert — an empty category ('') was passed, i.e. "clear everything"
        $this->assertSame('', $seen, 'Default invocation must request clearing all categories');
    }

    /**
     * With --category=<name> and a successful adapter clear, the command must
     * pass that category through and report the category-specific message.
     * This proves the single-category path and correct option plumbing.
     */
    public function testClearsSingleCategorySuccessfully(): void
    {
        // Arrange
        $seen  = null;
        $tester = $this->makeTester(function (string $category) use (&$seen): bool {
            $seen = $category;
            return true;
        });

        // Act
        $exitCode = $tester->execute(['--category' => 'views']);
        $output   = $tester->getDisplay();

        // Assert — success and the category-specific confirmation
        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString("Cache category 'views' cleared.", $output);

        // Assert — the option value reached the adapter unchanged
        $this->assertSame('views', $seen);
    }

    // =========================================================================
    // Adapter returns false
    // =========================================================================

    /**
     * When the adapter returns false (cache disabled or nothing to clear), the
     * command must NOT claim success text but print the "Nothing cleared"
     * comment — while still exiting SUCCESS, because a no-op is not an error.
     */
    public function testAdapterReturnsFalsePrintsNothingCleared(): void
    {
        // Arrange — adapter reports it cleared nothing
        $tester = $this->makeTester(fn (string $category): bool => false);

        // Act
        $exitCode = $tester->execute([]);
        $output   = $tester->getDisplay();

        // Assert — exit is still SUCCESS (a disabled cache is not a failure)
        $this->assertSame(Command::SUCCESS, $exitCode, $output);

        // Assert — the informational "nothing cleared" branch was taken
        $this->assertStringContainsString('Nothing cleared', $output);

        // Assert — the success confirmation was NOT printed
        $this->assertStringNotContainsString('Cache cleared.', $output);
    }

    // =========================================================================
    // Adapter throws
    // =========================================================================

    /**
     * When the adapter throws, the command must catch it, print the error
     * message and exit FAILURE rather than letting the exception surface. This
     * guarantees the CLI degrades cleanly on a broken cache backend.
     */
    public function testAdapterThrowsReturnsFailure(): void
    {
        // Arrange — adapter raises an exception mid-clear
        $tester = $this->makeTester(function (string $category): bool {
            throw new \RuntimeException('backend unreachable');
        });

        // Act
        $exitCode = $tester->execute([]);
        $output   = $tester->getDisplay();

        // Assert — the failure exit code is returned
        $this->assertSame(Command::FAILURE, $exitCode, $output);

        // Assert — the error message surfaces the underlying cause
        $this->assertStringContainsString('Cache clear failed', $output);
        $this->assertStringContainsString('backend unreachable', $output);
    }
}
