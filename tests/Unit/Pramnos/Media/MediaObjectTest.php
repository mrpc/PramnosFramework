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

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Ensure user tables exist
        User::setupDb();

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
}
