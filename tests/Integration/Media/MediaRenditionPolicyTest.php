<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Media;

use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\DatabaseTestCase;
use Pramnos\Media\MediaObject;

/**
 * What the media library derives from a file, and what it answers when it cannot.
 *
 * Four things this class pins, each of which was measured going the other way:
 *
 *  1. **`0` means «skip this rendition»**, the same as it already did for `max`. It used to mean the
 *     reverse: the guard was `$startWidth > $this->medium`, so `medium = 0` was true for every
 *     picture that has ever existed, and the setting that reads as «no medium rendition» produced one
 *     for every upload at a width the caller never named.
 *  2. **`deriveNothing`** says the whole thing in one line. Saying it before took six assignments, two
 *     of which meant the opposite of the other four, plus a number chosen to sit above every real
 *     picture.
 *  3. **`max` no longer caps retrieval.** `max` is «do not rewrite what I stored»; `get()` was passing
 *     it down as «how large may a derived image be», so an application that set `max = 0` to protect
 *     its originals silently got 120-pixel renditions from every `get()`, at any requested size.
 *  4. **A file GD cannot read gets the original back**, not a picture of an error message. An SVG took
 *     exactly that route: the first request for a size replaced the logo with a 500×100 JPEG of its
 *     own file path, stored at a `.jpg` URL and recorded as the size that had been asked for.
 *
 * ## Why this is an integration class and not a unit one
 *
 * `processImage()` runs the md5 dedupe query and `get()` saves, so nothing here can be asserted
 * without a real database. It also means the file layout under `www/uploads/` is the real one: three
 * of these four assertions are about *which files exist*, and a mocked filesystem would let them pass
 * over a store that never wrote anything.
 *
 * ## The engines
 *
 * MySQL here, PostgreSQL in the subclass. There is no dialect-specific SQL in any of this — the only
 * statement involved is the existing dedupe `SELECT`, which the whole suite already runs on every
 * lane — so what the second lane actually proves is that the schema the shipped migration builds
 * carries these rows identically on both. That is worth one subclass and is the whole of the
 * difference: a MariaDB or TimescaleDB lane would execute the same PHP against the same two
 * statements.
 */
class MediaRenditionPolicyTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /** @return string[] */
    protected static function ownedTables(): array
    {
        return ['mediause', 'media'];
    }

    /**
     * Nothing: the tables come from the shipped migration instead.
     *
     * Hand-written DDL beside a migration is how a test ends up asserting a shape the framework does
     * not ship — which is a test that can pass while the real schema is broken.
     *
     * @return string[]
     */
    protected static function schemaStatements(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $db = static::openConnection();

        // A migration only ever touches `$this->application->database`, so an Application built
        // without its constructor is enough — and this is static, so a mock is not available.
        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))
            ->newInstanceWithoutConstructor();
        $application->database = $db;

        (new \Pramnos\Framework\Migrations\Core\CreateMediaTables($application))->up();

        $db->close();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // MediaObject reaches for the Factory singleton, not for `$this->db`, so the lane's
        // connection has to *be* the singleton or the rows land in whichever engine the settings
        // file names — which would make the second lane a copy of the first.
        $singleton = &Factory::getDatabase();
        $singleton = $this->db;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->temporary = [];

        foreach (['media_policy', date('Y')] as $directory) {
            $path = ROOT . DS . 'www' . DS . 'uploads' . DS . $directory;
            if (is_dir($path)) {
                $this->removeRecursive($path);
            }
        }

        $singleton = &Factory::getDatabase();
        $singleton = null;

        parent::tearDown();
    }

    private function removeRecursive(string $directory): void
    {
        foreach ((array) glob($directory . DS . '*') as $entry) {
            if (is_dir((string) $entry)) {
                $this->removeRecursive((string) $entry);
            } else {
                @unlink((string) $entry);
            }
        }

        @rmdir($directory);
    }

    /** A PNG of the given size, in the system temp directory. */
    private function png(int $width, int $height): string
    {
        $path = sys_get_temp_dir() . DS . 'mediapolicy_' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 12, 120, 200));
        imagepng($image, $path);
        $this->temporary[] = $path;

        return $path;
    }

    /** An SVG, which `getimagesize()` reads and GD cannot decode. */
    private function svg(int $width, int $height): string
    {
        $path = sys_get_temp_dir() . DS . 'mediapolicy_' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents(
            $path,
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">'
            . '<rect width="' . $width . '" height="' . $height . '" fill="#c00"/></svg>'
        );
        $this->temporary[] = $path;

        return $path;
    }

    /** The renditions a stored object holds, keyed by reason. */
    private function renditionsOf(MediaObject $media): array
    {
        $byReason = [];

        foreach ($media->thumbnails as $thumbnail) {
            $byReason[$thumbnail->reason] = $thumbnail;
        }

        return $byReason;
    }

    /**
     * `deriveNothing` stores the file and writes no derivatives.
     *
     * The three named renditions still exist and all three point at the untouched file, which is the
     * honest answer for a store that was told not to resize: callers ask for `medium` and `thumb` by
     * name, and the entries describe what is actually there.
     */
    public function testDeriveNothingWritesNoDerivatives(): void
    {
        // Arrange
        $source = $this->png(1400, 900);

        // Act
        $media = new MediaObject();
        $media->deriveNothing = true;
        $media->addImage($source, 'media_policy');
        $media->save();

        // Assert
        $this->assertFalse($media->error, (string) $media->error);

        $renditions = $this->renditionsOf($media);
        $this->assertSame(['original', 'medium', 'thumb'], array_keys($renditions));

        foreach ($renditions as $reason => $rendition) {
            $this->assertSame(
                $media->filename,
                $rendition->filename,
                $reason . ' points at a derived file, and nothing was supposed to be derived'
            );
        }

        // The original was not rewritten either: 1400x900 survives `max = 1024`.
        $this->assertSame([1400, 900], array_slice((array) getimagesize($media->filename), 0, 2));
        $this->assertCount(
            1,
            (array) glob(dirname($media->filename) . DS . '*'),
            'a derivative was written into the upload directory'
        );
    }

    /**
     * `medium = 0` skips the medium rendition instead of forcing one.
     *
     * The measured old behaviour: `medium = 0` produced a derivative for every upload at
     * `ResizeTools`' 120-pixel default, because `$startWidth > 0` is true for every picture. The
     * setting read as «off» and behaved as «on, at a size you did not choose».
     */
    public function testZeroMeansSkipRatherThanForce(): void
    {
        // Arrange
        $source = $this->png(800, 600);

        // Act
        $media = new MediaObject();
        $media->medium = 0;
        $media->mediumHeight = 0;
        $media->thumb = 0;
        $media->thumbHeight = 0;
        $media->max = 0;
        $media->maxHeight = 0;
        $media->addImage($source, 'media_policy');
        $media->save();

        // Assert
        $this->assertFalse($media->error, (string) $media->error);

        $renditions = $this->renditionsOf($media);
        $this->assertSame($media->filename, $renditions['medium']->filename);
        $this->assertSame($media->filename, $renditions['thumb']->filename);
        $this->assertSame(
            [800, 600],
            array_slice((array) getimagesize($media->filename), 0, 2),
            'the original was rewritten despite max = 0'
        );
        $this->assertCount(1, (array) glob(dirname($media->filename) . DS . '*'));
    }

    /**
     * `max = 0` protects the original without capping what `get()` may return.
     *
     * This is the measurement that made the two settings obviously different questions: with `max = 0`
     * every requested width was «over the ceiling», so `ResizeTools` substituted its 120-pixel
     * default and **every** `get()` came back 120 wide, at any size, silently. An application setting
     * `max = 0` to keep its originals lost sized renditions and was not told.
     */
    public function testMaxZeroDoesNotCapWhatGetReturns(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->max = 0;
        $media->maxHeight = 0;
        $media->addImage($this->png(800, 600), 'media_policy');
        $media->save();

        // Act
        $rendition = $media->get(400, 300);

        // Assert
        $this->assertSame(400, $rendition->x, 'the request was clamped to ResizeTools\' default');
        $this->assertSame(300, $rendition->y);
        $this->assertSame(
            [400, 300],
            array_slice((array) getimagesize($rendition->filename), 0, 2),
            'the row and the file disagree'
        );
    }

    /**
     * A request above the source's own size comes back at the source's size.
     *
     * Not 512×512 of stretched blur written to disk, recorded as a rendition and served as real — and
     * larger than the original it was derived from, which is the opposite of what a thumbnail is for.
     */
    public function testARequestLargerThanTheSourceIsNotUpscaled(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->addImage($this->png(40, 40), 'media_policy');
        $media->save();

        // Act
        $rendition = $media->get(512, 512);

        // Assert
        $this->assertSame(40, $rendition->x);
        $this->assertSame(40, $rendition->y);
        $this->assertSame(
            [40, 40],
            array_slice((array) getimagesize($rendition->filename), 0, 2)
        );
    }

    /**
     * A clamped rendition is found again by the request that produced it.
     *
     * WHAT: the same `get()` twice, on a source smaller than the box. The second
     *       call returns the stored rendition and appends nothing.
     * WHY:  the lookup compared the request against `x`/`y` — what came *out* —
     *       while the write recorded the clamped result under those same fields.
     *       So every source smaller than the requested box missed its own cache
     *       entry for ever: rebuilt, appended, saved, on every call. `ResizeTools`
     *       prefixes `rand(1,9999)_` when the filename it wants exists, so it was
     *       a new file on disk **and** a new entry in the column each time,
     *       neither bounded. Reported against real data as 46% of images — 467 of
     *       1023 — rebuilt on every page view.
     *
     * The test above this one stops one line short of it: it asserts the clamp
     * and never asks twice, which is why a green suite shipped the defect.
     */
    public function testAClampedRenditionIsFoundAgainAndNotRebuilt(): void
    {
        // Arrange — 40×40 source, asked for a 512×512 box
        $media = new MediaObject();
        $media->addImage($this->png(40, 40), 'media_policy');
        $media->save();

        $first = $media->get(512, 512);
        $countAfterFirst = count($media->thumbnails);
        $this->assertSame(40, $first->x, 'precondition: the request was clamped');

        // Act — the identical request again
        $second = $media->get(512, 512);

        // Assert — nothing was appended, and the same file came back
        $this->assertCount(
            $countAfterFirst,
            $media->thumbnails,
            'the second identical request must not append another entry'
        );
        $this->assertSame(
            $first->filename,
            $second->filename,
            'and must not write a second file for the same rendition'
        );

        // Assert — a third, because the count growing by one per call is the shape
        // of the bug and two calls cannot tell that from an off-by-one.
        $media->get(512, 512);
        $this->assertCount($countAfterFirst, $media->thumbnails);
    }

    /**
     * The entry records both what was asked for and what came out.
     *
     * They are different numbers here, which is the whole reason the lookup
     * needed something other than the result to compare against. Keeping the
     * requested box is what preserves «what was asked for» — the information the
     * clamp used to discard.
     */
    public function testAClampedRenditionRecordsTheRequestedBoxAndTheResult(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->addImage($this->png(40, 40), 'media_policy');
        $media->save();

        // Act
        $rendition = $media->get(512, 512);

        // Assert
        $this->assertSame(40, $rendition->x, 'what came out');
        $this->assertSame(40, $rendition->y);
        $this->assertSame(512, $rendition->requestedX, 'what was asked for');
        $this->assertSame(512, $rendition->requestedY);
    }

    /**
     * A request that needed no clamping still round-trips.
     *
     * The complement: if the matcher only ever compared the requested box, an
     * entry from before this field existed would stop being found — and those are
     * every entry in every existing installation.
     */
    public function testAnUnclampedRenditionIsAlsoFoundAgain(): void
    {
        // Arrange — 200×200 source, a box well inside it
        $media = new MediaObject();
        $media->addImage($this->png(200, 200), 'media_policy');
        $media->save();

        $first = $media->get(50, 50);
        $count = count($media->thumbnails);

        // Act
        $second = $media->get(50, 50);

        // Assert
        $this->assertCount($count, $media->thumbnails);
        $this->assertSame($first->filename, $second->filename);
        $this->assertSame(50, $first->x, 'no clamping was needed here');
        $this->assertSame(50, $first->requestedX);
    }

    /**
     * An SVG is stored as itself, and asking it for a size answers with the original.
     *
     * The whole failure in one assertion: `get()` used to return a `Thumbnail` whose `url` ended
     * `.jpg`, whose `x`/`y` said 128×64, and whose file on disk was a 500×100 JPEG of the source path
     * on a white ground — `makeErrorImg()` verbatim, because GD cannot decode SVG on an ordinary
     * build. A station logo became a picture of a file path, and nothing raised, because a JPEG of an
     * error message is a perfectly valid JPEG.
     *
     * An SVG is already every size, so the original *is* the rendition.
     */
    public function testAVectorAnswersWithItself(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->addImage($this->svg(200, 100), 'media_policy');
        $media->save();

        $this->assertFalse($media->error, (string) $media->error);
        $this->assertSame('image/svg+xml', $media->mimetype);
        $this->assertTrue($media->isVector());

        // Act
        $rendition = $media->get(128, 64);

        // Assert
        $this->assertSame($media->filename, $rendition->filename);
        $this->assertStringEndsWith('.svg', $rendition->url);
        $this->assertSame(200, $rendition->x, 'the row claims a size the file does not have');
        $this->assertSame(100, $rendition->y);

        // …and nothing raster was written beside it.
        $written = array_map('basename', (array) glob(dirname($media->filename) . DS . '*'));
        $this->assertSame(
            [],
            array_values(array_filter($written, fn ($f) => str_ends_with((string) $f, '.jpg'))),
            'a JPEG was produced from a vector'
        );
    }

    /**
     * A raster file GD cannot read gets the original back, and records nothing.
     *
     * Nothing written to `thumbnails` on purpose. A row pointing at a file that was never created is
     * worse than no row: the next `get()` at the same size finds it, fails the `file_exists()` check,
     * deletes it and saves — one write per request, for ever, for a rendition that cannot exist.
     */
    public function testAnUnreadableFileFallsBackToTheOriginalWithoutRecordingIt(): void
    {
        // Arrange — stored as a valid PNG, then corrupted underneath the library
        $media = new MediaObject();
        $media->addImage($this->png(600, 400), 'media_policy');
        $media->save();

        $countBefore = count($media->thumbnails);
        file_put_contents($media->filename, 'not an image at all');

        // Act
        $rendition = $media->get(120, 80);

        // Assert
        $this->assertSame($media->filename, $rendition->filename);
        $this->assertIsString($media->error, 'the failure was not reported');
        $this->assertCount(
            $countBefore,
            $media->thumbnails,
            'a rendition that was never written got a row'
        );
    }

    // ── What arrives from a URL, once the bytes are in hand ───────────────────

    /**
     * A MediaObject whose outbound request is answered from the test rather than the network.
     *
     * Everything interesting in `addRemoteImage()` happens after the bytes arrive — the type read from
     * the content, the extension that follows from it, the refusal of an error status — and none of it
     * was reachable, because reaching it meant a real request. Every address `OutboundUrl` will fetch
     * from is by definition outside this network, so the loopback listener a suite could stand up is
     * exactly what the guard refuses.
     *
     * @param string $body   What the «server» answers with.
     * @param int    $status The status it answers with.
     */
    private function answering(string $body, int $status = 200): MediaObject
    {
        return new class ($body, $status) extends MediaObject {
            public function __construct(private string $body, private int $status)
            {
                parent::__construct();
            }

            protected function fetchRemote(string $url, ?string &$reason, ?int &$status): string|false
            {
                $status = $this->status;
                $reason = null;

                return $this->body === '' ? false : $this->body;
            }
        };
    }

    /** The bytes of a real image of the given type. */
    private function bytesOf(string $type): string
    {
        $image = imagecreatetruecolor(24, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 90, 180));

        ob_start();
        match ($type) {
            'png'  => imagepng($image),
            'gif'  => imagegif($image),
            default => imagejpeg($image),
        };

        return (string) ob_get_clean();
    }

    /**
     * The extension a fetched file is stored under comes from its bytes, not from its URL.
     *
     * The defect this replaced, and it was not a style problem. `$ext` was taken from the URL's own
     * text with a `jpg` fallback, so the library held files whose *name* asserted a type nothing had
     * verified — and everything under `www/uploads/` is served back by the web server according to
     * that name. Here the URL says `.png` and the bytes are a JPEG; the stored file has to say JPEG.
     */
    public function testTheStoredExtensionComesFromTheBytesAndNotTheUrl(): void
    {
        // Arrange — a URL that lies about its content
        $media = $this->answering($this->bytesOf('jpg'));

        // Act
        $media->addRemoteImage('https://example.test/logo.png', 'media_policy');

        // Assert
        $this->assertFalse($media->error, (string) $media->error);
        $this->assertStringEndsWith('.jpg', $media->filename, 'the URL decided the extension');
        $this->assertSame('image/jpeg', $media->mimetype);
    }

    /**
     * Something that is not an image at all is refused, and nothing is written.
     *
     * The commonest real answer to a stale image URL: an HTML error page, served with a 200 by a CDN
     * that would rather show something than nothing. Stored under an image extension it becomes a
     * broken picture on a page; the refusal is what turns it into a visible gap instead.
     */
    public function testSomethingThatIsNotAnImageIsRefused(): void
    {
        // Arrange
        $media = $this->answering('<!doctype html><title>Not found</title>');

        // Act
        $media->addRemoteImage('https://example.test/logo.png', 'media_policy');

        // Assert
        $this->assertIsString($media->error);
        $this->assertStringContainsString('not an image', (string) $media->error);
        $this->assertSame('', $media->filename, 'a non-image was written into the library');
    }

    /**
     * An SVG is refused for a *remote* fetch, even though it is a real image.
     *
     * Deliberate, and the reason is not that it cannot be drawn: it is markup, it can carry script, and
     * served back from this site's own origin that script is same-origin. An application that wants
     * remote SVGs owns that decision and can hand a fetched, sanitised file to `addImage()` — which
     * accepts them, as {@see testAVectorAnswersWithItself()} above shows.
     */
    public function testAnSvgIsRefusedForARemoteFetch(): void
    {
        // Arrange
        $media = $this->answering(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<rect width="10" height="10" fill="#c00"/></svg>'
        );

        // Act
        $media->addRemoteImage('https://example.test/logo.svg', 'media_policy');

        // Assert
        $this->assertIsString($media->error);
        $this->assertStringContainsString('svg', strtolower((string) $media->error));
        $this->assertSame('', $media->filename);
    }

    /**
     * A valid image that arrived with a 404 is refused.
     *
     * The case that proves checking the content is not enough. A CDN answering `404` with a
     * placeholder image returns bytes that are a perfectly valid PNG, so every content check passes —
     * and somebody's grey «image not found» square is entered into the library as the thing that was
     * asked for. Worse than storing nothing: nothing is visible as a gap, and a placeholder looks like
     * a result.
     */
    public function testAValidImageWithAnErrorStatusIsRefused(): void
    {
        // Arrange — real PNG bytes, wrong status
        $media = $this->answering($this->bytesOf('png'), 404);

        // Act
        $media->addRemoteImage('https://example.test/logo.png', 'media_policy');

        // Assert
        $this->assertIsString($media->error);
        $this->assertStringContainsString('404', (string) $media->error);
        $this->assertSame('', $media->filename, 'a placeholder was stored as the requested picture');
    }

    /**
     * A refused fetch says why, and writes nothing.
     *
     * The `$reason` is carried through rather than replaced, because it is the only thing that
     * distinguishes «that host is inside our network» from «that host does not resolve» from «the
     * response was too large» — three different things for an operator looking at an import that
     * skipped rows.
     */
    public function testARefusedFetchIsReportedAndWritesNothing(): void
    {
        // Arrange — the seam's «false»
        $media = $this->answering('', 0);

        // Act
        $media->addRemoteImage('https://example.test/logo.png', 'media_policy');

        // Assert
        $this->assertIsString($media->error);
        $this->assertStringContainsString('Cannot fetch remote image', (string) $media->error);
        $this->assertSame('', $media->filename);
    }

    /**
     * The type of content held in memory is read from the content.
     *
     * `detectMimeTypeOfString()` is what decides the extension above, and it is the piece that makes
     * the decision cheap enough to take *before* anything is written — `finfo_file()` needs a path, and
     * the alternative is putting unidentified bytes on disk to ask about them and moving them
     * afterwards.
     *
     * @param string      $body
     * @param string|null $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesAndTypes')]
    public function testTheTypeOfABufferIsReadFromItsBytes(string $body, ?string $expected): void
    {
        // Act
        $detected = (new \ReflectionMethod(MediaObject::class, 'detectMimeTypeOfString'))
            ->invoke(null, $body);

        // Assert
        $this->assertSame($expected, $detected);
    }

    /** @return array<string, array{string, string|null}> */
    public static function bodiesAndTypes(): array
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/AF/8P0OAAAAAElFTkSuQmCC'
        );

        return [
            'a PNG'            => [$png, 'image/png'],
            'HTML'             => ['<!doctype html><title>x</title>', 'text/html'],
            'nothing'          => ['', null],
            'plain bytes'      => ['just some words', 'text/plain'],
        ];
    }
}
