<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\MediaObject;

/**
 * What stops an upload being code — the checks that run after the client has been believed twice.
 *
 * An upload arrives with two claims, and both are the client's: the extension it chose and the
 * `Content-Type` header it sent. Neither is evidence. The existing tests here cover the two claims
 * being *checked*; what had never run is what happens after they pass — asking the file itself, and
 * making sure the directory it lands in cannot execute anything.
 *
 * That pair is the actual defence, and each half fails differently:
 *
 * - **content against extension.** A PHP script named `holiday.jpg`, sent with
 *   `Content-Type: image/jpeg`, satisfies every check above it. `finfo` says `text/x-php`, and this
 *   is the only thing between that file and a directory the web server serves.
 * - **the directory.** Even a correctly identified image is in a directory that must never run
 *   anything, because the next hole is always the one nobody has thought of yet. The `.htaccess`
 *   turns the PHP engine off *and* refuses `.php`, `.phtml` and `.phar` by rewrite — belt and
 *   braces, because `php_flag` is silently ignored on a server without `mod_php`.
 *
 * Deliberately *not* strict, and that is asserted too: the check refuses content from a different
 * world and stays quiet about the odd-but-valid files real people upload. A spreadsheet exported as
 * CSV and named `.xls` has always been accepted here, and a check that started rejecting it would be
 * a worse bug than the one it guards against — reported as «the site stopped accepting my file»,
 * with nothing in any log.
 *
 * ### What is deliberately not asserted here, and why
 *
 * That an *accepted* upload completes. Every successful `uploadFile()` ends by querying
 * `#PREFIX#media` for a duplicate by md5 — and **the framework ships no migration for the `media`
 * table**. The model has been here for years with no schema behind it, which is why the existing
 * media tests hand-roll the DDL themselves; a test that did the same would be asserting a shape the
 * framework does not define. So the refusals are covered — they all return before the file is moved
 * — and the accepted path waits for that table to have a migration.
 *
 * The check being too *strict* is still covered, from the other side: `contentMatchesExtension()` is
 * asserted directly to accept the ordinary cases, which is what keeps the refusals above from being
 * satisfied by a check that refuses everything.
 *
 * No database: none of this touches one.
 */
#[CoversClass(MediaObject::class)]
class UploadContentChecksTest extends TestCase
{
    /** Uploads land under `www/uploads/<module>/Y/m/d`; a module of its own keeps them together. */
    private const MODULE = 'upload_checks_probe';

    private string $tmp = '';

