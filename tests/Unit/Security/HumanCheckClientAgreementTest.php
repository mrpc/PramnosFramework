<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\HumanCheck;

/**
 * The client and the server agree — proved by running the client.
 *
 * This is the test that was missing, and its absence cost a production login. The server-side
 * verification had unit tests, the client-side solver had none, and nothing checked that the
 * two computed the same thing. When the client could not run at all, the visitor was told
 * «your browser must support JavaScript» while using a browser that supported it fine.
 *
 * A test that re-implemented the hash in PHP would have agreed with itself and proved nothing.
 * So this loads `scaffolding/assets/js/pf-humancheck.js` — the exact bytes a browser is served —
 * under Node, asks it to solve a challenge minted by `HumanCheck`, and verifies the answer with
 * `HumanCheck::verify()`. The two implementations are compared by making them do the job.
 *
 * ## Four paths, because there are four
 *
 * `crypto.subtle` exists only in a secure context: HTTPS, or localhost. A site reached over
 * plain HTTP by hostname or LAN address — a staging box, a colleague's machine, a tablet on the
 * office network — has none, and a client that needed it could not solve the challenge there at
 * all. Each mode below withholds a capability the way such a browser does, and every one of
 * them has to produce an answer the server accepts.
 *
 * ## When Node is missing
 *
 * The test skips, loudly. That is not ideal and it is better than the alternatives: a test that
 * cannot run is visible in the output, and one that silently passes is not.
 */
#[CoversClass(HumanCheck::class)]
class HumanCheckClientAgreementTest extends TestCase
{
    /** Low enough to be quick even in the pure-JS path, high enough to be a real search. */
    private const BITS = 12;

    private function harness(): string
    {
        return dirname(__DIR__, 2) . '/Support/humancheck-agreement.mjs';
    }

    private function clientScript(): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/assets/js/pf-humancheck.js';
    }

    private function requireNode(): void
    {
        exec('node --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped(
                'node is not on this machine, so the shipped client-side solver cannot be run. '
                . 'This is the only test that proves the browser and the server agree — run it '
                . 'somewhere with Node before shipping a change to pf-humancheck.js.'
            );
        }
    }

    /**
     * Named `solveWith` rather than `run`: `TestCase::run()` is final, and overriding it is a
     * fatal error rather than a failing test.
     *
     * @return array<int, string> The lines Node printed
     */
    private function solveWith(string $mode, string $payload): array
    {
        $command = escapeshellarg(PHP_BINARY === '' ? 'node' : 'node')
            . ' ' . escapeshellarg($this->harness())
            . ' ' . escapeshellarg($this->clientScript())
            . ' ' . escapeshellarg($payload)
            . ' ' . escapeshellarg((string) self::BITS)
            . ' ' . escapeshellarg($mode)
            . ' 2>&1';

        exec($command, $output, $status);

        $this->assertSame(0, $status, "node failed:\n" . implode("\n", $output));

        return $output;
    }

    /** @return array<string, array{string}> */
    public static function paths(): array
    {
        return [
            'a Worker and Web Crypto — what almost everybody gets' => ['worker-subtle'],
            'a Worker, no Web Crypto — plain HTTP, not localhost'  => ['worker-purejs'],
            'no Worker, Web Crypto — some embedded webviews'       => ['main-subtle'],
            'neither — everything else with JavaScript'            => ['main-purejs'],
        ];
    }

    /**
     * Every path the client can take produces a solution this server accepts.
     *
     * The challenge is minted by `HumanCheck` and verified by `HumanCheck`, so what is being
     * tested is the client: the payload derivation (the signature is not hashed and the client
     * has to know that), the separator between payload and candidate, the hash, the
     * leading-zero-*bit* count, and the base-36 encoding of the candidate it sends back.
     *
     * Any one of those being wrong looks identical from production: a refused login.
     */
    #[DataProvider('paths')]
    public function testEveryClientPathProducesASolutionTheServerAccepts(string $mode): void
    {
        // Arrange
        $this->requireNode();

        $check     = new HumanCheck(1, 600, 'a-fixed-key-for-this-test');
        $challenge = $check->challenge();
        [$nonce, $bits, $expires] = explode('.', $challenge['challenge']);
        $payload = $nonce . '.' . self::BITS . '.' . $expires;

        // Act — the shipped script, in a scope with only this path's capabilities
        $output   = $this->solveWith($mode, $payload);
        $solution = trim((string) end($output));

        // Assert
        $this->assertNotSame('', $solution, 'the client produced no solution at all');
        $this->assertTrue(
            $check->meetsDifficulty($payload, $solution, self::BITS),
            $mode . ': the server does not accept what the browser computed — solution '
            . '"' . $solution . '" for payload ' . $payload
        );
    }

    /**
     * The client's own SHA-256 is SHA-256.
     *
     * It exists because `crypto.subtle` is absent in an insecure context, and it is the one
     * piece of cryptography this project implements rather than borrows — so it is compared
     * against a real implementation over empty input, ASCII, a long string, Greek text and an
     * emoji. The last two are surrogate pairs and multi-byte UTF-8, which is exactly where a
     * hand-written encoder goes wrong.
     */
    public function testTheClientsOwnSha256IsSha256(): void
    {
        // Arrange
        $this->requireNode();

        // Act
        $output = $this->solveWith('hash', 'unused');

        // Assert
        $this->assertSame('ok', trim((string) end($output)));
    }

    /**
     * And a solution from one path verifies end to end, single use included.
     *
     * `verify()` rather than `meetsDifficulty()`: the signature, the expiry and the single-use
     * claim are part of what the browser's answer has to survive, and the claim is the one that
     * would let a replay through.
     */
    public function testASolutionFromTheBrowserSurvivesTheWholeVerification(): void
    {
        // Arrange
        $this->requireNode();

        $check = new class(1, 600, 'a-fixed-key-for-this-test') extends HumanCheck {
            /** The cache is a live dependency; single use is asserted through this instead. */
            protected function claim(string $nonce, int $expires): bool
            {
                return !isset($this->claimed[$nonce]) && ($this->claimed[$nonce] = true);
            }

            /** @var array<string, bool> */
            private array $claimed = [];
        };

        $challenge = $check->challenge();
        [$nonce, , $expires] = explode('.', $challenge['challenge']);

        // The difficulty is signed, so the challenge has to be re-minted at the difficulty this
        // test solves at rather than edited.
        $solved = new HumanCheck(1, 600, 'a-fixed-key-for-this-test');

        // Act
        $output   = $this->solveWith('worker-subtle', $nonce . '.' . $challenge['difficulty'] . '.' . $expires);
        $solution = trim((string) end($output));

        // Assert
        $this->assertTrue(
            $check->verify($challenge['challenge'], $solution),
            'a solution the browser computed has to pass the whole verification, not only the '
            . 'difficulty check'
        );
        $this->assertFalse(
            $check->verify($challenge['challenge'], $solution),
            'and it must not pass twice'
        );
    }
}
