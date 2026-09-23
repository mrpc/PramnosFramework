<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;
use Pramnos\Media\MediaObject;
use Pramnos\Media\Thumbnail;
use Pramnos\Storage\Storage;

/**
 * Publishing media to a storage disk, and doing nothing at all without one.
 *
 * WHAT: with a `media` disk configured, the original and every rendition land on it and
 *       `publicUrl()` answers the disk's address. With none, nothing is published and
 *       `publicUrl()` is the site's own path — which is what every installation has today.
 *
 * WHY:  `www/uploads/` is local disk, and on more than one server a picture uploaded on one
 *       node does not exist on the other. A shared mount solves that with no code; object
 *       storage is for the cases a mount cannot cover — a CDN origin, a node that gets
 *       replaced, files nobody is reading.
 *
 *       **The "without one" half is the half worth testing.** Every existing installation
 *       has no `storage` block, and the change must be invisible to all of them: same
 *       paths, same URL, no copy from a directory to itself. A feature that is optional in
 *       the docblock and not in the code is not optional.
 *
 * The work stays local and only the result is published, because GD writes with
 * `imagejpeg($image, $path)` — a real filesystem path a bucket does not have. So this
 * tests the publish, not a rewritten resize pipeline.
 */
#[CoversClass(MediaObject::class)]
class MediaStoragePublishTest extends BaseTestCase
{
    private Database $db;

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    private string $diskRoot = '';

    /** @var list<string> */
    private array $localFiles = [];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = Connection::fresh();
        if (!$this->db->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        $this->savedSettings = (array) (new \ReflectionProperty(Settings::class, 'settings'))->getValue();
        $this->diskRoot      = sys_get_temp_dir() . '/pramnos_mediadisk_' . bin2hex(random_bytes(4));
        mkdir($this->diskRoot, 0777, true);

        Storage::reset();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $this->savedSettings);
        Storage::reset();

        foreach ($this->localFiles as $file) {
            @unlink($file);
        }

