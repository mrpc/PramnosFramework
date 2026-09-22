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

/**
 * `new MediaObject($id)` loads the row.
 *
 * WHAT: constructing with an id populates the object; constructing with nothing, a zero
 *       or an id that is not there leaves it empty.
 *
 * WHY:  it used to load nothing. The class declared no constructor, so it inherited
 *       `Base::__construct()`, which takes no parameters — and PHP does not complain
 *       about an argument passed to a constructor that declares none. The id went
 *       nowhere and the object stayed empty.
 *
 *       The shape of the failure is the expensive part. One application's
 *       `GET /api/1.0/media/{id}` answered **404 for every picture that had ever
 *       existed**; the screen showed a caption with no thumbnail, which looks exactly
 *       like a file somebody deleted. It was investigated three times — a broken upload,
 *       a broken `.htaccess`, a lost file — while the row was in the table throughout.
 *       Nothing in a log, no status code that was wrong: every caller's
 *       `if (empty($media->mediaid))` turned an empty object into "not found".
 *
 *       Every other model in the framework loads from its identifier. This was the one
 *       that looked the same and behaved differently.
 */
#[CoversClass(MediaObject::class)]
class MediaObjectLoadsFromItsIdTest extends BaseTestCase
{
    private Database $db;

    private int $mediaId = 0;

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

        $this->runMigrations([\Pramnos\Framework\Migrations\Core\CreateMediaTables::class], $this->db);
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->mediaId > 0) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#media')
                    ->where('mediaid', $this->mediaId)->delete();
            } catch (\Throwable) {
                // Already gone.
            }
        }

        parent::tearDown();
    }

    private function seed(): void
    {
        // `name`, not `title`: the column is what the create-table declares, and
        // reading it back is half of what this class asserts.
        $this->db->queryBuilder()->table('#PREFIX#media')->insert([
            'mediatype' => 1,
            'name'      => 'A picture',
            'filename'  => 'picture.png',
        ]);

        $this->mediaId = (int) $this->db->getInsertId();
        $this->assertGreaterThan(0, $this->mediaId, 'the fixture row was not created');
    }

    /**
     * The row is there, and the object built from its id carries it.
     *
     * **The assertion the bug is about.** `$media->mediaid` being zero after this is
     * exactly what every caller reads as "no such picture".
     */
    public function testConstructingWithAnIdLoadsTheRow(): void
    {
        // Act
        $media = new MediaObject($this->mediaId);

        // Assert
        $this->assertSame($this->mediaId, (int) $media->mediaid, 'the id was ignored');
        $this->assertSame('A picture', (string) $media->name);
    }

    /**
     * And it is the same object `load()` produces.
     *
     * The constructor must be a shorthand rather than a second path: two ways of loading
     * that differ in what they populate is the next bug of this shape.
     */
    public function testTheConstructorAgreesWithLoad(): void
    {
        // Arrange
        $loaded = new MediaObject();
        $loaded->load($this->mediaId);

        // Act
        $constructed = new MediaObject($this->mediaId);

        // Assert
        $this->assertSame((int) $loaded->mediaid, (int) $constructed->mediaid);
        $this->assertSame((string) $loaded->name, (string) $constructed->name);
        $this->assertSame((string) $loaded->filename, (string) $constructed->filename);
    }

    /**
     * Constructing with nothing is unchanged.
     *
     * Every existing `new MediaObject()` in this framework and in every application —
     * and the argument for a constructor rather than a static `find()`.
     */
    public function testConstructingWithNothingIsUnchanged(): void
    {
        // Act
        $media = new MediaObject();

        // Assert
        $this->assertSame(0, (int) $media->mediaid);
    }

    /**
     * A zero or an empty string is "no id", not row zero.
     *
     * Callers reach this with an unvalidated route segment, and `/media/` gives an empty
     * one. Loading nothing is the honest answer; a query for `mediaid = 0` is a wasted
     * round trip on every such request.
     */
    public function testAZeroOrEmptyIdLoadsNothing(): void
    {
        // Act + Assert
        $this->assertSame(0, (int) (new MediaObject(0))->mediaid);
        $this->assertSame(0, (int) (new MediaObject(''))->mediaid);
    }

    /**
     * An id that is not there leaves the object empty rather than raising.
     *
     * The caller's own `empty($media->mediaid)` is the 404, and that contract is
     * unchanged — what changed is that it now means what it says.
     */
    public function testAnIdThatIsNotThereLeavesTheObjectEmpty(): void
    {
        // Act
        $media = new MediaObject($this->mediaId + 100000);

        // Assert
        $this->assertSame(0, (int) $media->mediaid);
    }
}
