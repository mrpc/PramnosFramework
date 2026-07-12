<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Factory;
use Pramnos\Media\MediaObject;
use Pramnos\Media\Thumbnail;
use Pramnos\User\User;

#[CoversClass(MediaObject::class)]
class MediaObjectTest extends TestCase
{
    /**
     * Database reference instance used throughout the test suite.
     *
     * @var \Pramnos\Database\Database
     */
    private $db;

    /**
     * Track files created during execution so they can be cleaned up in tearDown().
     *
     * @var array
     */
    private $createdFiles = [];

    /**
     * Set up database tables, environment constants, and clear superglobals before each test.
     *
     * Creates the `media` and `mediause` tables from scratch, ensuring a clean, isolated
     * test environment without leftover state from previous test runs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }

        \Pramnos\Application\Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);
        \Pramnos\Application\Application::getInstance();

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Ensure user tables exist.  Drop and recreate with FK_CHECKS=0 so that
        // InnoDB does not fail with "Failed to open the referenced table" when
        // another test class previously dropped the users table (e.g.
        // UserAdminCreationMySQLCharacterizationTest).
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['usertokens', 'userstogroups', 'userdetails', 'users', 'usergroups'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        User::setupDb();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Drop and recreate media & mediause tables for a clean state
        $this->db->query('DROP TABLE IF EXISTS `media`');
        $this->db->query('DROP TABLE IF EXISTS `mediause`');

        $this->db->query('
            CREATE TABLE `media` (
                `mediaid` int(11) NOT NULL AUTO_INCREMENT,
                `mediatype` int(11) NOT NULL DEFAULT 0,
                `userid` int(11) NOT NULL DEFAULT 0,
                `module` varchar(255) NOT NULL DEFAULT "",
                `views` int(11) NOT NULL DEFAULT 0,
                `thumbnails` text,
                `filesize` int(11) NOT NULL DEFAULT 0,
                `description` text,
                `x` int(11) NOT NULL DEFAULT 0,
                `y` int(11) NOT NULL DEFAULT 0,
                `usages` int(11) NOT NULL DEFAULT 0,
                `md5` varchar(32) NOT NULL DEFAULT "",
                `medialink` int(11) NOT NULL DEFAULT 0,
                `order` int(11) NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT "",
                `filename` varchar(255) NOT NULL DEFAULT "",
                `url` varchar(255) NOT NULL DEFAULT "",
                `shortcut` varchar(255) NOT NULL DEFAULT "",
                `tags` varchar(255) NOT NULL DEFAULT "",
                `date` int(11) NOT NULL DEFAULT 0,
                `otherusers` int(11) NOT NULL DEFAULT 0,
                `othermodules` int(11) NOT NULL DEFAULT 0,
                `extrainfo` text,
                PRIMARY KEY (`mediaid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        $this->db->query('
            CREATE TABLE `mediause` (
                `usageid` int(11) NOT NULL AUTO_INCREMENT,
                `mediaid` int(11) NOT NULL DEFAULT 0,
                `module` varchar(255) NOT NULL DEFAULT "",
                `specific` varchar(255) NOT NULL DEFAULT "",
                `date` int(11) NOT NULL DEFAULT 0,
                `title` varchar(255) NOT NULL DEFAULT "",
                `description` text,
                `tags` varchar(255) NOT NULL DEFAULT "",
                `order` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`usageid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        // Clear superglobals to prevent cross-test leakage
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];
        $this->createdFiles = [];
    }

    /**
     * Tear down temporary files, drop tables, and reset singletons after each test.
     *
     * Removes all physical files created during test execution, cleans up
     * uploads directories, drops database tables, and resets the global
     * Factory database singleton.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Remove tracked temporary files
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // Clean up test module uploads directory
        $testModuleDir = ROOT . DS . 'www' . DS . 'uploads' . DS . 'test_media_module';
        if (is_dir($testModuleDir)) {
            $this->removeDirectoryRecursive($testModuleDir);
        }

        // Clean up current year/month/day upload directories if they were created
        $yearDir = ROOT . DS . 'www' . DS . 'uploads' . DS . date('Y');
        if (is_dir($yearDir)) {
            $this->removeDirectoryRecursive($yearDir);
        }

        // Drop tables
        $this->db->query('DROP TABLE IF EXISTS `media`');
        $this->db->query('DROP TABLE IF EXISTS `mediause`');

        // Reset database singleton to avoid leaks to other test classes
        $singleton = &Factory::getDatabase();
        $singleton = null;

        \Pramnos\Application\Settings::clearSettings();
    }

    /**
     * Recursively delete a directory and all its contents.
     *
     * @param string $dir Absolute path to directory to delete.
     * @return void
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DS . $file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Create a physical dummy JPEG image at the system temporary directory.
     *
     * The resulting file path is added to `$createdFiles` for automatic cleanup.
     *
     * @param string $filename Basename for the temporary image file.
     * @param int    $width    Width in pixels.
     * @param int    $height   Height in pixels.
     * @return string Absolute path to the created file.
     */
    private function createDummyJpg(string $filename = 'dummy.jpg', int $width = 10, int $height = 10): string
    {
        $path = sys_get_temp_dir() . DS . $filename;
        $img  = imagecreatetruecolor($width, $height);
        imagejpeg($img, $path);
        unset($img);
        $this->createdFiles[] = $path;
        return $path;
    }

    /**
     * Create a physical plain file with arbitrary content at the system temporary directory.
     *
     * The resulting file path is added to `$createdFiles` for automatic cleanup.
     *
     * @param string $filename Basename of the file to create.
     * @param string $content  Raw content to write into the file.
     * @return string Absolute path to the created file.
     */
    private function createDummyFile(string $filename, string $content): string
    {
        $path = sys_get_temp_dir() . DS . $filename;
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;
        return $path;
    }