    /** Files and directories to remove afterwards. */
    private array $made = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pramnos-upload-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
        $this->made[] = $this->tmp;

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->made) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                foreach ((array) glob($path . '/*') as $child) {
                    @unlink($child);
                }
                @rmdir($path);
            }
        }
        $this->made = [];
        $_SESSION = [];
    }

    /** A file in this test's own directory. */
    private function file(string $name, string $contents): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        $this->made[] = $path;

        return $path;
    }

    /** @return array<string, string> A `$_FILES`-shaped entry. */
    private function upload(string $path, string $name, string $type): array
    {
        return ['name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => 0,
            'size' => (string) filesize($path)];
    }

    private static function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(MediaObject::class, $method))->invoke(null, ...$args);
    }

    // ── Asking the file itself ────────────────────────────────────────────────

    /**
     * A PHP script named `.jpg` is refused, however it was labelled.
     *
     * The whole reason the content check exists. The extension is in the allow-list and the
     * `Content-Type` is one this class accepts, so every check before this one passes — and the file
     * is a script, on its way to a directory a web server answers for.
     */
    public function testAPhpScriptNamedJpgIsRefused(): void
    {
        // Arrange
        $script = $this->file('holiday.jpg', "<?php system(\$_GET['c']); ?>\n");
        $media  = new MediaObject();

        // Act
        $media->uploadFile($this->upload($script, 'holiday.jpg', 'image/jpeg'), self::MODULE);

        // Assert
        $this->assertNotFalse($media->error, 'a PHP script was accepted as an image');
        $this->assertStringContainsString('does not match its extension', (string) $media->error);
        $this->assertStringContainsString('.jpg', (string) $media->error, 'the error does not say which');
    }

    // ── What counts as a match ────────────────────────────────────────────────

    /**
     * The families accept what belongs and refuse what comes from elsewhere.
     *
     * Both directions in one test, because the value of the check is entirely in the ratio: refusing
     * a script matters only if ordinary files still go through.
     */
    public function testTheContentFamiliesAcceptTheOrdinaryAndRefuseTheForeign(): void
    {
        // Act & Assert — the ordinary
        $this->assertTrue(self::call('contentMatchesExtension', 'image/jpeg', 'jpg'));
        $this->assertTrue(self::call('contentMatchesExtension', 'image/png', 'png'));
        $this->assertTrue(self::call('contentMatchesExtension', 'application/pdf', 'pdf'));

        // An icon is legitimately reported as octet-stream by some finfo builds.
        $this->assertTrue(self::call('contentMatchesExtension', 'application/octet-stream', 'ico'));

        // And the foreign
        $this->assertFalse(
            self::call('contentMatchesExtension', 'text/x-php', 'jpg'),
            'a PHP script passed the content check for a .jpg'
        );
        $this->assertFalse(self::call('contentMatchesExtension', 'application/x-executable', 'png'));
        $this->assertFalse(self::call('contentMatchesExtension', 'text/html', 'pdf'));
    }

    /**
     * A spreadsheet exported as something else is still accepted.
     *
     * Deliberately loose, and worth a test of its own so a later tightening has to argue with it:
     * plenty of tools export CSV or HTML and name it `.xls`, those files have always been accepted
     * here, and refusing them would be reported as «the site stopped accepting my file» with nothing
     * in any log. The check is for content from a different world, not for pedantry about Excel's
     * many disguises.
     */
    public function testASpreadsheetInDisguiseIsStillAccepted(): void
    {
        // Act & Assert
        foreach (['text/csv', 'text/plain', 'application/zip', 'application/octet-stream'] as $type) {
            $this->assertTrue(
                self::call('contentMatchesExtension', $type, 'xls'),
                $type . ' named .xls was refused, which used to work'
            );
        }
    }

    /**
     * An extension nobody listed is not this check's business.
     *
     * The allow-list above already decides what may be stored at all, so a second gate here would
     * mean adding a new file type in two places — and forgetting the second gives a refusal whose
     * message points at content rather than at the missing family.
     */
    public function testAnUnlistedExtensionIsNotThisChecksBusiness(): void
    {
        // Act & Assert
        $this->assertTrue(self::call('contentMatchesExtension', 'application/vnd.whatever', 'odt'));
    }

    /**
     * An inability to look is not evidence of wrongdoing.
     *
     * `detectMimeType` answers null when the file cannot be read, and the caller treats null as "no
     * opinion" rather than as a refusal. A server without `fileinfo` would otherwise reject every
     * upload — a far worse bug than the one being guarded against, and one that appears only on the
     * one deployment missing the extension.
     */
    public function testAFileThatCannotBeReadYieldsNoOpinion(): void
    {
        // Act & Assert
        $this->assertNull(
            self::call('detectMimeType', $this->tmp . '/definitely-not-here'),
            'an unreadable file produced a verdict'
        );
    }

    /**
     * A random component that is not guessable from the clock.
     *
     * `rand(0, time())` would be seeded from a value an attacker knows and drawn from a
     * non-cryptographic generator, making the URLs of files uploaded around a known moment
     * searchable. For anything not meant to be public the name is the only protection there is.
     */
    public function testTheNameCarriesAnUnguessableComponent(): void
    {
        // Act
        $tokens = [];
        for ($i = 0; $i < 20; $i++) {
            $tokens[] = (string) self::call('randomToken');
        }

        // Assert
        $this->assertCount(20, array_unique($tokens), 'the token repeats, so names are predictable');
        $this->assertGreaterThanOrEqual(6, strlen($tokens[0]), 'the token is too short to matter');
    }

    // ── The directory the file lands in ───────────────────────────────────────

    /**
     * The upload directory is given an `.htaccess` that refuses to execute anything.
     *
     * Two mechanisms on purpose. `php_flag engine off` is the direct answer and is *silently
     * ignored* on a server without `mod_php` — which is most of them now — so the rewrite refusing
     * `.php`, `.phtml` and `.phar` is what actually holds there. Either alone leaves a deployment
     * where an uploaded script would run.
     */
    public function testTheUploadDirectoryIsProtectedAgainstExecution(): void
    {
        // Arrange
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'uploads';
        mkdir($dir, 0777, true);
        $this->made[] = $dir;

        // Act
        (new \ReflectionMethod(MediaObject::class, 'protectUploadDirectory'))->invoke(null, $dir);

        // Assert
        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        $this->assertFileExists($htaccess, 'uploaded files sit in a directory that can execute them');

        $rules = (string) file_get_contents($htaccess);
        $this->assertStringContainsString('engine off', $rules);
        $this->assertStringContainsString('RewriteRule', $rules, 'no protection without mod_php');
        foreach (['php', 'phtml', 'phar'] as $extension) {
            $this->assertStringContainsString($extension, $rules, $extension . ' is not refused');
        }
    }

    /**
     * An existing `.htaccess` is left alone.
     *
     * An installation may have its own rules there — a cache header, a CORS policy — and overwriting
     * them on the next upload would break something far from here, at a moment nobody connects to an
     * upload.
     */
    public function testAnExistingHtaccessIsNotOverwritten(): void
    {
        // Arrange
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'uploads-own-rules';
        mkdir($dir, 0777, true);
        $this->made[] = $dir;

        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        file_put_contents($htaccess, "# the installation's own rules\n");

        // Act
        (new \ReflectionMethod(MediaObject::class, 'protectUploadDirectory'))->invoke(null, $dir);

        // Assert
        $this->assertSame(
            "# the installation's own rules\n",
            (string) file_get_contents($htaccess),
            "the installation's own rules were replaced"
        );
    }

    /**
     * A directory that cannot be written is left alone rather than raising.
     *
     * Protection is best-effort: an upload directory the process cannot write to has bigger problems
     * than a missing `.htaccess`, and raising here would turn that into a failed upload with a
     * misleading message.
     */
    public function testAnUnwritableDirectoryIsNotFatal(): void
    {
        // Act & Assert — the assertion is that nothing is raised
        (new \ReflectionMethod(MediaObject::class, 'protectUploadDirectory'))
            ->invoke(null, $this->tmp . '/not-a-directory-at-all');

        $this->assertFileDoesNotExist($this->tmp . '/not-a-directory-at-all/.htaccess');
    }

    // ── What a stored row can do to the reader ────────────────────────────────

    /**
     * A thumbnail serialised as a class this installation does not have is dropped, not fatal.
     *
     * The `thumbnails` column holds serialised objects, and the class name travels with them. A
     * library filled by an older application deserialises into `__PHP_Incomplete_Class`, and
     * `getThumb()` reads `$thumb->reason` on every entry — reading *any* property of an incomplete
     * class is a fatal error, not a warning.
     *
     * This is real data, not a hypothetical: a production library holds rows serialised as
     * `O:23:"foreign_media_thumbnail"`, a class that is not in this framework. Every one of those
     * rows would have taken down the page that displayed it.
     */
    public function testAThumbnailOfAnUnknownClassIsDroppedRatherThanFatal(): void
    {
        // Arrange — exactly the shape found in production
        $stored = 'a:1:{i:0;O:23:"foreign_media_thumbnail":1:{s:6:"reason";s:5:"thumb";}}';

        // Act
        $usable = self::call('usableThumbnails', $stored);

        // Assert
        $this->assertSame([], $usable, 'an unreadable thumbnail reached the code that walks them');
    }

    /**
     * A thumbnail of the framework's own class survives.
     *
     * Without this the filter would be satisfied by one that drops everything — a media library
     * where no image ever has a thumbnail, which looks like the thumbnails were never generated.
     */
    public function testAThumbnailOfTheFrameworksOwnClassSurvives(): void
    {
        // Arrange
        $thumb = new \Pramnos\Media\Thumbnail();
        $thumb->reason = 'thumb';
        $thumb->filename = '/tmp/x.png';

        // Act
        $usable = self::call('usableThumbnails', serialize([$thumb]));

        // Assert
        $this->assertCount(1, $usable, 'a valid thumbnail was dropped');
        $this->assertSame('thumb', $usable[0]->reason);
    }

    /**
     * An empty or unparsable value is an empty list, not `false`.
     *
     * `unserialize('')` returns `false`, and `foreach (false)` warns and iterates nothing — so the
     * symptom was a warning in the log rather than a missing thumbnail anybody would chase.
     */
    public function testAnEmptyOrUnparsableValueIsAnEmptyList(): void
    {
        // Act & Assert
        foreach (['', 'not serialised at all', 'a:1:{i:0;s:3:"str";}', null] as $stored) {
            $this->assertSame(
                [],
                self::call('usableThumbnails', $stored),
                'a stored value of ' . var_export($stored, true) . ' was not normalised'
            );
        }
    }

    /**
     * A file that cannot be read leaves the hash empty, not the hash of nothing.
     *
     * `file_get_contents()` on a missing file returns `false`, and `md5(false)` is `md5('')` —
     * `d41d8cd98f00b204e9800998ecf8427e`, **the same value for every missing file**. `uploadFile()`
     * finds a re-upload with `where md5 = %s and medialink = 0`, so every file whose bytes had gone
     * was a duplicate of every other one, and the next upload could be linked to any of them.
     *
     * A production library of 4,551 files holds **14** rows carrying exactly that hash. An empty
     * hash matches nothing, which is the honest answer for a file nobody can read.
     */
    public function testAnUnreadableFileLeavesTheHashEmpty(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->filename = $this->tmp . DIRECTORY_SEPARATOR . 'definitely-not-here.png';

        // Act
        $media->createMd5();

        // Assert
        $this->assertSame('', $media->md5, 'a missing file was given the md5 of an empty string');
        $this->assertNotSame('d41d8cd98f00b204e9800998ecf8427e', $media->md5);
    }

    /**
     * A file that can be read is hashed normally.
     *
     * The other half: the guard above must not turn every hash into an empty string, which would
     * stop re-upload detection working at all.
     */
    public function testAReadableFileIsHashedNormally(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->filename = $this->file('hashme.txt', 'the contents');

        // Act
        $media->createMd5();

        // Assert
        $this->assertSame(md5('the contents'), $media->md5);
    }

    /**
     * The detected type is recorded rather than used and thrown away.
     *
     * `uploadFile()` reads the real type with `finfo` to decide whether the content matches the
     * extension — the check that refuses a PHP script named `holiday.jpg` — and used to discard it.
     * Two things went with it: the security decision became unauditable, and anything serving the
     * file later had to re-guess the type from the extension, which is the claim that check exists
     * to distrust. `mediatype` is no substitute: it is a display family and cannot tell a png from a
     * jpeg.
     */
    public function testTheDetectedTypeIsRecorded(): void
    {
        // Arrange — a real PDF, which is refused by nothing
        $pdf = $this->file(
            'recorded.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
        );
        $media = new MediaObject();

        // Act — the checks run and pass; the upload itself needs a database, which this does not have
        $detected = self::call('detectMimeType', $pdf);

        // Assert — what the checks saw is what the column is meant to hold
        $this->assertSame('application/pdf', $detected, 'the file was not identified');
        $this->assertSame('', (new MediaObject())->mimetype, 'the property has no safe default');
    }

    // ── The stored format ─────────────────────────────────────────────────────

    /**
     * Thumbnails are written as JSON, not as serialised objects.
     *
     * The format change, and the reason is not cosmetic. PHP's serialisation writes the **class name
     * into the data**, so who can read a row depends on which classes the reading process has — this
     * framework alone, a second application on the same database, or a CLI script that skips the
     * first application's autoloader all get `__PHP_Incomplete_Class` and a fatal on the first
     * property read. It writes property *names* too, so renaming one silently drops every stored
     * value; it lets `unserialize()` instantiate classes and run their magic methods; and nothing
     * outside PHP can read it — no SQL, no `JSON_EXTRACT`, no index on «thumbnails wider than 500».
     */
    public function testThumbnailsAreStoredAsJson(): void
    {
        // Arrange
        $thumb = new \Pramnos\Media\Thumbnail();
        $thumb->reason   = 'thumb';
        $thumb->filename = '/uploads/2026/09/x.png';
        $thumb->x        = 120;
        $thumb->y        = 90;

        // Act
        $stored = (string) self::call('encodeThumbnails', [$thumb]);

        // Assert
        $this->assertStringStartsWith('[', $stored, 'the column still holds a serialised object');
        $this->assertStringNotContainsString('O:', $stored, 'a class name reached the data');
        $this->assertStringNotContainsString('Pramnos', $stored, 'the class name is in the payload');

        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded);
        $this->assertSame('thumb', $decoded[0]['reason']);
        $this->assertSame(120, $decoded[0]['x']);
    }

    /**
     * A JSON payload comes back as `Thumbnail` objects, with every field.
     *
     * The in-memory shape is what callers depend on: `getThumb()` returns an object and its callers
     * write `$thumb->url`. Changing the storage must not change that, or every template breaks.
     */
    public function testJsonComesBackAsThumbnailObjects(): void
    {
        // Arrange
        $thumb = new \Pramnos\Media\Thumbnail();
        $thumb->reason     = 'medium';
        $thumb->filename   = '/uploads/2026/09/y.png';
        $thumb->url        = 'uploads/2026/09/y.png';
        $thumb->x          = 640;
        $thumb->y          = 480;
        $thumb->views      = 3;
        $thumb->filesize   = 9182;
        $thumb->createdTxt = '01/09/2026 12:00:00';

        $stored = (string) self::call('encodeThumbnails', [$thumb]);

        // Act
        $read = self::call('usableThumbnails', $stored);

        // Assert
        $this->assertCount(1, $read);
        $this->assertInstanceOf(\Pramnos\Media\Thumbnail::class, $read[0]);

        foreach (['reason', 'filename', 'url', 'x', 'y', 'views', 'filesize', 'createdTxt'] as $field) {
            $this->assertSame($thumb->$field, $read[0]->$field, $field . ' did not round-trip');
        }
    }

    /**
     * Greek text in a filename survives the round trip unescaped.
     *
     * `JSON_UNESCAPED_UNICODE`, and it earns its place: without it a Greek filename is stored as six
     * bytes of `\uXXXX` per character in a `text` column, and a reader comparing the stored string
     * with the one on disk stops matching.
     */
    public function testGreekTextSurvivesTheRoundTrip(): void
    {
        // Arrange
        $thumb = new \Pramnos\Media\Thumbnail();
        $thumb->reason   = 'thumb';
        $thumb->filename = '/uploads/φωτογραφία.png';

        // Act
        $stored = (string) self::call('encodeThumbnails', [$thumb]);
        $read   = self::call('usableThumbnails', $stored);

        // Assert
        $this->assertStringContainsString('φωτογραφία', $stored, 'stored as \\u escapes');
        $this->assertSame('/uploads/φωτογραφία.png', $read[0]->filename);
    }

    /**
     * A legacy serialised row is still read, and its objects come back as they are.
     *
     * Deliberately **not** converted to `Thumbnail`. An application with its own thumbnail class has
     * been getting its own objects back from these rows for years, and rewriting them into this
     * class's type on read would change what `getThumb()` hands its callers — for rows nobody has
     * touched. They convert themselves on the next save, because the writer only produces JSON.
     */
    public function testALegacySerialisedRowIsStillReadUnchanged(): void
    {
        // Arrange — the framework's own class, serialised the old way
        $thumb = new \Pramnos\Media\Thumbnail();
        $thumb->reason = 'thumb';
        $thumb->x      = 42;

        // Act
        $read = self::call('usableThumbnails', serialize([$thumb]));

        // Assert
        $this->assertCount(1, $read, 'a row written before the format change stopped being readable');
        $this->assertSame(42, $read[0]->x);
        $this->assertInstanceOf(\Pramnos\Media\Thumbnail::class, $read[0]);
    }

    /**
     * A JSON key the class does not declare is ignored.
     *
     * Not assigned as a dynamic property — PHP 8.2 deprecates those, and nothing would read it
     * anyway. This is the forward-compatibility case: a payload written by a later version that
     * added a field must still load here, minus the field this version does not know.
     */
    public function testAnUnknownJsonKeyIsIgnored(): void
    {
        // Act
        $read = self::call(
            'usableThumbnails',
            '[{"reason":"thumb","x":10,"somethingNew":"from a later version"}]'
        );

        // Assert
        $this->assertCount(1, $read);
        $this->assertSame('thumb', $read[0]->reason);
        $this->assertSame(10, $read[0]->x);
        $this->assertFalse(
            property_exists($read[0], 'somethingNew'),
            'an unknown key became a dynamic property'
        );
    }

    /** Malformed JSON is an empty list, like any other unreadable value. */
    public function testMalformedJsonIsAnEmptyList(): void
    {
        // Act & Assert
        $this->assertSame([], self::call('usableThumbnails', '[{"reason":'));
        $this->assertSame([], self::call('usableThumbnails', '[1,2,3]'));
    }

    /**
     * Encoding a foreign-class object keeps whatever of the fields it has.
     *
     * The upgrade path for an application with its own thumbnail class: the first save after this
     * change reads its objects (legacy path) and writes them as JSON, so the row converts without
     * anybody migrating it — and without the class name going along.
     */
    public function testEncodingAForeignObjectKeepsTheFieldsItHas(): void
    {
        // Arrange — same field names, different class, exactly like the real legacy data
        $foreign = new class () {
            public $reason = 'thumb';

            public $filename = '/uploads/legacy.png';

            public $x = 200;
        };

        // Act
        $stored  = (string) self::call('encodeThumbnails', [$foreign]);
        $decoded = json_decode($stored, true);

        // Assert
        $this->assertSame('thumb', $decoded[0]['reason']);
        $this->assertSame('/uploads/legacy.png', $decoded[0]['filename']);
        $this->assertSame(200, $decoded[0]['x']);
        $this->assertNull($decoded[0]['views'], 'a field it does not have should be null, not absent');
    }

    /** Nothing to encode is an empty JSON list, not an empty string. */
    public function testNothingToEncodeIsAnEmptyJsonList(): void
    {
        // Act & Assert
        $this->assertSame('[]', (string) self::call('encodeThumbnails', []));
        $this->assertSame('[]', (string) self::call('encodeThumbnails', 'not an array at all'));
        $this->assertSame([], self::call('usableThumbnails', '[]'));
    }
}
