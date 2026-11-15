<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Files;
use Pramnos\Llm\Analyst\Tools\ReadStorageDocumentTool;

/**
 * `read_storage_document` (FW-505) reads a conversation file's words back for the model, on each
 * backend — the harness spec's tool, left unbuilt before for lack of a generic permission model,
 * which {@see Files::pathFor()} already is: the same rights as reading the conversation itself.
 */
#[CoversClass(ReadStorageDocumentTool::class)]
#[CoversClass(Files::class)]
class ReadStorageDocumentTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The tree this test keeps files in. */
    private string $tree = '';

    protected function tearDown(): void
    {
        if ($this->tree !== '' && is_dir($this->tree)) {
            exec('rm -rf ' . escapeshellarg($this->tree));
        }
        $this->probeTearDown();
    }

    /** A temporary file with these bytes. */
    private function upload(string $bytes): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pfchat');
        file_put_contents($tmp, $bytes);

        return $tmp;
    }

    /** A small, real PNG — `finfo` reads the bytes, not a made-up header. */
    private static function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A reader of the conversation gets the file's words, exactly as `DocumentText` reads them —
     * another reader does not, and nor does somebody who is not a reader at all; a picture, which
     * `DocumentText` does not read for its words, says so rather than nothing.
     */
    #[DataProvider('databases')]
    public function testReadsAFileItsReaderMayRead(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $secret = Conversations::create('private', 'finance', [$maria], $eleni);
        $csv    = Files::store($secret, $eleni, $this->upload("name,total\r\nAthens,10"), 'figures.csv');
        $image  = Files::store($secret, $eleni, $this->upload(self::png()), 'chart.png');

        // Act
        $byEleni  = (new ReadStorageDocumentTool(0, $this->user('eleni')))->execute(['file' => $csv]);
        $byMaria  = (new ReadStorageDocumentTool(0, $this->user('maria')))->execute(['file' => $csv]);
        $byNikos  = (new ReadStorageDocumentTool(0, $this->user('nikos')))->execute(['file' => $csv]);
        $byAdmin  = (new ReadStorageDocumentTool($eleni))->execute(['file' => $csv]);
        $picture  = (new ReadStorageDocumentTool(0, $this->user('eleni')))->execute(['file' => $image]);
        $noSuch   = (new ReadStorageDocumentTool(0, $this->user('eleni')))->execute(['file' => 424242]);
        $nobody   = (new ReadStorageDocumentTool())->execute(['file' => $csv]);

        // Assert
        $this->assertSame("name,total\nAthens,10", $byEleni);
        $this->assertSame($byEleni, $byMaria, 'another reader of the same conversation reads it the same');
        $this->assertSame('Error: there is no such file you can read.', $byNikos, 'not a reader of its conversation');
        $this->assertSame($byEleni, $byAdmin, 'adminId and "as" reach the same person');
        $this->assertStringStartsWith('Error: chart.png cannot be read as text', $picture);
        $this->assertSame('Error: there is no such file you can read.', $noSuch);
        $this->assertSame('Error: the conversations are not open to you.', $nobody);
    }

    /** The tool's own name. */
    public function testName(): void
    {
        $this->assertSame('read_storage_document', (new ReadStorageDocumentTool())->name());
    }
}