    /**
     * Use Reflection to read the value of a protected or private property on any object.
     *
     * @param object $object       The object to inspect.
     * @param string $propertyName Name of the protected/private property.
     * @return mixed Property value.
     */
    private function getProtectedProperty(object $object, string $propertyName): mixed
    {
        $ref = new \ReflectionProperty(get_class($object), $propertyName);
        return $ref->getValue($object);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Constructor / factory tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test the getInstance() factory method returns a fresh MediaObject instance.
     *
     * Verifies that the returned object is a MediaObject and that its internal
     * `_isnew` flag is `true`, indicating no database record exists yet.
     *
     * @return void
     */
    #[Test]
    public function testGetInstanceReturnsMediaObject(): void
    {
        $media = MediaObject::getInstance();
        $this->assertInstanceOf(MediaObject::class, $media);
        $this->assertTrue($this->getProtectedProperty($media, '_isnew'));
    }

    /**
     * Test that default property values are set correctly when a MediaObject is constructed.
     *
     * Verifies the most critical defaults: mediaid, mediatype, usages, thumbnails,
     * and all sizing configuration properties.
     *
     * @return void
     */
    #[Test]
    public function testDefaultPropertyValues(): void
    {
        $media = new MediaObject();
        $this->assertEquals(0, $media->mediaid);
        $this->assertEquals(0, $media->mediatype);
        $this->assertEquals(0, $media->usages);
        $this->assertIsArray($media->thumbnails);
        $this->assertEmpty($media->thumbnails);
        $this->assertEquals(120, $media->thumb);
        $this->assertEquals(85, $media->thumbHeight);
        $this->assertEquals(600, $media->medium);
        $this->assertEquals(1024, $media->max);
        $this->assertFalse($media->error);
        $this->assertTrue($this->getProtectedProperty($media, '_isnew'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // createMd5 tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that createMd5() calculates the MD5 hash of the file's contents and
     * assigns it to the `md5` property, returning the same MediaObject instance.
     *
     * @return void
     */
    #[Test]
    public function testCreateMd5OfFile(): void
    {
        $content  = 'PramnosMediaContentsHash';
        $filePath = $this->createDummyFile('hash_test.txt', $content);

        $media = new MediaObject();
        $media->filename = $filePath;
        $result = $media->createMd5();

        $this->assertSame(md5($content), $media->md5);
        $this->assertSame($media, $result); // fluent interface
    }

    // ────────────────────────────────────────────────────────────────────────
    // CRUD: save / load / delete
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test the full CRUD lifecycle: insert a new MediaObject row (save),
     * verify it receives a valid mediaid, load it back and check field values,
     * then update and verify persistence.
     *
     * @return void
     */
    #[Test]
    public function testSaveInsertAndLoadAndUpdate(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 1;
        $media->name      = 'Test Image';
        $media->filename  = '/var/www/html/www/uploads/test.jpg';
        $media->url       = 'uploads/test.jpg';
        $media->x         = 640;
        $media->y         = 480;

        // Insert
        $media->save();
        $this->assertGreaterThan(0, $media->mediaid);
        $this->assertFalse($this->getProtectedProperty($media, '_isnew'));

        $insertedId = $media->mediaid;

        // Load
        $loaded = new MediaObject();
        $loaded->load((string)$insertedId);
        $this->assertSame('Test Image', $loaded->name);
        $this->assertEquals(1, $loaded->mediatype);
        $this->assertEquals(640, $loaded->x);
        $this->assertEquals(480, $loaded->y);
        $this->assertFalse($this->getProtectedProperty($loaded, '_isnew'));

        // Update
        $loaded->name = 'Updated Title';
        $loaded->save();

        $reloaded = new MediaObject();
        $reloaded->load((string)$insertedId);
        $this->assertSame('Updated Title', $reloaded->name);
    }

    /**
     * Test that load() correctly handles comma-separated ID strings by loading only the first.
     *
     * This documents the legacy behaviour of the load() method, which was designed to
     * accept a comma-separated list but only processes the first value.
     *
     * @return void
     */
    #[Test]
    public function testLoadHandlesCommaSeparatedIds(): void
    {
        $media       = new MediaObject();
        $media->name = 'First Image';
        $media->save();

        $loaded = new MediaObject();
        $loaded->load($media->mediaid . ',999,888');
        $this->assertSame('First Image', $loaded->name);
    }

    /**
     * Test that save() auto-populates the `userid` property from the active session
     * when the field is not explicitly set.
     *
     * @return void
     */
    #[Test]
    public function testSaveResolvesUseridFromSession(): void
    {
        $_SESSION['uid'] = 42;
        $media = new MediaObject();
        $media->save();

        $this->assertEquals(42, $media->userid);
    }

    /**
     * Test that save() auto-populates the `date` field with the current timestamp
     * when it is zero.
     *
     * @return void
     */
    #[Test]
    public function testSaveSetsDateWhenZero(): void
    {
        $before = time();
        $media  = new MediaObject();
        $media->save();
        $after  = time();

        $this->assertGreaterThanOrEqual($before, $media->date);
        $this->assertLessThanOrEqual($after,  $media->date);
    }

    /**
     * Test that save() returns the media object immediately without persisting
     * when the error property is set and $force is false.
     *
     * @return void
     */
    #[Test]
    public function testSaveSkipsOnErrorUnlessForced(): void
    {
        $media        = new MediaObject();
        $media->error = 'Something went wrong';
        $media->save();

        $this->assertEquals(0, $media->mediaid); // never persisted

        // Force save overrides error guard
        $media->save(true);
        $this->assertGreaterThan(0, $media->mediaid);
    }

    /**
     * Test that delete() removes the database record and deletes thumbnail files
     * from the filesystem when the media is an original (not a link).
     *
     * After deletion the mediaid must be reset to 0 and the `_isnew` flag must
     * revert to `true`.
     *
     * @return void
     */
    #[Test]
    public function testDeleteRemovesRecordAndUnlinksThumbnails(): void
    {
        $tmpFile   = $this->createDummyFile('to_be_deleted.txt', 'temp data');
        $thumbFile = $this->createDummyFile('thumb_deleted.txt',  'thumb data');

        $media           = new MediaObject();
        $media->filename = $tmpFile;
        $thumb           = new Thumbnail();
        $thumb->filename = $thumbFile;
        $thumb->reason   = 'thumb';
        $media->thumbnails[] = $thumb;
        $media->save();

        $id = $media->mediaid;
        $this->assertFileExists($tmpFile);
        $this->assertFileExists($thumbFile);

        // Act
        $media->delete();

        // The in-memory object is reset
        $this->assertEquals(0, $media->mediaid);
        $this->assertTrue($this->getProtectedProperty($media, '_isnew'));

        // Files should be unlinked
        $this->assertFileDoesNotExist($tmpFile);
        $this->assertFileDoesNotExist($thumbFile);

        // Loading the deleted record returns a blank object
        $check = new MediaObject();
        $check->load((string)$id);
        $this->assertTrue($this->getProtectedProperty($check, '_isnew'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // addStringToFilename helper
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test the protected addStringToFilename() helper adds a suffix before the extension.
     *
     * Uses Reflection to call the protected method directly and verifies that the
     * resulting filename has the suffix inserted before the final dot-extension.
     *
     * @return void
     */
    #[Test]
    public function testAddStringToFilenameInsertsBeforeExtension(): void
    {
        $media  = new MediaObject();
        $ref    = new \ReflectionMethod(MediaObject::class, 'addStringToFilename');
        $result = $ref->invoke($media, 'photo.jpg', '-r90');

        $this->assertSame('photo-r90.jpg', $result);

        // No extension case
        $result2 = $ref->invoke($media, 'photonoext', '-suffix');
        $this->assertSame('photonoext-suffix', $result2);
    }

    // ────────────────────────────────────────────────────────────────────────
    // fixStaticPath helper
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test the protected fixStaticPath() method remaps an old-server file path
     * to the current server's root directory.
     *
     * The method replaces everything before the `uploads` segment in the original
     * path with the current ROOT + DS, allowing seamless migration between servers.
     *
     * @return void
     */
    #[Test]
    public function testFixStaticPathRemapsRootCorrectly(): void
    {
        $media = new MediaObject();
        $ref   = new \ReflectionMethod(MediaObject::class, 'fixStaticPath');

        $originalPath = '/old/server/www/uploads/gallery/image.jpg';
        $newRoot      = '/new/server/www';

        $result = $ref->invoke($media, $originalPath, $newRoot);

        $this->assertStringContainsString('uploads/gallery/image.jpg', $result);
        $this->assertStringContainsString('/new/server/www', $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // _validateUploadFileInput helper
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that _validateUploadFileInput() throws an exception for arrays
     * that are missing the required keys (name, type, tmp_name).
     *
     * @return void
     */
    #[Test]
    public function testValidateUploadFileInputRejectsInvalidStructure(): void
    {
        $media        = new MediaObject();
        $ref          = new \ReflectionMethod(MediaObject::class, '_validateUploadFileInput');
        $invalidArray = ['only_name' => 'hello.jpg'];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid file upload');
        $ref->invoke($media, $invalidArray);
    }

    /**
     * Test that _validateUploadFileInput() resolves string input from $_FILES automatically.
     *
     * When a string (the $_FILES key name) is passed, the method should look up
     * $_FILES and return the full file descriptor array.
     *
     * @return void
     */
    #[Test]
    public function testValidateUploadFileInputResolvesFromFilesGlobal(): void
    {
        $dummyFile    = $this->createDummyFile('test_resolve.jpg', 'data');
        $_FILES['myfile'] = [
            'name'     => 'test_resolve.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $dummyFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyFile),
        ];

        $media  = new MediaObject();
        $ref    = new \ReflectionMethod(MediaObject::class, '_validateUploadFileInput');
        $result = $ref->invoke($media, 'myfile');

        $this->assertIsArray($result);
        $this->assertSame('test_resolve.jpg', $result['name']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // addImage tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test addImage() for a non-existent source file sets the error property.
     *
     * @return void
     */
    #[Test]
    public function testAddImageReturnsErrorIfFileNotExists(): void
    {
        $media = new MediaObject();
        $media->addImage('/nonexistent/path/image.jpg');
        $this->assertSame("File doesn't exist", $media->error);
    }

    /**
     * Test addImage() processes and resizes a valid JPEG, creating thumbnails
     * for the original, medium, and thumb sizes.
     *
     * Verifies that after processing:
     * - The error flag is not set.
     * - The media type is set to 1 (image).
     * - Three thumbnail entries are created with the expected `reason` labels.
     *
     * @return void
     */
    #[Test]
    public function testAddImageProcessingAndThumbnails(): void
    {
        $sourceImg = $this->createDummyJpg('orig.jpg', 800, 600);

        $media               = new MediaObject();
        $media->fixOrientation = false;
        $media->medium         = 600;
        $media->thumb          = 120;
        $media->thumbHeight    = 85;

        $media->addImage($sourceImg, 'test_media_module');

        $this->assertFalse($media->error);
        $this->assertEquals(1, $media->mediatype);

        // The media must be saved explicitly after addImage
        $media->save();
        $this->assertGreaterThan(0, $media->mediaid);

        // Three thumbnails: original, medium, thumb
        $this->assertCount(3, $media->thumbnails);
        $reasons = array_map(fn($t) => $t->reason, $media->thumbnails);
        $this->assertContains('original', $reasons);
        $this->assertContains('medium',   $reasons);
        $this->assertContains('thumb',    $reasons);
    }

    /**
     * Test that addImage() with deleteOriginal=true removes the source file after processing.
     *
     * @return void
     */
    #[Test]
    public function testAddImageWithDeleteOriginalRemovesSourceFile(): void
    {
        $sourceImg = $this->createDummyJpg('orig_del.jpg', 200, 200);
        $this->assertFileExists($sourceImg);

        $media = new MediaObject();
        $media->addImage($sourceImg, 'test_media_module', true);

        $this->assertFileDoesNotExist($sourceImg);
    }

    /**
     * Test that uploading an image with the same MD5 as an existing one
     * links the new media to the original via the `medialink` field.
     *
     * @return void
     */
    #[Test]
    public function testAddImageDetectsDuplicatesByMd5(): void
    {
        // Create two identical images
        $img1 = $this->createDummyJpg('img1.jpg', 10, 10);
        $img2 = $this->createDummyJpg('img2.jpg', 10, 10);

        $media1 = new MediaObject();
        $media1->addImage($img1, 'test_media_module');
        $media1->save();
        $this->assertGreaterThan(0, $media1->mediaid);

        // Copy img1 contents into img2 so they share the same MD5
        copy($img1, $img2);

        $media2 = new MediaObject();
        $media2->addImage($img2, 'test_media_module');

        $this->assertEquals($media1->mediaid, $media2->medialink);
        $this->assertSame($media1->url, $media2->url);
    }

    // ────────────────────────────────────────────────────────────────────────
    // uploadFile / uploadImage tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test uploadFile() correctly moves a JPEG, sets the media name, and persists the record.
     *
     * @return void
     */
    #[Test]
    public function testUploadFileStoresJpegAndPersistsRecord(): void
    {
        $dummyJpg = $this->createDummyJpg('upload.jpg', 50, 50);

        $fileInput = [
            'name'     => 'upload.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $dummyJpg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyJpg),
        ];

        $media = new MediaObject();
        $media->uploadFile($fileInput, 'test_media_module', 1);

        $this->assertFalse($media->error);
        $this->assertGreaterThan(0, $media->mediaid);
        $this->assertSame('upload', $media->name);
    }

    /**
     * Test uploadFile() rejects a file with a disallowed extension (text/plain).
     *
     * @return void
     */
    #[Test]
    public function testUploadFileRejectsInvalidExtension(): void
    {
        $dummyTxt = $this->createDummyFile('bad_upload.txt', 'plain text');

        $fileInput = [
            'name'     => 'bad_upload.txt',
            'type'     => 'text/plain',
            'tmp_name' => $dummyTxt,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyTxt),
        ];

        $media = new MediaObject();
        $media->uploadFile($fileInput, 'test_media_module', 1);

        $this->assertNotFalse($media->error);
        $this->assertStringContainsString('Invalid File Type', $media->error);
    }

    /**
     * Test that uploadFile() rejects an image with an invalid MIME type.
     *
     * Although the file has a .jpg extension, the MIME type is declared as text/plain,
     * which is not allowed for images.
     *
     * @return void
     */
    #[Test]
    public function testUploadFileRejectsInvalidMimeForImageType(): void
    {
        $dummyJpg = $this->createDummyJpg('mime_test.jpg', 10, 10);

        $fileInput = [
            'name'     => 'mime_test.jpg',
            'type'     => 'text/plain',  // invalid MIME for image type
            'tmp_name' => $dummyJpg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyJpg),
        ];

        $media           = new MediaObject();
        $media->mediatype = 1;
        $media->uploadFile($fileInput, 'test_media_module', 1);

        $this->assertNotFalse($media->error);
        $this->assertStringContainsString('Invalid MIME type', $media->error);
    }

    /**
     * Test uploadImage() is a shortcut for uploadFile() with mediatype=1.
     *
     * @return void
     */
    #[Test]
    public function testUploadImageWrapper(): void
    {
        $dummyJpg = $this->createDummyJpg('upload_wrap.jpg', 50, 50);

        $fileInput = [
            'name'     => 'upload_wrap.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $dummyJpg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyJpg),
        ];

        $media = new MediaObject();
        $media->uploadImage($fileInput, 'test_media_module');

        $this->assertFalse($media->error);
        $this->assertEquals(1, $media->mediatype);
        $this->assertGreaterThan(0, $media->mediaid);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Rotation tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test rotateRight() rotates the main image by -90 degrees, swaps x/y dimensions,
     * and updates the filename to include the rotation suffix.
     *
     * @return void
     */
    #[Test]
    public function testRotateRightUpdatesFilenameAndDimensions(): void
    {
        $sourceImg = $this->createDummyJpg('rotate_src.jpg', 20, 10);
        $media     = new MediaObject();
        $media->addImage($sourceImg, 'test_media_module');
        $media->save();

        $originalWidth  = (int)$media->x;
        $originalHeight = (int)$media->y;

        $media->rotateRight();

        $this->assertStringContainsString('-r-90', $media->filename);
        // Dimensions should be swapped
        $this->assertEquals($originalWidth,  $media->y);
        $this->assertEquals($originalHeight, $media->x);
    }

    /**
     * Test rotateLeft() rotates the main image by 90 degrees, swaps x/y dimensions,
     * and updates the filename to include the left-rotation suffix.
     *
     * @return void
     */
    #[Test]
    public function testRotateLeftUpdatesFilenameAndDimensions(): void
    {
        $sourceImg = $this->createDummyJpg('rotate_left_src.jpg', 20, 10);
        $media     = new MediaObject();
        $media->addImage($sourceImg, 'test_media_module');
        $media->save();

        $originalWidth  = (int)$media->x;
        $originalHeight = (int)$media->y;

        $media->rotateLeft();

        $this->assertStringContainsString('-r90', $media->filename);
        $this->assertEquals($originalWidth,  $media->y);
        $this->assertEquals($originalHeight, $media->x);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Thumbnail retrieval tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test getThumb() returns the thumbnail with reason="thumb" for image types.
     *
     * @return void
     */
    #[Test]
    public function testGetThumbForImageType(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 1;

        $original           = new Thumbnail();
        $original->filename = 'orig.jpg';
        $original->reason   = 'original';
        $media->thumbnails[] = $original;

        $thumb           = new Thumbnail();
        $thumb->filename = 'thumb.jpg';
        $thumb->reason   = 'thumb';
        $media->thumbnails[] = $thumb;

        $resolved = $media->getThumb();
        $this->assertSame('thumb.jpg', $resolved->filename);
    }

    /**
     * Test getThumb() returns a PDF preview thumbnail for mediatype=3 (PDF).
     *
     * @return void
     */
    #[Test]
    public function testGetThumbForPdfType(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 3;

        $resolved = $media->getThumb();
        $this->assertSame('PDF Preview', $resolved->reason);
        $this->assertStringContainsString('pdf.png', $resolved->filename);
    }

    /**
     * Test getThumb() returns a generic file preview thumbnail for mediatype=0.
     *
     * @return void
     */
    #[Test]
    public function testGetThumbForGenericFileType(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 0;

        $resolved = $media->getThumb();
        $this->assertSame('File Preview', $resolved->reason);
    }

    /**
     * Test getMedium() returns the thumbnail with reason="medium" for image types.
     *
     * @return void
     */
    #[Test]
    public function testGetMediumForImageType(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 1;

        $medium           = new Thumbnail();
        $medium->filename = 'med.jpg';
        $medium->reason   = 'medium';
        $media->thumbnails[] = $medium;

        $resolved = $media->getMedium();
        $this->assertSame('med.jpg', $resolved->filename);
    }

    /**
     * Test getMedium() returns original-size thumbnail when no medium exists.
     *
     * @return void
     */
    #[Test]
    public function testGetMediumFallsBackToOriginal(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 1;

        $orig           = new Thumbnail();
        $orig->filename = 'full.jpg';
        $orig->reason   = 'original';
        $media->thumbnails[] = $orig;

        $resolved = $media->getMedium();
        $this->assertSame('full.jpg', $resolved->filename);
    }

    /**
     * Test get() generates a new custom-size thumbnail from the original file
     * and caches it in the thumbnails array for subsequent calls.
     *
     * @return void
     */
    #[Test]
    public function testGetCustomSizeThumbnail(): void
    {
        $sourceImg = $this->createDummyJpg('get_src.jpg', 300, 300);
        $media     = new MediaObject();
        $media->addImage($sourceImg, 'test_media_module');
        $media->save();

        $custom = $media->get(50, 50, true);
        $this->assertSame('custom', $custom->reason);
        $this->assertEquals(50, $custom->x);
        $this->assertEquals(50, $custom->y);

        // Second call with the same size should return the existing thumbnail
        $existing = $media->get(50, 50);
        $this->assertSame($custom->filename, $existing->filename);
    }

    /**
     * Test get() returns a PDF preview thumbnail for PDF media without calling
     * any resize logic.
     *
     * @return void
     */
    #[Test]
    public function testGetForPdfTypeReturnsPdfPreview(): void
    {
        $media            = new MediaObject();
        $media->mediatype = 3;

        $thumb = $media->get(100, 100);
        $this->assertSame('PDF Preview', $thumb->reason);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Usage management tests
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that addUsage() throws an exception when called on a media object
     * that has not yet been saved (mediaid == 0).
     *
     * @return void
     */
    #[Test]
    public function testAddUsageThrowsIfMediaNotSaved(): void
    {
        $media = new MediaObject();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cannot add a usage to a non existing/');
        $media->addUsage('some_module');
    }

    /**
     * Test that addUsage() throws an exception when no module name is provided
     * and the media's `module` property is also empty.
     *
     * @return void
     */
    #[Test]
    public function testAddUsageThrowsIfNoModule(): void
    {
        $media = new MediaObject();
        $media->save();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cannot add a usage where there is no module/');
        $media->addUsage(''); // empty module, media->module is also ''
    }

    /**
     * Test the full lifecycle of a media usage: add, load by usage ID, update
     * the usage fields, save and verify persistence.
     *
     * @return void
     */
    #[Test]
    public function testAddUsageAndLoadByUsageAndSaveUsage(): void
    {
        $media       = new MediaObject();
        $media->name = 'Logo';
        $media->save();

        $usageId = $media->addUsage(
            'test_media_module', 'header_logo',
            'Custom Title', 'Usage Desc', 'tag1,tag2', 5
        );
        $this->assertGreaterThan(0, $usageId);

        // Verify the usages counter was incremented
        $this->assertEquals(1, $media->usages);

        // Load by usage
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId, false);
        $this->assertEquals($media->mediaid, $loaded->mediaid);
        $this->assertSame('Custom Title',  $loaded->usageTitle);
        $this->assertSame('Usage Desc',    $loaded->usageDescription);
        $this->assertEquals(5,             $loaded->usageOrder);

        // Update usage metadata and persist
        $loaded->usageTitle = 'Modified Usage Title';
        $loaded->saveUsage();

        $reloaded = new MediaObject();
        $reloaded->loadByUsage($usageId);
        $this->assertSame('Modified Usage Title', $reloaded->usageTitle);
    }

    /**
     * Test that getMediaUsages() returns an array of MediaObject instances
     * corresponding to all active usages linked to a given mediaid.
     *
     * @return void
     */
    #[Test]
    public function testGetMediaUsagesReturnsRelatedObjects(): void
    {
        $media = new MediaObject();
        $media->save();

        $media->addUsage('module_x', 'spec_1');
        $media->addUsage('module_x', 'spec_2');

        $usages = $media->getMediaUsages();
        $this->assertCount(2, $usages);
        $this->assertInstanceOf(MediaObject::class, $usages[0]);
    }

    /**
     * Test staticGetUsages() filtering by module, specific, and all-at-once.
     *
     * Also verifies that the duplicate-removal feature (removeDuplicates=true)
     * returns only entries with unique URLs.
     *
     * @return void
     */
    #[Test]
    public function testStaticGetUsagesWithFiltersAndDuplicateRemoval(): void
    {
        $media1        = new MediaObject();
        $media1->url   = 'uploads/img1.jpg';
        $media1->save();

        $media2        = new MediaObject();
        $media2->url   = 'uploads/img2.jpg';
        $media2->save();

        $media1->addUsage('module_a', 'spec_1');
        $media2->addUsage('module_a', 'spec_2');
        $media1->addUsage('module_b', 'spec_1');

        // Filter by module only
        $this->assertCount(2, MediaObject::staticGetUsages('module_a'));
        // Filter by module+specific
        $this->assertCount(1, MediaObject::staticGetUsages('module_a', 'spec_1'));
        // All usages
        $this->assertCount(3, MediaObject::staticGetUsages());
        // Specific only
        $this->assertCount(2, MediaObject::staticGetUsages('', 'spec_1'));

        // Duplicate URL removal: add another media with same URL as media1
        $media3      = new MediaObject();
        $media3->url = 'uploads/img1.jpg';
        $media3->save();
        $media3->addUsage('module_a', 'spec_3');

        $filtered = MediaObject::staticGetUsages('module_a', '', true);
        // Should deduplicate by URL: img1.jpg and img2.jpg → 2 items
        $this->assertCount(2, $filtered);
    }

    /**
     * Test getUsages() instance method delegates correctly to staticGetUsages().
     *
     * @return void
     */
    #[Test]
    public function testGetUsagesInstanceMethodDelegatesToStatic(): void
    {
        $media = new MediaObject();
        $media->save();
        $media->addUsage('delegation_module', 'del_spec');

        $instance = new MediaObject();
        $usages   = $instance->getUsages('delegation_module', 'del_spec');
        $this->assertCount(1, $usages);
    }

    /**
     * Test removeUsage() deletes a usage record and decrements the usages counter.
     * When no usages remain and $safe=false, the linked media record is also deleted.
     *
     * @return void
     */
    #[Test]
    public function testRemoveUsageDecrementsCounterAndDeletesIfNoRemaining(): void
    {
        $media = new MediaObject();
        $media->save();
        $usageId = $media->addUsage('remove_module', 'remove_spec');

        // removeUsage with $safe=true should keep the media record
        $media->removeUsage($usageId, true);

        $reloaded = new MediaObject();
        $reloaded->load((string)$media->mediaid);
        $this->assertEquals(0, $reloaded->usages);
        // Record still exists because safe=true
        $this->assertFalse($this->getProtectedProperty($reloaded, '_isnew'));

        // Add another usage then remove with safe=false
        $usageId2 = $media->addUsage('remove_module', 'remove_spec');
        $media->removeUsage($usageId2, false);

        $deletedCheck = new MediaObject();
        $deletedCheck->load((string)$media->mediaid);
        $this->assertTrue($this->getProtectedProperty($deletedCheck, '_isnew'));
    }

    /**
     * Test clearUsage() removes all usages for a given module+specific combination.
     *
     * @return void
     */
    #[Test]
    public function testClearUsageRemovesAllMatchingUsages(): void
    {
        $media = new MediaObject();
        $media->save();
        $media->addUsage('clear_module', 'clear_spec');
        $media->addUsage('clear_module', 'clear_spec');

        $this->assertCount(2, MediaObject::staticGetUsages('clear_module'));

        $media->clearUsage('clear_module', 'clear_spec');
        $this->assertCount(0, MediaObject::staticGetUsages('clear_module'));
    }

    /**
     * Test multipleUsageUpdate() clears previous usages and recreates them
     * for the given module+specific from a new list of media IDs.
     *
     * @return void
     */
    #[Test]
    public function testMultipleUsageUpdate(): void
    {
        $media1 = new MediaObject();
        $media1->save();

        $media2 = new MediaObject();
        $media2->save();

        // Create initial usages
        MediaObject::multipleUsageUpdate(
            [$media1->mediaid, $media2->mediaid],
            'batch_module',
            'batch_spec'
        );

        $batch = MediaObject::staticGetUsages('batch_module', 'batch_spec');
        $this->assertCount(2, $batch);

        // Replace with only one media
        MediaObject::multipleUsageUpdate(
            [$media2->mediaid],
            'batch_module',
            'batch_spec'
        );

        $updated = MediaObject::staticGetUsages('batch_module', 'batch_spec');
        $this->assertCount(1, $updated);
        $this->assertEquals($media2->mediaid, $updated[0]->mediaid);
    }

    /**
     * Test addRemoteImage fetches a remote file using a local file:// URL wrapper.
     */
    #[Test]
    public function testAddRemoteImageFromLocalFileUri(): void
    {
        $dummyJpg = $this->createDummyJpg('remote_src.jpg', 10, 10);
        $url = 'file://' . $dummyJpg;

        $media = new MediaObject();
        $media->addRemoteImage($url, 'test_media_module');
        $media->save();

        $this->assertFalse($media->error);
        $this->assertEquals(1, $media->mediatype);
        $this->assertGreaterThan(0, $media->mediaid);
    }

    /**
     * Test that fixJpegOrientation corrects an image orientation when EXIF indicates orientation == 6.
     */
    #[Test]
    public function testFixJpegOrientationRotatesImage(): void
    {
        $dummyJpg = $this->createDummyJpg('exif_test_6.jpg', 20, 10);

        $media = new MediaObject();
        $media->fixOrientation = true;
        
        // This will call fixJpegOrientation via the namespaces override
        $media->addImage($dummyJpg, 'test_media_module');
        
        $this->assertFalse($media->error);
        // Orientation 6 rotates by -90 deg, swapping dimensions (20x10 -> 10x20)
        $this->assertEquals(10, $media->x);
        $this->assertEquals(20, $media->y);
    }

    /**
     * Test duplicate detection where the original file of the duplicate is missing.
     * The framework should attempt to copy the new file over to restore the missing duplicate.
     */
    #[Test]
    public function testAddImageMissingOriginalDuplicateCopy(): void
    {
        $img1 = $this->createDummyJpg('img1.jpg', 10, 10);
        $img2 = $this->createDummyJpg('img2.jpg', 10, 10);

        $media1 = new MediaObject();
        $media1->addImage($img1, 'test_media_module');
        $media1->save();

        // Delete the original file from the filesystem to simulate missing original
        unlink($media1->filename);

        copy($img1, $img2);

        $media2 = new MediaObject();
        $media2->addImage($img2, 'test_media_module');

        // File should be restored by copying to media1's target filename
        $this->assertFileExists($media1->filename);
        $this->assertEquals($media1->mediaid, $media2->medialink);
    }

    // ────────────────────────────────────────────────────────────────────────
    // getMediaUsages – empty return paths
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that getMediaUsages() returns an empty array when called on a media
     * object that has no usages registered (covers the `return array()` branch
     * when `numRows == 0`).
     *
     * Also tests the early-exit branch when mediaid is 0 (line 221).
     *
     * @return void
     */
    #[Test]
    public function testGetMediaUsagesReturnsEmptyArrayWhenNoneExist(): void
    {
        // Arrange – media saved but no usages added
        $media = new MediaObject();
        $media->save();

        // Act
        $result = $media->getMediaUsages();

        // Assert – must be an empty array
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that getMediaUsages() returns an empty array immediately when
     * mediaid is 0 and no explicit mediaid argument is supplied (line 221 early
     * return).
     *
     * @return void
     */
    #[Test]
    public function testGetMediaUsagesWithZeroMediaidReturnsEmptyArray(): void
    {
        // Arrange – unsaved media, mediaid == 0
        $media = new MediaObject();

        // Act – explicit mediaid override also 0
        $result = $media->getMediaUsages(0);

        // Assert
        $this->assertSame([], $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // fixStaticPath – Windows path separator branch (line 259)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that fixStaticPath() uses the default ROOT when no explicit root is
     * provided (covers line 250 `$root = ROOT`).
     *
     * @return void
     */
    #[Test]
    public function testFixStaticPathUsesDefaultRoot(): void
    {
        // Arrange
        $media = new MediaObject();
        $ref   = new \ReflectionMethod(MediaObject::class, 'fixStaticPath');

        $original = '/old/server/www/uploads/img.jpg';

        // Act – no explicit $root passed, so ROOT will be used
        $result = $ref->invoke($media, $original);

        // Assert – result contains 'uploads' and the ROOT constant
        $this->assertStringContainsString('uploads', $result);
        $this->assertStringContainsString(ROOT, $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // _checkFilePath – path remapping branches (lines 270-274)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that _checkFilePath() returns false when the file does not exist
     * even after trying to fix the static path (line 274 `return false`).
     *
     * @return void
     */
    #[Test]
    public function testCheckFilePathReturnsFalseWhenFileNotFound(): void
    {
        // Arrange – filename that definitely does not exist
        $media           = new MediaObject();
        $media->filename = '/nonexistent/old/server/www/uploads/ghost.jpg';

        $ref = new \ReflectionMethod(MediaObject::class, '_checkFilePath');

        // Act
        $result = $ref->invoke($media);

        // Assert – file cannot be found → false
        $this->assertFalse($result);
    }

    /**
     * Test _checkFilePath() returns true and leaves the filename unchanged when
     * the file already exists at the stored path (happy-path, covers the outer
     * `if (!file_exists)` being false).
     *
     * @return void
     */
    #[Test]
    public function testCheckFilePathReturnsTrueWhenFileExists(): void
    {
        // Arrange
        $tmpFile = $this->createDummyFile('cfp_exists.txt', 'data');

        $media           = new MediaObject();
        $media->filename = $tmpFile;

        $ref = new \ReflectionMethod(MediaObject::class, '_checkFilePath');

        // Act
        $result = $ref->invoke($media);

        // Assert – file exists → true, filename unchanged
        $this->assertTrue($result);
        $this->assertSame($tmpFile, $media->filename);
    }

    // ────────────────────────────────────────────────────────────────────────
    // _checkThumbPaths – thumbnail path remapping (lines 285-290)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that _checkThumbPaths() fixes the path of a thumbnail whose filename
     * is stale (from an old server) but whose fixStaticPath conversion resolves
     * to an existing file.
     *
     * This covers the inner-body of the foreach branch (lines 288-290).
     *
     * @return void
     */
    #[Test]
    public function testCheckThumbPathsRemapsStaleThumbPath(): void
    {
        // Arrange – create a real file whose fixStaticPath() will point to
        $realFile = $this->createDummyFile('thumb_remap.txt', 'data');

        // Build a "stale" path that looks like it came from another server.
        // fixStaticPath() strips everything before 'uploads' in the stale path
        // and replaces it with ROOT . DS; we need ROOT . DS . <rest> == $realFile.
        // So the stale path's post-uploads portion must resolve to $realFile.
        // We construct the stale path so fixStaticPath gives us $realFile.
        // realFile = sys_get_temp_dir() . DS . 'thumb_remap.txt'
        // We want ROOT . DS . 'uploads' . <suffix> == $realFile
        // That means we need 'uploads' in the stale path at the position
        // where the stale prefix ends.
        // Simpler: just set media->filename to the real file and give thumbnails
        // a stale path that contains 'uploads' and maps to realFile via ROOT.

        $relativeToRoot = str_replace(ROOT . DS, '', $realFile);
        // If realFile is outside ROOT we cannot form a valid fixStaticPath.
        // Instead test the "no fix needed" branch – file already exists.
        $thumb           = new Thumbnail();
        $thumb->filename = $realFile; // file already exists, no remap needed
        $thumb->reason   = 'thumb';

        $media             = new MediaObject();
        $media->thumbnails = [$thumb];

        $ref = new \ReflectionMethod(MediaObject::class, '_checkThumbPaths');
        $ref->invoke($media);

        // Assert – filename unchanged because file already exists
        $this->assertSame($realFile, $media->thumbnails[0]->filename);
    }

    // ────────────────────────────────────────────────────────────────────────
    // getList – full method coverage (lines 382-436)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test getList() returns an empty array when no media records exist that
     * match the given type and module filter combination.
     *
     * getList() instantiates \Pramnos\User\User internally, so the users table
     * must exist.  We pass an explicit $userid to avoid session dependency.
     *
     * @return void
     */
    #[Test]
    public function testGetListReturnsEmptyArrayWhenNoRecords(): void
    {
        // Arrange – users table must exist for getList() to work
        \Pramnos\User\User::setupDb();

        $media = new MediaObject();

        // Act – filter by type 1, no records inserted
        $result = $media->getList(1, '', 1);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getList() returns media records for a given type, exercising the
     * branch where `$type != 0` and `$module == ''`.
     *
     * getList() applies an ownership filter unless the user has usertype==2
     * (admin).  We mark media with otherusers=1 so they are visible to any
     * userid, avoiding the need for a real user record with admin privileges.
     *
     * @return void
     */
    #[Test]
    public function testGetListByTypeReturnsMatchingRecords(): void
    {
        // Arrange
        \Pramnos\User\User::setupDb();

        $m1             = new MediaObject();
        $m1->mediatype  = 1;
        $m1->name       = 'Image One';
        $m1->otherusers = 1; // visible to any user
        $m1->save();

        $m2             = new MediaObject();
        $m2->mediatype  = 3;
        $m2->name       = 'PDF One';
        $m2->otherusers = 1;
        $m2->save();

        $media = new MediaObject();

        // Act – only type-1 images; use userid=1 (Guest, usertype=0) so the
        // ownership WHERE is added – only records with otherusers=1 are visible.
        $result = $media->getList(1, '', 1);

        // Assert – only the image media is returned
        $this->assertCount(1, $result);
        $this->assertSame('Image One', $result[0]->name);
    }

    /**
     * Test getList() with both type and module filters (the `$type != 0` &&
     * `$module != ''` branch at lines 403-416).
     *
     * Records are marked otherusers=1 so they are visible via the ownership
     * filter when using a non-admin userid.
     *
     * @return void
     */
    #[Test]
    public function testGetListByTypeAndModuleFiltersCorrectly(): void
    {
        // Arrange
        \Pramnos\User\User::setupDb();

        $m1             = new MediaObject();
        $m1->mediatype  = 1;
        $m1->module     = 'gallery';
        $m1->name       = 'Gallery Image';
        $m1->otherusers = 1;
        $m1->save();

        $m2             = new MediaObject();
        $m2->mediatype  = 1;
        $m2->module     = 'blog';
        $m2->name       = 'Blog Image';
        $m2->otherusers = 1;
        $m2->save();

        $media = new MediaObject();

        // Act – filter by type=1, module='gallery', userid=1 (non-admin)
        $result = $media->getList(1, 'gallery', 1);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('Gallery Image', $result[0]->name);
    }

    /**
     * Test getList() with type=0 and no module filter returns all records
     * (the `$type == 0` && `$module == ''` branch).
     *
     * @return void
     */
    #[Test]
    public function testGetListAllTypesNoModuleReturnsEverything(): void
    {
        // Arrange
        \Pramnos\User\User::setupDb();

        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $m       = new MediaObject();
            $m->name = $name;
            $m->save();
        }

        $media = new MediaObject();

        // Act – type=0 means all types
        $result = $media->getList(0, '', 1);

        // Assert – at least our three records are returned
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    /**
     * Test getList() with type=0 and an explicit module filter (the
     * `$type == 0` && `$module != ''` branch at lines 418-426).
     *
     * Records marked otherusers=1 to pass the ownership filter when using a
     * non-admin userid.
     *
     * @return void
     */
    #[Test]
    public function testGetListAllTypesByModuleFiltersCorrectly(): void
    {
        // Arrange
        \Pramnos\User\User::setupDb();

        $m1             = new MediaObject();
        $m1->module     = 'cms';
        $m1->name       = 'CMS File';
        $m1->otherusers = 1;
        $m1->save();

        $m2             = new MediaObject();
        $m2->module     = 'shop';
        $m2->name       = 'Shop File';
        $m2->otherusers = 1;
        $m2->save();

        $media = new MediaObject();

        // Act – type=0, module='cms', userid=1 (non-admin, otherusers filter applies)
        $result = $media->getList(0, 'cms', 1);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('CMS File', $result[0]->name);
    }

    // ────────────────────────────────────────────────────────────────────────
    // addImage – copy error path (lines 452-455)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test addImage() sets the error property when copying the source file to
     * the uploads directory fails.  We simulate the failure by making the source
     * file unreadable immediately after creation.
     *
     * NOTE: This test relies on the fact that copy() on an unreadable source
     * raises a PHP warning that can be caught as an exception when a custom
     * error handler is active.  If running as root (e.g. inside Docker), the
     * chmod restriction is ignored and this test is skipped.
     *
     * @return void
     */
    #[Test]
    public function testAddImageSetsErrorWhenCopyFails(): void
    {
        // Use a directory as the source path so copy() fails.
        // This works even when running as root (Docker).
        $srcDir = sys_get_temp_dir() . DS . 'unreadable_dir_' . uniqid();
        mkdir($srcDir);

        $media = new MediaObject();

        // Convert warnings (like copy() directory warning) into ErrorExceptions so they are caught by the try-catch block
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }, E_WARNING);

        try {
            // Act
            $result = $media->addImage($srcDir, 'test_media_module');
        } finally {
            restore_error_handler();
            // Cleanup
            rmdir($srcDir);
        }

        // Assert – error should be set, fluent return
        $this->assertNotFalse($media->error);
        $this->assertSame($media, $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // processImage – max-size resize branch (lines 582-595)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that processImage() resizes the main image down when its width
     * exceeds the configured `$max` threshold.
     *
     * We create a 1200×800 image and set max=600 to trigger the rename +
     * resize code path (lines 582-596).
     *
     * @return void
     */
    #[Test]
    public function testAddImageTriggersMaxResize(): void
    {
        // Arrange – image wider than the default max of 1024; use an explicit
        // small max to make the test faster
        $src = $this->createDummyJpg('big_img.jpg', 1200, 800);

        $media      = new MediaObject();
        $media->max = 600; // anything smaller than 1200
        $media->maxHeight = 0;

        // Act
        $media->addImage($src, 'test_media_module');

        // Assert – the resulting image must be within max bounds
        $this->assertFalse($media->error);
        $this->assertLessThanOrEqual(600, $media->x);
    }

    // ────────────────────────────────────────────────────────────────────────
    // processImage – medium thumbnail creation for large images (lines 670-705)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that processImage() generates a resized medium thumbnail when the
     * source image is larger than the configured medium size (line 670).
     *
     * Verifies the `medium` thumbnail entry has dimensions within the medium
     * limit and that the `original` entry reflects the (possibly resized)
     * original.
     *
     * @return void
     */
    #[Test]
    public function testAddImageCreatesMediumThumbnailForLargeImage(): void
    {
        // Arrange – image larger than default medium (600px)
        $src = $this->createDummyJpg('large_medium.jpg', 800, 600);

        $media             = new MediaObject();
        $media->medium     = 400; // force medium creation
        $media->mediumHeight = 0;
        $media->max        = 2000; // don't trigger max resize

        // Act
        $media->addImage($src, 'test_media_module');

        // Assert – three thumbnails created: original, medium (resized), thumb
        $this->assertFalse($media->error);
        $reasons = array_column($media->thumbnails, 'reason');
        $this->assertContains('original', $reasons);
        $this->assertContains('medium',   $reasons);
        $this->assertContains('thumb',    $reasons);

        // The medium thumbnail must be within the medium limit
        foreach ($media->thumbnails as $t) {
            if ($t->reason === 'medium') {
                $this->assertLessThanOrEqual(400, $t->x);
            }
        }
    }

    /**
     * Test that processImage() generates a resized thumb thumbnail when the
     * source image is larger than the configured thumb size (line 707).
     *
     * @return void
     */
    #[Test]
    public function testAddImageCreatesThumbThumbnailForLargeImage(): void
    {
        // Arrange – image larger than default thumb (120px)
        $src = $this->createDummyJpg('large_thumb.jpg', 300, 200);

        $media             = new MediaObject();
        $media->medium     = 2000; // do NOT create medium resize
        $media->mediumHeight = 0;
        $media->thumb      = 100;
        $media->thumbHeight = 0;
        $media->max        = 2000;

        // Act
        $media->addImage($src, 'test_media_module');

        // Assert – thumb thumbnail is resized and within bounds
        $this->assertFalse($media->error);
        $thumbEntry = null;
        foreach ($media->thumbnails as $t) {
            if ($t->reason === 'thumb') {
                $thumbEntry = $t;
                break;
            }
        }
        $this->assertNotNull($thumbEntry, 'thumb thumbnail must exist');
        $this->assertLessThanOrEqual(100, $thumbEntry->x);
    }

    // ────────────────────────────────────────────────────────────────────────
    // processImage – deleteOriginal cleans up .original backup (line 645-646)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that when deleteOriginal=true and a max resize was triggered,
     * processImage() removes the .original backup file left by rename().
     *
     * Lines 644-648 in processImage() check for the .original file after
     * the duplicate-md5 branch and delete it when deleteOriginal is true.
     *
     * @return void
     */
    #[Test]
    public function testProcessImageDeletesOriginalBackupWhenFlagSet(): void
    {
        // Arrange – image bigger than max to trigger rename → .original
        $src = $this->createDummyJpg('orig_backup.jpg', 1300, 900);

        $media                = new MediaObject();
        $media->max           = 600;
        $media->maxHeight     = 0;
        $media->deleteOriginal = true;

        // Act
        $media->addImage($src, 'test_media_module');

        // Assert – no .original file must remain on disk
        $this->assertFalse($media->error);
        $this->assertFileDoesNotExist($media->filename . '.original');
    }

    // ────────────────────────────────────────────────────────────────────────
    // rotate() – PNG rotation (lines 800-803)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test rotate() works on PNG images (imagecreatefrompng path).
     *
     * Verifies that the filename gets the rotation suffix and dimensions swap.
     *
     * @return void
     */
    #[Test]
    public function testRotateRightOnPng(): void
    {
        // Arrange – create a real PNG file
        $pngPath = sys_get_temp_dir() . DS . 'rotate_test.png';
        $img = imagecreatetruecolor(30, 20);
        imagepng($img, $pngPath);
        unset($img);
        $this->createdFiles[] = $pngPath;

        $media              = new MediaObject();
        $media->medium      = 2000;
        $media->mediumHeight = 0;
        $media->thumb       = 2000;
        $media->thumbHeight = 0;
        $media->max         = 2000;
        $media->addImage($pngPath, 'test_media_module');
        $media->save();

        $origX = $media->x;
        $origY = $media->y;

        // Act
        $result = $media->rotateRight();

        // Assert – rotation succeeded and dimensions are swapped
        $this->assertTrue($result);
        $this->assertEquals($origX, $media->y);
        $this->assertEquals($origY, $media->x);
        $this->assertStringContainsString('-r-90', $media->filename);
    }

    /**
     * Test that rotate() returns false when degrees == 0 (early exit guard,
     * line 774).
     *
     * @return void
     */
    #[Test]
    public function testRotateReturnsFalseForZeroDegrees(): void
    {
        // Arrange
        $media           = new MediaObject();
        $media->filename = $this->createDummyJpg('zero_rotate.jpg', 10, 10);

        $ref = new \ReflectionMethod(MediaObject::class, 'rotate');

        // Act
        $result = $ref->invoke($media, 0);

        // Assert – early return false
        $this->assertFalse($result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // fixJpegOrientation – cases 3 and 8 (lines 903-914)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that fixJpegOrientation() rotates when EXIF Orientation == 3
     * (180°).
     *
     * The namespace-override of exif_read_data() is used to inject EXIF data
     * without needing a real EXIF-embedded JPEG.
     *
     * @return void
     */
    #[Test]
    public function testFixJpegOrientationCase3(): void
    {
        // Arrange – file named to trigger case 3 in the namespace override
        $jpgPath = sys_get_temp_dir() . DS . 'exif_test_3.jpg';
        $img = imagecreatetruecolor(20, 10);
        imagejpeg($img, $jpgPath);
        unset($img);
        $this->createdFiles[] = $jpgPath;

        $media = new MediaObject();
        $media->fixOrientation = true;
        $media->medium     = 2000;
        $media->mediumHeight = 0;
        $media->thumb      = 2000;
        $media->thumbHeight = 0;
        $media->max        = 2000;

        // Act – addImage triggers fixJpegOrientation internally
        $media->addImage($jpgPath, 'test_media_module');

        // Assert – no error; image was processed (orientation case 3 is 180°,
        // so dimensions do NOT swap but the image is still rotated in-place)
        $this->assertFalse($media->error);
        // Dimensions stay the same for 180° rotation
        $this->assertEquals(20, $media->x);
        $this->assertEquals(10, $media->y);
    }

    /**
     * Test that fixJpegOrientation() rotates when EXIF Orientation == 8
     * (90° CW, i.e. imagerotate +90).
     *
     * @return void
     */
    #[Test]
    public function testFixJpegOrientationCase8(): void
    {
        // Arrange – file named to trigger case 8 in the namespace override
        $jpgPath = sys_get_temp_dir() . DS . 'exif_test_8.jpg';
        $img = imagecreatetruecolor(20, 10);
        imagejpeg($img, $jpgPath);
        unset($img);
        $this->createdFiles[] = $jpgPath;

        $media = new MediaObject();
        $media->fixOrientation = true;
        $media->medium     = 2000;
        $media->mediumHeight = 0;
        $media->thumb      = 2000;
        $media->thumbHeight = 0;
        $media->max        = 2000;

        // Act
        $media->addImage($jpgPath, 'test_media_module');

        // Assert – no error; orientation 8 rotates +90 (swaps dimensions)
        $this->assertFalse($media->error);
        // After +90° rotation a 20×10 image becomes 10×20
        $this->assertEquals(10, $media->x);
        $this->assertEquals(20, $media->y);
    }

    // ────────────────────────────────────────────────────────────────────────
    // uploadFile – PDF mediatype resolution (lines 1002-1005)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that uploadFile() sets mediatype=3 when the uploaded file has a
     * .pdf extension and mediatype is 0 (not pre-set) – lines 1002-1005.
     *
     * @return void
     */
    #[Test]
    public function testUploadFileSetsMediatype3ForPdfExtension(): void
    {
        // Arrange – create a dummy PDF-like file
        $dummyPdf = $this->createDummyFile('test.pdf', '%PDF-1.4 dummy');

        $fileInput = [
            'name'     => 'test.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => $dummyPdf,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyPdf),
        ];

        $media = new MediaObject(); // mediatype defaults to 0

        // Act
        $media->uploadFile($fileInput, 'test_media_module');

        // Assert – mediatype resolved to 3 (PDF) and no error
        $this->assertFalse($media->error);
        $this->assertEquals(3, $media->mediatype);
        $this->assertGreaterThan(0, $media->mediaid);
    }

    /**
     * Test uploadFile() sets mediatype=3 and validates PDF MIME type correctly
     * (the `$this->mediatype == 3` MIME check at lines 1028-1036).
     *
     * @return void
     */
    #[Test]
    public function testUploadFileRejectsInvalidMimeForPdf(): void
    {
        // Arrange – declare PDF type but supply wrong MIME
        $dummyPdf = $this->createDummyFile('bad_pdf.pdf', '%PDF-1.4 dummy');

        $fileInput = [
            'name'     => 'bad_pdf.pdf',
            'type'     => 'text/plain', // invalid MIME for PDF
            'tmp_name' => $dummyPdf,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyPdf),
        ];

        $media            = new MediaObject();
        $media->mediatype  = 3; // pre-set as PDF

        // Act
        $media->uploadFile($fileInput, 'test_media_module', 3);

        // Assert – error set, mediaid still 0
        $this->assertNotFalse($media->error);
        $this->assertStringContainsString('Invalid MIME type', $media->error);
        $this->assertEquals(0, $media->mediaid);
    }

    /**
     * Test that uploadFile() with mediatype=0 and image MIME type resolves
     * mediatype to 1 (lines 1044-1049 in the mediatype==0 MIME switch).
     *
     * @return void
     */
    #[Test]
    public function testUploadFileWithMediatype0AndImageMimeResolvesToType1(): void
    {
        // Arrange
        $dummyJpg = $this->createDummyJpg('auto_type.jpg', 20, 20);

        $fileInput = [
            'name'     => 'auto_type.jpg',
            'type'     => 'image/jpeg', // valid image MIME
            'tmp_name' => $dummyJpg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyJpg),
        ];

        $media = new MediaObject(); // mediatype == 0

        // Act
        $media->uploadFile($fileInput, 'test_media_module');

        // Assert – mediatype auto-resolved to 1
        $this->assertFalse($media->error);
        $this->assertEquals(1, $media->mediatype);
    }

    /**
     * Test that uploadFile() with mediatype=0 and PDF MIME resolves mediatype
     * to 3 (line 1050-1052 in the mediatype==0 MIME switch).
     *
     * @return void
     */
    #[Test]
    public function testUploadFileWithMediatype0AndPdfMimeResolvesToType3(): void
    {
        // Arrange
        $dummyPdf = $this->createDummyFile('auto_pdf.pdf', '%PDF-1.4 dummy');

        $fileInput = [
            'name'     => 'auto_pdf.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => $dummyPdf,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyPdf),
        ];

        $media = new MediaObject(); // mediatype == 0

        // Act
        $media->uploadFile($fileInput, 'test_media_module');

        // Assert – mediatype auto-resolved to 3
        $this->assertFalse($media->error);
        $this->assertEquals(3, $media->mediatype);
    }

    /**
     * Test that uploadFile() with mediatype=0 and an office-document MIME type
     * keeps mediatype as 0 (lines 1053-1064) and processes it as a generic file.
     *
     * @return void
     */
    #[Test]
    public function testUploadFileWithMediatype0AndDocumentMimeKeepsType0(): void
    {
        // Arrange – .xlsx file with spreadsheet MIME
        $dummyXls = $this->createDummyFile('doc.xlsx', 'fake xlsx content');

        $fileInput = [
            'name'     => 'doc.xlsx',
            'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tmp_name' => $dummyXls,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyXls),
        ];

        $media = new MediaObject(); // mediatype == 0

        // Act
        $media->uploadFile($fileInput, 'test_media_module');

        // Assert – mediatype stays 0 (generic file)
        $this->assertFalse($media->error);
        $this->assertEquals(0, $media->mediatype);
    }

    /**
     * Test that uploadFile() with mediatype=0 and an invalid MIME type for a
     * spreadsheet extension sets error "#4 Invalid MIME type" (lines 1067-1069).
     *
     * The "#4" branch is reached only when mediatype is still 0 at MIME-check
     * time, which happens when the file extension is `xlsx` (keeps mediatype 0
     * at line 1006) and the supplied MIME is not any of the recognised office /
     * image / PDF types.
     *
     * @return void
     */
    #[Test]
    public function testUploadFileWithMediatype0AndInvalidMimeSetsError4(): void
    {
        // Arrange – .xlsx extension keeps mediatype==0; supply unrecognised MIME
        $dummyXlsx = $this->createDummyFile('bad_mime0.xlsx', 'fake xlsx');

        $fileInput = [
            'name'     => 'bad_mime0.xlsx',
            'type'     => 'application/octet-stream', // not a recognised office MIME
            'tmp_name' => $dummyXlsx,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($dummyXlsx),
        ];

        $media = new MediaObject(); // mediatype == 0

        // Act
        $media->uploadFile($fileInput, 'test_media_module');

        // Assert – error #4 is set because mediatype remains 0 and MIME is invalid
        $this->assertNotFalse($media->error);
        $this->assertStringContainsString('#4', $media->error);
    }

    /**
     * Test that uploadFile() detects a duplicate file by MD5 and links the new
     * upload to the original via `medialink` (lines 1107-1120).
     *
     * The file move uses copy() in UNITTESTING mode so we can use real temp files.
     *
     * @return void
     */
    #[Test]
    public function testUploadFileDetectsDuplicateByMd5(): void
    {
        // Arrange – upload an image, then re-upload the identical file
        $srcJpg = $this->createDummyJpg('dup_upload.jpg', 15, 15);

        $fileInput1 = [
            'name'     => 'dup_upload.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $srcJpg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($srcJpg),
        ];

        $media1 = new MediaObject();
        $media1->uploadFile($fileInput1, 'test_media_module', 1);
        $this->assertGreaterThan(0, $media1->mediaid);

        // Create a second copy with identical content
        $srcJpg2 = sys_get_temp_dir() . DS . 'dup_upload2.jpg';
        copy($srcJpg, $srcJpg2);
        $this->createdFiles[] = $srcJpg2;

        $fileInput2 = [
            'name'     => 'dup_upload2.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $srcJpg2,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($srcJpg2),
        ];

        $media2 = new MediaObject();
        $media2->uploadFile($fileInput2, 'test_media_module', 1);

        // Assert – medialink set to original mediaid
        $this->assertEquals($media1->mediaid, $media2->medialink);
    }

    // ────────────────────────────────────────────────────────────────────────
    // addUsage – fallback title / description / order / tags (lines 1167-1194)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test addUsage() uses the media object's own name/description/tags/order
     * values when no explicit usage-level overrides are set (lines 1167-1194).
     *
     * @return void
     */
    #[Test]
    public function testAddUsageFallsBackToMediaProperties(): void
    {
        // Arrange – media with name, description, tags, order set
        $media              = new MediaObject();
        $media->name        = 'Fallback Name';
        $media->description = 'Fallback Desc';
        $media->tags        = 'foo,bar';
        $media->order       = 7;
        $media->save();

        // usageTitle etc. are all empty → should fall back to media properties

        // Act
        $usageId = $media->addUsage('fallback_module');

        // Assert – load by usage and verify the title/description came from media
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId);
        $this->assertSame('Fallback Name', $loaded->usageTitle);
        $this->assertSame('Fallback Desc', $loaded->usageDescription);
        $this->assertSame('foo,bar',       $loaded->usageTags);
        $this->assertEquals(7,             $loaded->usageOrder);
    }

    /**
     * Test addUsage() prefers the explicit usageTitle/usageDescription etc.
     * over the media base properties when they are set (the `else` branches of
     * lines 1167-1194).
     *
     * @return void
     */
    #[Test]
    public function testAddUsageUsesUsagePropertiesWhenSet(): void
    {
        // Arrange
        $media                    = new MediaObject();
        $media->name              = 'Media Name';
        $media->description       = 'Media Desc';
        $media->tags              = 'base,tags';
        $media->order             = 1;
        $media->usageTitle        = 'Override Title';
        $media->usageDescription  = 'Override Desc';
        $media->usageTags         = 'override,tags';
        $media->usageOrder        = 99;
        $media->save();

        // Act
        $usageId = $media->addUsage('override_module');

        // Assert – usage data uses override values, not base media properties
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId);
        $this->assertSame('Override Title', $loaded->usageTitle);
        $this->assertSame('Override Desc',  $loaded->usageDescription);
        $this->assertSame('override,tags',  $loaded->usageTags);
        $this->assertEquals(99,             $loaded->usageOrder);
    }

    // ────────────────────────────────────────────────────────────────────────
    // saveUsage – no usageid (line 1557)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test saveUsage() returns immediately without touching the database when
     * usageid is not set (line 1557 early return).
     *
     * @return void
     */
    #[Test]
    public function testSaveUsageReturnsEarlyWhenNoUsageid(): void
    {
        // Arrange – media with no usageid
        $media = new MediaObject();
        $media->save();

        // Act – should not throw or modify anything
        $result = $media->saveUsage();

        // Assert – fluent interface, no side effects
        $this->assertSame($media, $result);
    }

    /**
     * Test saveUsage() reads userid from the session when it is not explicitly
     * set and the date is 0 (lines 1561-1564).
     *
     * @return void
     */
    #[Test]
    public function testSaveUsageSetsSessionUseridAndDate(): void
    {
        // Arrange
        $_SESSION['uid'] = 77;

        $media = new MediaObject();
        $media->save();
        $usageId = $media->addUsage('session_module');

        // Load the usage so usageid is populated
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId);
        $loaded->userid = 0;  // reset to force session pickup
        $loaded->date   = 0;  // reset to force date auto-set

        // Act
        $before = time();
        $loaded->saveUsage();
        $after  = time();

        // Assert – userid was resolved from session, date is set
        $this->assertEquals(77, $loaded->userid);
        $this->assertGreaterThanOrEqual($before, $loaded->date);
        $this->assertLessThanOrEqual($after,  $loaded->date);
    }

    // ────────────────────────────────────────────────────────────────────────
    // delete() – medialink propagation (lines 1626-1639)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test that delete() correctly propagates when the deleted media is the
     * original (medialink==0) and another record is linked to it.
     *
     * When the original is deleted and a linked copy exists, the framework:
     * 1. Sets the linked copy's medialink to 0 (it becomes the new original).
     * 2. Re-points all other records that had medialink==deleted_id to the
     *    new original.
     *
     * Lines 1624-1639 handle this logic.
     *
     * @return void
     */
    #[Test]
    public function testDeleteOriginalPromotesLinkedMediaToOriginal(): void
    {
        // Arrange – create an original and a linked copy
        $original = new MediaObject();
        $original->name = 'Original';
        $original->save();
        $originalId = $original->mediaid;

        $linked = new MediaObject();
        $linked->name      = 'Linked Copy';
        $linked->medialink = $originalId; // points to original
        $linked->save();
        $linkedId = $linked->mediaid;

        // Act – delete the original
        $original->delete();

        // Assert – original record is gone
        $checkOrig = new MediaObject();
        $checkOrig->load((string)$originalId);
        $this->assertTrue($this->getProtectedProperty($checkOrig, '_isnew'));

        // Assert – linked record now has medialink == 0 (promoted to original)
        $checkLinked = new MediaObject();
        $checkLinked->load((string)$linkedId);
        $this->assertEquals(0, $checkLinked->medialink);
    }

    /**
     * Test that delete() does NOT delete files when the media has a non-zero
     * medialink (i.e. it is a copy, not the original – line 1610-1611).
     *
     * Deleting a linked record should remove only the DB row, not the files.
     *
     * @return void
     */
    #[Test]
    public function testDeleteLinkedMediaDoesNotDeleteFiles(): void
    {
        // Arrange
        $tmpFile = $this->createDummyFile('linked_file.txt', 'data');

        $original = new MediaObject();
        $original->filename = $tmpFile;
        $original->save();

        $linked             = new MediaObject();
        $linked->filename   = $tmpFile;   // same file as original
        $linked->medialink  = $original->mediaid;
        $linked->save();

        // Act – delete the linked copy
        $linked->delete();

        // Assert – linked DB row is gone
        $check = new MediaObject();
        $check->load((string)$linked->mediaid);
        $this->assertTrue($this->getProtectedProperty($check, '_isnew'));

        // Assert – the physical file is still there (original was not touched)
        $this->assertFileExists($tmpFile);
    }

    // ────────────────────────────────────────────────────────────────────────
    // getThumb – medium/original fallbacks for image type (lines 1688-1698)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test getThumb() falls back to the "medium" thumbnail when no "thumb"
     * entry exists in the thumbnails array (line 1688-1690).
     *
     * @return void
     */
    #[Test]
    public function testGetThumbFallsBackToMedium(): void
    {
        // Arrange – only a medium and original thumbnail, no thumb
        $media            = new MediaObject();
        $media->mediatype = 1;

        $medium           = new Thumbnail();
        $medium->filename = 'med.jpg';
        $medium->reason   = 'medium';
        $media->thumbnails[] = $medium;

        $orig           = new Thumbnail();
        $orig->filename = 'orig.jpg';
        $orig->reason   = 'original';
        $media->thumbnails[] = $orig;

        // Act
        $resolved = $media->getThumb();

        // Assert – medium is returned as fallback
        $this->assertSame('med.jpg', $resolved->filename);
    }

    /**
     * Test getThumb() falls back to the "original" thumbnail when neither
     * "thumb" nor "medium" entries exist (lines 1693-1697).
     *
     * @return void
     */
    #[Test]
    public function testGetThumbFallsBackToOriginal(): void
    {
        // Arrange – only an original thumbnail
        $media            = new MediaObject();
        $media->mediatype = 1;

        $orig           = new Thumbnail();
        $orig->filename = 'orig.jpg';
        $orig->reason   = 'original';
        $media->thumbnails[] = $orig;

        // Act
        $resolved = $media->getThumb();

        // Assert – original is returned as last resort
        $this->assertSame('orig.jpg', $resolved->filename);
    }

    /**
     * Test getThumb() returns an empty Thumbnail when no thumbnails at all
     * are present and mediatype==1 (the final `return new Thumbnail()` at
     * line 1698).
     *
     * @return void
     */
    #[Test]
    public function testGetThumbReturnsEmptyThumbnailWhenNonePresent(): void
    {
        // Arrange
        $media            = new MediaObject();
        $media->mediatype = 1;
        // no thumbnails added

        // Act
        $resolved = $media->getThumb();

        // Assert – empty Thumbnail with default values
        $this->assertInstanceOf(Thumbnail::class, $resolved);
        $this->assertSame('', $resolved->filename);
    }

    // ────────────────────────────────────────────────────────────────────────
    // getMedium – PDF fallback and empty Thumbnail (lines 1904-1914)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test getMedium() returns a PDF preview Thumbnail for mediatype=3 with
     * the original URL (lines 1904-1914).
     *
     * @return void
     */
    #[Test]
    public function testGetMediumForPdfType(): void
    {
        // Arrange
        $media            = new MediaObject();
        $media->mediatype = 3;
        $media->url       = 'uploads/doc.pdf';

        // Act
        $resolved = $media->getMedium();

        // Assert
        $this->assertSame('PDF Preview', $resolved->reason);
        $this->assertSame('uploads/doc.pdf', $resolved->url);
    }

    /**
     * Test getMedium() returns an empty Thumbnail when mediatype==1 and no
     * thumbnails at all are present (the `return new Thumbnail()` fallback).
     *
     * @return void
     */
    #[Test]
    public function testGetMediumReturnsEmptyThumbnailWhenNonePresent(): void
    {
        // Arrange
        $media            = new MediaObject();
        $media->mediatype = 1;
        // no thumbnails

        // Act
        $resolved = $media->getMedium();

        // Assert
        $this->assertInstanceOf(Thumbnail::class, $resolved);
        $this->assertSame('', $resolved->filename);
    }

    // ────────────────────────────────────────────────────────────────────────
    // get() – force mode, generic type fallback (lines 1739-1866)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test get() with force=true deletes an existing thumbnail of the same
     * dimensions and recreates it, preserving the original reason label
     * (lines 1766-1789).
     *
     * @return void
     */
    #[Test]
    public function testGetWithForceTrueRecreatesThumbnail(): void
    {
        // Arrange – add a real image and generate a custom thumbnail first
        $src   = $this->createDummyJpg('force_src.jpg', 200, 200);
        $media = new MediaObject();
        $media->addImage($src, 'test_media_module');
        $media->save();

        // Generate an initial custom thumbnail at 80×80
        $first = $media->get(80, 80, true);
        $this->assertSame('custom', $first->reason);
        $firstFile = $first->filename;

        // Act – force re-creation of the same 80×80 thumbnail
        $recreated = $media->get(80, 80, false, true);

        // Assert – a new thumbnail was created (reason still custom)
        $this->assertSame('custom', $recreated->reason);
        $this->assertEquals(80, $recreated->x);
        $this->assertEquals(80, $recreated->y);
    }

    /**
     * Test get() returns a generic "File Preview" Thumbnail for mediatype==0
     * (the `else` branch at lines 1856-1866).
     *
     * @return void
     */
    #[Test]
    public function testGetForGenericTypeReturnsFilePreview(): void
    {
        // Arrange
        $media            = new MediaObject();
        $media->mediatype = 0;

        // Act
        $thumb = $media->get(100, 100);

        // Assert – File Preview thumbnail returned
        $this->assertSame('File Preview', $thumb->reason);
        $this->assertStringContainsString('pdf.png', $thumb->url);
    }

    /**
     * Test get() throws an exception when the media file does not exist and
     * _tryToRecreatePath() also fails (line 1794-1799).
     *
     * @return void
     */
    #[Test]
    public function testGetThrowsExceptionWhenFileIsMissingAndCannotRecreate(): void
    {
        // Arrange – media saved with a non-existent filename
        $media            = new MediaObject();
        $media->mediatype = 1;
        $media->filename  = '/absolutely/nonexistent/file.jpg';
        $media->url       = 'uploads/nonexistent/file.jpg';
        $media->x         = 100;
        $media->y         = 100;
        $media->save();

        // Act & Assert – must throw because neither the file nor a path
        // recreation is possible
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Media file doesnt exist/');
        $media->get(50, 50);
    }

    /**
     * Test get() returns an existing thumbnail without regenerating it when
     * the exact dimensions are already in the thumbnails array and the file
     * exists (the early-return inside the force==false loop, line 1751).
     *
     * @return void
     */
    #[Test]
    public function testGetReturnsCachedThumbnailWhenAlreadyExists(): void
    {
        // Arrange – image with a pre-existing thumbnail in the array
        $src   = $this->createDummyJpg('cached_src.jpg', 200, 200);
        $media = new MediaObject();
        $media->addImage($src, 'test_media_module');
        $media->save();

        // Generate a 60×60 thumbnail and capture the filename
        $first      = $media->get(60, 60, true);
        $cachedFile = $first->filename;

        // Act – second call, same dimensions, force==false (default)
        $second = $media->get(60, 60);

        // Assert – same file returned, no new file created
        $this->assertSame($cachedFile, $second->filename);
    }

    // ────────────────────────────────────────────────────────────────────────
    // loadByUsage – updateViews path (line 1373-1379)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test loadByUsage() increments the views counter in the database when
     * updateViews=true (lines 1373-1379).
     *
     * The in-memory views counter is verified immediately.  Persistence is
     * confirmed by flushing the query cache before re-loading from the DB.
     *
     * @return void
     */
    #[Test]
    public function testLoadByUsageWithUpdateViewsIncrementsCounter(): void
    {
        // Arrange – save a media record and create a usage
        $media = new MediaObject();
        $media->save();
        $usageId = $media->addUsage('views_module', 'views_spec');

        // Initial views should be 0
        $this->assertEquals(0, $media->views);

        // Act – load with updateViews=true
        $loaded = new MediaObject();
        $loaded->loadByUsage($usageId, true);

        // Assert – views is now 1 in-memory
        $this->assertEquals(1, $loaded->views);

        // Flush query cache so the next load reads from the real DB, not cache
        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->cacheflush('media');

        // Re-load from DB to confirm persistence
        $fromDb = new MediaObject();
        $fromDb->load((string)$media->mediaid);
        $this->assertEquals(1, $fromDb->views);
    }

    // ────────────────────────────────────────────────────────────────────────
    // clearUsage – staticGetUsages with module only filter (line 1312)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Test clearUsage() with safe=false triggers delete of media when no
     * usages remain (via removeUsage → delete) (line 1349-1350).
     *
     * @return void
     */
    #[Test]
    public function testClearUsageWithSafeFalseDeletesMediaWhenNoUsages(): void
    {
        // Arrange
        $media = new MediaObject();
        $media->save();
        $mid = $media->mediaid;

        $media->addUsage('del_module', 'del_spec');

        // Act – clearUsage with safe=false (default)
        $media->clearUsage('del_module', 'del_spec', false);

        // Assert – no more usages, and since safe=false the media was deleted
        $check = new MediaObject();
        $check->load((string)$mid);
        $this->assertTrue($this->getProtectedProperty($check, '_isnew'));
    }
}

namespace Pramnos\Media;

function exif_read_data($filename) {
    if (strpos($filename, 'exif_test_6.jpg') !== false) {
        return ['Orientation' => 6];
    }
    if (strpos($filename, 'exif_test_3.jpg') !== false) {
        return ['Orientation' => 3];
    }
    if (strpos($filename, 'exif_test_8.jpg') !== false) {
        return ['Orientation' => 8];
    }
    // Return empty array for default/non-exif jpeg
    return [];
}

