<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Nothing low-level constructs an application in order to read one.
 *
 * WHAT: no file under `src/Pramnos/Auth/`, `src/Pramnos/Http/Middleware/`,
 *       `src/Pramnos/User/` or `src/Pramnos/Database/` calls `Application::getInstance()`.
 *       `currentInstance()` is the lookup; `getInstance()` is a factory that reads
 *       `app.php`, defines constants and runs the whole constructor — database, language,
 *       session.
 *
 * WHY:  the rule was already written down, in the Framework Guide and in
 *       `currentInstance()`'s own docblock, together with the incident behind it:
 *       `Session::getFingerprint()` began asking for the trusted-proxy list, and a reference
 *       application's login tests started failing on valid tokens because a second
 *       application was being constructed underneath them.
 *
 *       Five call sites in the authentication code were using the factory anyway, and every
 *       one was written as `if ($app)` — a guard for a null the call cannot return. So the
 *       guard was dead and the construction was live, in the middle of a security decision,
 *       and the source said so the whole time.
 *
 * Read from the source rather than executed, for the same reason as
 * {@see ConnectionPathPurityTest}: the call is wrong on the runs where it happens to work,
 * so behaviour cannot catch it. The companion behavioural test —
 * `NoApplicationIsConstructedTest` — proves the five known sites; this one is what stops the
 * sixth being added, which is the failure mode that matters, because `getInstance()` is the
 * name one remembers.
 *
 * The prose in the guide already said all of this. Prose demonstrably did not prevent it.
 */
class ApplicationFactoryPurityTest extends TestCase
{
    /**
     * Directories whose contents must never build an application.
     *
     * All four run *inside* a request that already has one, so the lookup is always the
     * correct call and the factory can only ever be a side effect — and in the database
     * layer's case, a cycle.
     *
     * @var list<string>
     */
    private const GUARDED_DIRECTORIES = [
        'src/Pramnos/Auth',
        'src/Pramnos/Http/Middleware',
        // Identity: `User::getCurrentUser()` held the worst placement of the factory in the
        // framework — asking *who is signed in* constructed an entire application.
        'src/Pramnos/User',
        // The database layer, where the factory is self-defeating: building an application
        // builds `Settings`, which queries the database. `Database::displayError()` did that
        // while reporting a database error, which also made its own `error_log()` fallback
        // unreachable.
        'src/Pramnos/Database',
    ];

    /**
     * Files allowed to call the factory, with the reason, enumerated and never inferred.
     *
     * Empty on purpose. A file cannot become exempt by being added — somebody has to write
     * its name here and say why, which is the point: the exemption is the conversation.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [];

    /**
     * The repository root.
     *
     * Counted rather than assumed. A structural guard in this repository once resolved
     * `dirname(__DIR__, 5)` to a path outside the tree, scanned zero files, and passed every
     * assertion it made about them — which is why {@see testTheGuardActuallyScansFiles}
     * exists below.
     *
     * @return string
     */
    private function root(): string
    {
        // tests/Unit/Framework -> tests/Unit -> tests -> the repository
        return dirname(__DIR__, 3);
    }

    /**
     * Every PHP file under the guarded directories, recursively.
     *
     * @return array<string, string> Repository-relative path => contents
     */
    private function guardedFiles(): array
    {
        $files = [];

        foreach (self::GUARDED_DIRECTORIES as $relative) {
            $directory = $this->root() . '/' . $relative;
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path     = str_replace($this->root() . '/', '', $file->getPathname());
                $contents = file_get_contents($file->getPathname());

                if ($contents !== false) {
                    $files[$path] = $contents;
                }
            }
        }

        return $files;
    }

    /**
     * The guard reads a real, non-trivial number of files.
     *
     * **The assertion that makes every other one in this class mean something.** A wrong path
     * produces an empty scan, and an empty scan satisfies "nothing calls the factory"
     * perfectly. This is not a hypothetical failure mode in this repository; it has happened.
     *
     * @return void
     */
    public function testTheGuardActuallyScansFiles(): void
    {
        // Act
        $files = $this->guardedFiles();

        // Assert
        $this->assertGreaterThan(
            30,
            count($files),
            'These are dozens of files; a handful means the path is wrong.'
        );

        // And they are the files intended, not some other directory that happens to exist.
        // One named per guarded directory, so a directory silently dropped from the list
        // fails here rather than passing by covering nothing.
        $this->assertArrayHasKey('src/Pramnos/Auth/SessionExchange.php', $files);
        $this->assertArrayHasKey('src/Pramnos/Http/Middleware/ApiAuthMiddleware.php', $files);
        $this->assertArrayHasKey('src/Pramnos/User/User.php', $files);
        $this->assertArrayHasKey('src/Pramnos/Database/Database.php', $files);
    }

    /**
     * No guarded file calls `Application::getInstance()`.
     *
     * The rule itself. Reported per file so a failure names what to change rather than only
     * that something is wrong.
     *
     * @return void
     */
    public function testNoLowLevelCodeCallsTheApplicationFactory(): void
    {
        // Arrange
        $offenders = [];

        // Act
        foreach ($this->guardedFiles() as $path => $contents) {
            if (isset(self::EXEMPT[$path])) {
                continue;
            }

            // Matches `Application::getInstance(` with or without a leading namespace, and
            // deliberately not `currentInstance(`. Comments are stripped first: this class
            // and the files it guards both discuss `getInstance()` in prose, and a guard
            // that cannot tell a call from an explanation of why not to make one would make
            // documenting the rule impossible.
            $code = $this->withoutComments($contents);

            if (preg_match('/Application::getInstance\s*\(/', $code)) {
                $offenders[] = $path;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These files build an application where they should look one up.\n"
            . "Use Application::currentInstance() — it returns null instead of "
            . "constructing a database, a language and a session.\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * The lookup is in use, so the rule is being followed rather than merely not broken.
     *
     * Without this, deleting every application read from the guarded code would pass the
     * test above. The rule is *which call*, not *no call*.
     *
     * @return void
     */
    public function testTheLookupIsActuallyUsed(): void
    {
        // Arrange & Act
        $users = [];
        foreach ($this->guardedFiles() as $path => $contents) {
            if (preg_match('/Application::currentInstance\s*\(/', $this->withoutComments($contents))) {
                $users[] = $path;
            }
        }

        // Assert
        $this->assertGreaterThanOrEqual(
            6,
            count($users),
            'The converted call sites live in six files; all six should still read it.'
        );
    }

    /**
     * Source with comments and doc-blocks removed.
     *
     * `token_get_all()` rather than a regular expression: a `//` inside a string literal is
     * not a comment, and this guard must not be defeated — or triggered — by prose.
     *
     * @param  string $source PHP source
     * @return string
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
