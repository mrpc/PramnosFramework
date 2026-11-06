<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\MediaObject;

/**
 * The extensions an upload may have, per module, from app.php: `'media' => ['extensions' =>
 * ['<module>' => [...], '*' => [...]]]`, beside the framework's own list.
 *
 * Why it matters: the list was written into `uploadFile()`, so a module that takes a log file or
 * a zip — a support ticket's attachments — could not use MediaObject at all and grew a storage
 * service of its own. What it must not do is open the door to a file that runs or renders as a
 * page when served: those stay refused whatever app.php says.
 *
 * "Taken" is asserted as the file reaching the module's directory: the checks are past it then.
 * What follows (the duplicate lookup in `media`) needs a database this unit test does not have,
 * so its failure is caught and not asserted on.
 */
#[CoversClass(MediaObject::class)]
class UploadModuleExtensionsTest extends TestCase
{
    /** The module the application configures. */
    private const MODULE = 'upload_ext_probe';

    /** A module it does not. */
    private const OTHER = 'upload_ext_other';

    private string $tmp = '';

    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pramnos-upload-ext-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
        $this->info = \Pramnos\Application\Application::getInstance()->applicationInfo;
        $_SESSION   = [];
    }

    protected function tearDown(): void
    {
        \Pramnos\Application\Application::getInstance()->applicationInfo = $this->info;
        foreach ([$this->tmp, ROOT . '/www/uploads/' . self::MODULE, ROOT . '/www/uploads/' . self::OTHER] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($dir);
        }
        $_SESSION = [];
    }

    /** app.php's media extensions. */
    private function configure(mixed $extensions): void
    {
        \Pramnos\Application\Application::getInstance()->applicationInfo['media'] = ['extensions' => $extensions];
    }

    /**
     * Uploads a file of that name and content to a module: the media object, and whether the
     * file reached the module's directory.
     *
     * @return array{0: MediaObject, 1: bool}
     */
    private function upload(string $name, string $type, string $module, int $mediatype = 0): array
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . bin2hex(random_bytes(4));
        file_put_contents($path, "a line of a log\nanother line\n");
        $media = new MediaObject();
        try {
            $media->uploadFile(['name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => 0, 'size' => '28'], $module, $mediatype);
        } catch (\Throwable) {
            // The duplicate lookup after the file is stored: past what this test is about.
        }
        $stored = glob(ROOT . '/www/uploads/' . $module . '/*/*/*/*') ?: [];

        return [$media, $stored !== []];
    }

    /**
     * A module's own extensions are taken — written any way (`.LOG`), with the browser's
     * Content-Type whatever it is — and the file is a document, not a picture.
     */
    public function testAModulesOwnExtensionIsTaken(): void
    {
        // Arrange
        $this->configure([self::MODULE => ['txt', '.LOG']]);

        // Act
        [$text, $textStored] = $this->upload('notes.txt', 'text/plain', self::MODULE);
        [$log, $logStored]   = $this->upload('server.log', 'application/octet-stream', self::MODULE);

        // Assert
        $this->assertSame('', (string) $text->error);
        $this->assertTrue($textStored, 'the text file reached the module\'s directory');
        $this->assertSame(0, (int) $text->mediatype, 'a document, not a picture');
        $this->assertSame('', (string) $log->error, 'written .LOG in app.php, taken as log');
        $this->assertTrue($logStored);
    }

    /** Another module does not get them; the list under `'*'` is every module's. */
    public function testOnlyThatModuleOrEveryModuleWithAStar(): void
    {
        // Arrange
        $this->configure([self::MODULE => ['txt']]);

        // Act
        [$elsewhere] = $this->upload('notes.txt', 'text/plain', self::OTHER);
        $this->configure(['*' => ['txt']]);
        [$everywhere, $stored] = $this->upload('notes.txt', 'text/plain', self::OTHER);

        // Assert
        $this->assertSame('#1 Invalid File Type: txt', $elsewhere->error);
        $this->assertSame('', (string) $everywhere->error);
        $this->assertTrue($stored);
    }

    /**
     * A file that would run or render as a page when served is refused even when app.php lists
     * it, and a picture upload takes only pictures whatever the module allows.
     */
    public function testWhatWouldRunIsRefusedAndPicturesStayPictures(): void
    {
        // Arrange
        $this->configure([self::MODULE => ['php', 'html', 'txt'], '*' => 'not a list']);

        // Act
        [$php]     = $this->upload('shell.php', 'text/plain', self::MODULE);
        [$html]    = $this->upload('page.html', 'text/html', self::MODULE);
        [$picture] = $this->upload('notes.txt', 'text/plain', self::MODULE, 1);

        // Assert
        $this->assertSame('#1 Invalid File Type: php', $php->error);
        $this->assertSame('#1 Invalid File Type: html', $html->error);
        $this->assertSame('#1 Invalid File Type: txt', $picture->error, 'a picture upload is not widened by the module');
    }

    /**
     * The framework's own types keep their checks, configured or not: a PDF is still a PDF
     * (media type 3) and its Content-Type is still checked; app.php that is not a list adds nothing.
     */
    public function testTheFrameworksOwnTypesAreUnchanged(): void
    {
        // Arrange
        $this->configure('not a list');

        // Act
        [$pdf] = $this->upload('report.pdf', 'text/plain', self::MODULE);
        [$txt] = $this->upload('notes.txt', 'text/plain', self::MODULE);

        // Assert
        $this->assertSame('#3 Invalid MIME type: text/plain', $pdf->error, 'a PDF\'s Content-Type is still checked');
        $this->assertSame('#1 Invalid File Type: txt', $txt->error);
    }
}
