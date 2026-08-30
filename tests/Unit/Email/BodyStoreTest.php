<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Email\BodyStore;

/**
 * Where a sent message's body lives once it stops living in the database.
 *
 * The property everything here defends is that **nothing is lost**. That is the whole
 * difference from emptying the column, which is the other way to make `mails` small and costs
 * every question the preview screen can answer.
 */
#[CoversClass(BodyStore::class)]
class BodyStoreTest extends TestCase
{
    private string $root;

    private mixed $previous = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bodystore-' . bin2hex(random_bytes(5));

        $app = Application::getInstance();
        $this->previous = $app->applicationInfo['mail'] ?? null;
        $app->applicationInfo['mail'] = ['body_store' => ['enabled' => true, 'path' => $this->root]];
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->previous === null) {
            unset($app->applicationInfo['mail']);
        } else {
            $app->applicationInfo['mail'] = $this->previous;
        }

        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * A body goes in and comes back out, byte for byte.
     *
     * The only property that matters. Everything else here is about the cases where it must
     * still hold.
     */
    public function testABodyRoundTrips(): void
    {
        // Arrange
        $html = '<html><body><p>Καλώς ήρθατε — your code is 481920</p></body></html>';

        // Act
        $path = BodyStore::put($html, mktime(0, 0, 0, 8, 29, 2026));

        // Assert
        $this->assertNotNull($path);
        $this->assertSame($html, BodyStore::get($path));
    }

    /**
     * The same body twice is one file.
     *
     * This is the reason to address a body by its digest, and it is the case that makes this
     * table large in the first place: a campaign to forty thousand people is one body, sent
     * forty thousand times. Stored per row it is forty thousand copies of the same document.
     */
    public function testTheSameBodyTwiceIsOneFile(): void
    {
        // Arrange
        $html = str_repeat('<p>Newsletter</p>', 100);
        $when = mktime(0, 0, 0, 8, 29, 2026);

        // Act
        $first  = BodyStore::put($html, $when);
        $second = BodyStore::put($html, $when + 3600);

        // Assert
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->files());
    }

    /**
     * A personalised body is a different file, and that is the limit of the deduplication.
     *
     * The claim worth being precise about, because it is easy to state too broadly. The store is
     * content-addressed, so *identical* bodies are stored once — and the inbox copies of a mass
     * message genuinely are identical, which is where that property pays.
     *
     * A **mailed** campaign is not. `offerUnsubscribe()` puts a per-recipient token in the
     * wrapper and tracking gives each recipient its own pixel id, and `Email::send()` logs the
     * rendered body precisely because "the mailer sends this and the audit log records it, so
     * they have to be the same string". So forty thousand recipients are forty thousand distinct
     * bodies and forty thousand files.
     *
     * That is not a defect to fix here — it is what an honest audit trail costs. Compression
     * still applies to every one of them; deduplication does not. Anything that reads "a campaign
     * is one file" without saying *inbox* copies is wrong.
     */
    public function testAPersonalisedBodyIsNotDeduplicated(): void
    {
        // Arrange — one campaign, three recipients, differing only in their tokens
        $wrapper = '<html><body><h1>Προσφορά</h1>' . str_repeat('<p>Κείμενο</p>', 40);
        $paths   = [];

        foreach (['alpha', 'beta', 'gamma'] as $token) {
            // Act
            $paths[] = BodyStore::put(
                $wrapper . '<a href="https://example.gr/u/' . $token . '">Διαγραφή</a>'
                . '</body></html>',
                mktime(0, 0, 0, 8, 31, 2026)
            );
        }

        // Assert
        $this->assertCount(3, array_unique($paths),
            'a per-recipient token makes a per-recipient file — the deduplication does not reach '
            . 'a mailed campaign, only the identical inbox copies of one');
    }

    /**
     * A different body is a different file.
     */
    public function testADifferentBodyIsADifferentFile(): void
    {
        // Arrange
        $when = mktime(0, 0, 0, 8, 29, 2026);

        // Act
        BodyStore::put('<p>one</p>' . str_repeat('x', 600), $when);
        BodyStore::put('<p>two</p>' . str_repeat('x', 600), $when);

        // Assert
        $this->assertCount(2, $this->files());
    }

    /**
     * The partition is the message's own month, not today's.
     *
     * An archive run in October moving August's mail has to put August's mail in August, or the
     * dated layout says nothing — and "remove 2023" becomes a query rather than a directory.
     */
    public function testThePartitionIsTheMessagesOwnMonth(): void
    {
        // Act
        $path = BodyStore::put('<p>' . str_repeat('a', 600) . '</p>', mktime(0, 0, 0, 3, 4, 2024));

        // Assert
        $this->assertStringStartsWith('2024/03/', (string) $path);
    }

    /**
     * The same body in two months is two files, and that is the trade.
     *
     * Dedup within a partition, dated partitions across them. The campaign — one moment, one
     * month — keeps its dedup, and an operator keeps being able to act on a period without
     * consulting the database.
     */
    public function testTheDatedPartitionCostsDedupAcrossMonths(): void
    {
        // Arrange
        $html = '<p>' . str_repeat('a', 600) . '</p>';

        // Act
        $august  = BodyStore::put($html, mktime(0, 0, 0, 8, 29, 2026));
        $october = BodyStore::put($html, mktime(0, 0, 0, 10, 1, 2026));

        // Assert
        $this->assertNotSame($august, $october);
        $this->assertCount(2, $this->files());
    }

    /**
     * Nothing half-written is left behind.
     *
     * A file is addressed by the digest of its contents, so a truncated one is a file whose
     * name is a promise it does not keep — and the next send of the same body would find it
     * there and record a path to a broken document.
     */
    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        // Act
        BodyStore::put('<p>' . str_repeat('a', 5000) . '</p>', time());

        // Assert
        $this->assertSame([], glob($this->root . '/*/*/*/*.tmp') ?: []);

        foreach ($this->files() as $file) {
            $this->assertStringEndsWith('.html.gz', $file);
        }
    }

    /**
     * An empty body is not stored.
     */
    public function testAnEmptyBodyIsNotStored(): void
    {
        // Assert
        $this->assertNull(BodyStore::put(''));
        $this->assertNull(BodyStore::put('   '));
    }

    /**
     * The inline body wins, and the file is the fallback.
     *
     * Both halves matter: an installation that has never archived anything must not read a
     * file, and one that has must not see an empty message.
     */
    public function testTheReaderTakesTheBodyFromWhereverItIs(): void
    {
        // Arrange
        $path = BodyStore::put('<p>' . str_repeat('a', 600) . '</p>', time());

        // Assert
        $this->assertSame('inline', BodyStore::bodyOf(['content' => 'inline', 'bodypath' => $path]));
        $this->assertStringContainsString('<p>', BodyStore::bodyOf(['content' => '', 'bodypath' => $path]));
        $this->assertSame('', BodyStore::bodyOf(['content' => '', 'bodypath' => '']));
        $this->assertSame('', BodyStore::bodyOf([]));
    }

    /**
     * A path that is not one of ours is not read.
     *
     * The value comes from a database column, and a column is whatever was put in it. Checked
     * against the shape rather than resolved and compared, because `realpath()` on a file that
     * does not exist returns false — so that check would pass for every deleted body.
     */
    public function testAPathThatIsNotOursIsNotRead(): void
    {
        foreach ([
            '../../../../etc/passwd',
            '2026/08/3f/../../../../etc/passwd',
            '/etc/passwd',
            '2026/08/3f/notadigest.html.gz',
            'anything.html.gz',
        ] as $path) {
            // Assert
            $this->assertNull(BodyStore::get($path), $path);
            $this->assertSame(0, BodyStore::bytes($path), $path);
            $this->assertFalse(BodyStore::forget($path), $path);
        }
    }

    /**
     * A body whose file has gone reads as empty rather than as an error.
     *
     * Somebody moved the directory, or restored a database without the disk beside it. The
     * screen shows no body, which is true, instead of failing to render.
     */
    public function testAMissingFileIsEmptyRatherThanFatal(): void
    {
        // Arrange
        $path = BodyStore::put('<p>' . str_repeat('a', 600) . '</p>', time());
        unlink($this->root . '/' . $path);

        // Assert
        $this->assertNull(BodyStore::get($path));
        $this->assertSame('', BodyStore::bodyOf(['content' => '', 'bodypath' => $path]));
    }

    /**
     * It is on unless an installation says otherwise.
     *
     * The invariant everything else rests on — *the body is not in the database* — is only worth
     * having if it holds without anybody opting in. The installations that never make the
     * decision are the ones whose `mails` grows unwatched, and the body is the whole of that
     * growth.
     *
     * Only a literal `false` turns it off. Not `0`, not `'no'`: a config value that is not the
     * one written here is a typo, and a typo that silently moves an audit trail back into the
     * database is the failure this direction is meant to avoid.
     */
    public function testItIsOnUnlessSwitchedOff(): void
    {
        // Arrange
        $app = Application::getInstance();

        foreach ([null, true, 0, '1', 'yes', ''] as $value) {
            $app->applicationInfo['mail'] = ['body_store' => ['enabled' => $value]];

            // Assert
            $this->assertTrue(BodyStore::enabled(), var_export($value, true));
        }

        $app->applicationInfo['mail'] = ['body_store' => ['enabled' => false]];
        $this->assertFalse(BodyStore::enabled());
    }

    /**
     * With nothing configured at all, it is on.
     *
     * Not the same test as the one above: that one sets `enabled` to something. This is the
     * installation that has never heard of the setting, which is the case the default exists for.
     */
    public function testAnInstallationThatConfiguredNothingGetsTheStore(): void
    {
        // Arrange
        $app = Application::getInstance();
        unset($app->applicationInfo['mail']);

        // Assert
        $this->assertTrue(BodyStore::enabled());
    }

    /**
     * No size threshold: a short body goes to the store like any other.
     *
     * It was 512 bytes, and the arithmetic behind it was right — a 4 KB block and an inode for
     * two hundred bytes is a bad trade on disk. It was still the wrong call, because it put an
     * "unless" into the one sentence this store exists to make true, and every answer resting on
     * that sentence inherited it: what a GDPR erasure must clear, how large `mails` can get,
     * whether `var/mails` is the backup that matters.
     */
    public function testAShortBodyIsStoredToo(): void
    {
        // Arrange
        $body = 'Hi.';

        // Act
        $path = BodyStore::put($body, mktime(0, 0, 0, 8, 31, 2026));

        // Assert
        $this->assertNotNull($path, 'a three-byte body must still leave the database');
        $this->assertSame($body, BodyStore::bodyOf(['content' => '', 'bodypath' => $path]));
        $this->assertSame(0, BodyStore::MIN_BYTES, 'the threshold is retired, not re-tuned');
    }

    /**
     * The store lives outside the web root.
     *
     * A mail body is the contents of somebody's message. The one thing it must never be is
     * served, and `var/` is the directory this framework already keeps out of the web root and
     * out of git.
     */
    public function testTheDefaultLocationIsNotServable(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['mail'] = ['body_store' => ['enabled' => true]];

        // Act
        $root = BodyStore::root();

        /*
         * Assert against the *application's* web root, not against the string "www".
         *
         * The first version of this test looked for "/www/" anywhere in the path and failed on
         * a container whose application root is `/var/www/html` — a check that was reading the
         * hosting layout rather than the thing it meant.
         */
        $webroot = (defined('ROOT') ? (string) ROOT : '') . DIRECTORY_SEPARATOR . 'www';

        // Assert
        $this->assertStringEndsWith('mails', $root);
        $this->assertStringNotContainsString($webroot, $root,
            'a mail body is personal data, and the one thing it must never be is served');
        $this->assertStringContainsString('var', $root);
    }

    /**
     * It compresses, which is the point.
     */
    public function testItIsStoredCompressed(): void
    {
        // Arrange — a realistic mail: mostly repeated markup
        $html = str_repeat('<tr><td style="padding:12px;font-family:Helvetica">Row</td></tr>', 200);

        // Act
        $path = BodyStore::put($html, time());

        // Assert
        $this->assertLessThan(strlen($html) / 5, BodyStore::bytes((string) $path));
        $this->assertSame($html, BodyStore::get((string) $path));
    }

    /**
     * A store it cannot write to reports failure rather than pretending.
     *
     * The row keeps its body in that case, so a full disk costs a large table rather than a
     * lost message — but only because `put()` is honest about having failed.
     */
    public function testAStoreItCannotWriteToReturnsNull(): void
    {
        // Arrange — a file where the store's directory has to go
        file_put_contents($this->root, 'not a directory');

        try {
            // Assert
            $this->assertNull(BodyStore::put('<p>' . str_repeat('a', 600) . '</p>', time()));
        } finally {
            @unlink($this->root);
        }
    }

    /**
     * With nothing configured, the store still has a home under `var/`.
     *
     * Nothing has to be decided: neither `enabled` nor `path` is a setting an installation
     * must write before its bodies have somewhere to go.
     */
    public function testThePathIsOptional(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['mail'] = ['body_store' => ['enabled' => true]];

        // Assert
        $this->assertStringContainsString('mails', BodyStore::root());
    }

    /**
     * A configured path is used as given, trimmed of a trailing separator.
     */
    public function testAConfiguredPathIsUsed(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['mail'] =
            ['body_store' => ['enabled' => true, 'path' => '/tmp/somewhere/else/']];

        // Assert
        $this->assertSame('/tmp/somewhere/else', BodyStore::root());
    }

    /**
     * A file that is not gzip reads as nothing rather than as rubbish.
     *
     * A half-restored backup, a file touched by hand. Returning the raw bytes would put binary
     * into an iframe on the preview screen.
     */
    public function testAFileThatIsNotGzipIsNotReturned(): void
    {
        // Arrange
        $path = BodyStore::put('<p>' . str_repeat('a', 600) . '</p>', time());
        file_put_contents($this->root . '/' . $path, 'this is not gzip');

        // Assert
        $this->assertNull(BodyStore::get((string) $path));
    }

    /**
     * The size of a stored body is the size of its file.
     */
    public function testTheSizeIsTheFilesSize(): void
    {
        // Arrange
        $path = BodyStore::put('<p>' . str_repeat('a', 2000) . '</p>', time());

        // Assert
        $this->assertSame(
            filesize($this->root . '/' . $path),
            BodyStore::bytes((string) $path)
        );
        $this->assertSame(0, BodyStore::bytes('2026/08/aa/' . str_repeat('b', 64) . '.html.gz'),
            'a path with no file behind it costs nothing');
    }

    /**
     * A stored file can be removed, and only by a path this store could have written.
     *
     * `forget()` is called with a path `orphans()` returned, and `orphans()` only ever returns
     * paths of the store's own shape — but the method is public, so the guard is where it has
     * to be rather than where the current caller happens to be.
     */
    public function testAStoredFileCanBeForgotten(): void
    {
        // Arrange
        $path = BodyStore::put('<p>' . str_repeat('a', 600) . '</p>', time());

        // Act & Assert
        $this->assertTrue(BodyStore::forget((string) $path));
        $this->assertNull(BodyStore::get((string) $path));
        $this->assertFalse(BodyStore::forget((string) $path), 'and again is not a second removal');
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_values(array_filter(
            (array) glob($this->root . '/*/*/*/*'),
            static fn ($path): bool => is_file((string) $path)
        ));
    }
}