        $this->removeTree($this->diskRoot);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    /** Configure a `media` disk, or none at all. */
    private function withMediaDisk(bool $configured, string $publicUrl = 'https://cdn.example.com'): void
    {
        $settings = $this->savedSettings;

        if ($configured) {
            $settings['storage'] = [
                'default' => 'media',
                'disks'   => [
                    'media' => ['driver' => 'local', 'root' => $this->diskRoot, 'url' => $publicUrl],
                ],
            ];
        } else {
            unset($settings['storage']);
        }

        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);
        Storage::reset();
    }

    /**
     * A media object with a real local file and one rendition.
     *
     * Built rather than uploaded: `uploadFile()` needs a `$_FILES` entry and a real image
     * for the type check, and what is under test here is the publish, not the upload.
     */
    private function media(): MediaObject
    {
        $stamp = bin2hex(random_bytes(4));

        $originalKey = 'uploads/test/' . $stamp . '.txt';
        $thumbKey    = 'uploads/test/' . $stamp . '_thumb.txt';

        foreach ([$originalKey => 'the original', $thumbKey => 'the thumbnail'] as $key => $contents) {
            $local = ROOT . '/www/' . $key;
            if (!is_dir(dirname($local))) {
                mkdir(dirname($local), 0777, true);
            }
            file_put_contents($local, $contents);
            $this->localFiles[] = $local;
        }

        $media           = new MediaObject();
        $media->url      = $originalKey;
        $media->filename = ROOT . '/www/' . $originalKey;

        $thumb           = new Thumbnail();
        $thumb->url      = $thumbKey;
        $thumb->filename = ROOT . '/www/' . $thumbKey;
        $thumb->reason   = 'thumb';

        $media->thumbnails = [$thumb];

        return $media;
    }

    /**
     * With no disk configured, nothing is published and nothing is copied.
     *
     * **The assertion that makes this optional.** Every existing installation is this case,
     * and a publish step that ran anyway would be a copy from `www/uploads/` to
     * `www/uploads/` — IO for nothing, on every upload, on every single-server site.
     */
    public function testWithNoDiskNothingIsPublished(): void
    {
        // Arrange
        $this->withMediaDisk(false);

        // Act + Assert
        $this->assertSame(0, $this->media()->publishToStorage());
        $this->assertSame([], glob($this->diskRoot . '/*') ?: []);
    }

    /**
     * And the public address is the site's own, exactly as it has always been.
     */
    public function testWithNoDiskTheUrlIsTheSitesOwn(): void
    {
        // Arrange
        $this->withMediaDisk(false);
        $media = $this->media();

        // Act
        $url = $media->publicUrl();

        // Assert — whatever the site root is, the stored path is on the end of it
        $this->assertStringEndsWith($media->url, $url);
    }

    /**
     * With a disk, the original and every rendition are put on it.
     */
    public function testWithADiskEveryFileIsPublished(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();

        // Act
        $published = $media->publishToStorage();

        // Assert — two files, with their contents, under their stored keys
        $this->assertSame(2, $published);
        $this->assertSame('the original', file_get_contents($this->diskRoot . '/' . $media->url));
        $this->assertSame(
            'the thumbnail',
            file_get_contents($this->diskRoot . '/' . $media->thumbnails[0]->url)
        );
    }

    /**
     * The local copies stay.
     *
     * They are the origin a later resize reads, and on a single server they are also what
     * is served. Removing them would make rendering a page depend on the disk being
     * reachable — which is a worse failure than the one this feature is for.
     */
    public function testPublishingLeavesTheLocalCopies(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();

        // Act
        $media->publishToStorage();

        // Assert
        $this->assertFileExists($media->filename);
        $this->assertFileExists($media->thumbnails[0]->filename);
    }

    /**
     * The public address comes from the disk.
     *
     * This is what puts a CDN in front of the files, and it is why a view should ask
     * `publicUrl()` rather than concatenating `sURL` with the stored path — the
     * concatenation keeps working and keeps pointing at the wrong server.
     */
    public function testWithADiskTheUrlComesFromIt(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();

        // Act + Assert
        $this->assertSame('https://cdn.example.com/' . $media->url, $media->publicUrl());
        $this->assertSame(
            'https://cdn.example.com/' . $media->thumbnails[0]->url,
            $media->publicUrl('thumb')
        );
    }

    /**
     * A disk with no public base falls back to the site's own address.
     *
     * A private bucket is a legitimate configuration — the application serves the bytes
     * itself — and an empty `src` would be a broken image where a working one was possible.
     */
    public function testAPrivateDiskFallsBackToTheSiteUrl(): void
    {
        // Arrange
        $this->withMediaDisk(true, '');
        $media = $this->media();

        // Act + Assert
        $this->assertStringEndsWith($media->url, $media->publicUrl());
    }

    /**
     * Republishing skips what is already there.
     *
     * For a backfill after a disk is configured, and for retrying after the disk was
     * unreachable. It has to be cheap to run over a whole library repeatedly, or nobody
     * will run it twice.
     */
    public function testRepublishingSkipsWhatIsAlreadyThere(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();
        $this->assertSame(2, $media->publishToStorage());

        // Act
        $again = $media->republishToStorage();

        // Assert
        $this->assertSame(0, $again, 'republish copied files that were already on the disk');
    }

    /**
     * And it publishes what is missing.
     *
     * The other half: a backfill that skipped everything would report success over a disk
     * with nothing on it.
     */
    public function testRepublishingPublishesWhatIsMissing(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();

        // Act + Assert
        $this->assertSame(2, $media->republishToStorage());
    }

    /**
     * Removing takes the files off the disk too.
     *
     * A row whose files are gone locally and present on the disk is the worse half of the
     * two: the local copy is a cache, and the disk is what a CDN reads and keeps serving.
     */
    public function testDeletingRemovesThemFromTheDisk(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();
        $media->publishToStorage();
        $this->assertFileExists($this->diskRoot . '/' . $media->url);

        // Act
        $media->deleteFromStorage();

        // Assert
        $this->assertFileDoesNotExist($this->diskRoot . '/' . $media->url);
        $this->assertFileDoesNotExist($this->diskRoot . '/' . $media->thumbnails[0]->url);
    }

    /**
     * A rendition pointing at the original's own file is published once.
     *
     * `reason = 'original'` is a thumbnail row whose `filename` *is* the original, so a
     * publish keyed by local path would copy the same bytes twice — and a `delete()` would
     * try to remove the same key twice. Keying by `url` makes both naturally right.
     */
    public function testARenditionSharingTheOriginalIsPublishedOnce(): void
    {
        // Arrange
        $this->withMediaDisk(true);
        $media = $this->media();

        $sameFile           = new Thumbnail();
        $sameFile->url      = $media->url;
        $sameFile->filename = $media->filename;
        $sameFile->reason   = 'original';
        $media->thumbnails[] = $sameFile;

        // Act
        $published = $media->publishToStorage();

        // Assert — the original, the thumb, and not the original again
        $this->assertSame(2, $published);
    }
}
