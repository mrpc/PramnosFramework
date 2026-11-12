<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\Messages;

/**
 * Files sent in conversations, on each backend: kept as files on disk under their own tree by a
 * relative path; only what they are, never a program, and never larger than the setting; an image
 * with a thumbnail; read only by a reader of their conversation; removed to free space with their
 * message saying so; their use of space summed.
 */
#[CoversClass(Files::class)]
#[CoversClass(Messages::class)]
class FilesTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The tree this test keeps files in. */
    private string $tree = '';

    /** Remove the tree, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        if ($this->tree !== '' && is_dir($this->tree)) {
            exec('rm -rf ' . escapeshellarg($this->tree));
        }
        Settings::setSetting(Files::SETTING, 0, false);
        $this->probeTearDown();
    }

    /** A temporary file with these bytes. */
    private function upload(string $bytes): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pfchat');
        file_put_contents($tmp, $bytes);

        return $tmp;
    }

    /** A small PNG. */
    private static function png(): string
    {
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** An image of this size: a PNG with a transparent background, or a JPEG. */
    private static function image(int $width, int $height, string $kind = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 200, 30, 30));
        ob_start();
        $kind === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * A thumbnail keeps its image's proportions inside THUMB×THUMB — a strip of text stays a strip,
     * a tall photo stays tall — and is of the type its name says: a PNG for a PNG, transparency
     * kept, a JPEG for a JPEG. An image that fits already has none: it is shown itself. The command
     * makes them all again, so a square thumbnail made before goes, and a small image's goes too.
     */
    #[DataProvider('databases')]
    public function testAThumbnailKeepsItsImagesProportionsAndType(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree];
        $eleni   = $this->ids['eleni'];
        $general = Conversations::create('channel', 'general', [], $eleni);

        // Act
        $strip = Files::store($general, $eleni, $this->upload(self::image(1380, 164)), 'λωρίδα.png');
        $tall  = Files::store($general, $eleni, $this->upload(self::image(300, 900, 'jpeg')), 'ψηλή.jpg');
        $small = Files::store($general, $eleni, $this->upload(self::image(345, 41)), 'μικρή.png');
        $sizes = fn (int $id): array => array_slice((array) getimagesize($this->tree . '/' . Files::get($id)['thumbPath']), 0, 3);
        $thumbs = [$sizes($strip), $sizes($tall)];
        $corner = imagecolorat((object) imagecreatefrompng($this->tree . '/' . Files::get($strip)['thumbPath']), 399, 47) >> 24;
        // A square thumbnail as one was made before, for the small one.
        $old = dirname(Files::get($small)['path']) . '/' . $small . '-μικρή.thumb.jpg';
        file_put_contents($this->tree . '/' . $old, self::image(41, 41));
        $this->db->queryBuilder()->table('chat.conversation_files')->where('fileid', $small)->update(['thumbpath' => $old]);
        $console = new \Pramnos\Console\Application();
        $tester  = new \Symfony\Component\Console\Tester\CommandTester($console->find('conversations:thumbnails'));
        $status  = $tester->execute([]);

        // Assert
        $this->assertSame([[400, 48, IMAGETYPE_PNG], [133, 399, IMAGETYPE_JPEG]], $thumbs, 'fitted, each of its own type');
        $this->assertStringEndsWith('.thumb.png', Files::get($strip)['thumbPath'], 'named for what it is');
        $this->assertStringEndsWith('.thumb.jpg', Files::get($tall)['thumbPath']);
        $this->assertSame(127, $corner, 'the transparent background stays transparent');
        $this->assertSame(0, $status);
        $this->assertSame('3 images, 2 with a thumbnail; the rest are shown themselves.', trim($tester->getDisplay()));
        $this->assertNull(Files::get($small)['thumbPath'], 'small enough: shown itself');
        $this->assertFileDoesNotExist($this->tree . '/' . $old, 'the square one is gone');
        $this->assertSame([[400, 48, IMAGETYPE_PNG], [133, 399, IMAGETYPE_JPEG]], [$sizes($strip), $sizes($tall)], 'made again the same');
    }

    /**
     * A file is kept under `YYYY/MM/<id>-<name>`, its type by what it is; a program is refused,
     * whatever it is called, and so is one larger than the setting; a name loses its path; the
     * message it is sent with links it; an image has a thumbnail.
     */
    #[DataProvider('databases')]
    public function testAFileIsKeptOnDiskByWhatItIs(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree, 'max_file_mb' => 2];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);

        // Act
        $picture = Files::store($general, $eleni, $this->upload(self::png()), '../../etc/εικόνα.png');
        $text    = Files::store($general, $eleni, $this->upload("γραμμή 1\nγραμμή 2\n"), 'σημειώσεις.txt');
        $refusals = [];
        foreach ([
            fn () => Files::store($general, $eleni, $this->upload("MZ\x90\x00" . str_repeat("\x00", 200)), 'invoice.pdf'),
            fn () => Files::store($general, $eleni, $this->upload('<?php echo 1;'), 'run.php'),
            fn () => Files::store($general, $eleni, $this->upload(str_repeat('a', 3 * 1024 * 1024)), 'big.txt'),
            fn () => Files::store($general, $eleni, '/nonexistent', 'gone.txt'),
            fn () => Files::store($general, $this->ids['nikos'], $this->upload('x'), 'x.txt'),
        ] as $refused) {
            try {
                $refused();
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        $message = Messages::post($general, $eleni, '', ['files' => [$picture, $text]]);
        $listed  = Messages::list($general)[0]['files'];

        // Assert
        $file = Files::get($picture);
        $this->assertSame(date('Y/m') . '/' . $picture . '-εικόνα.png', $file['path'], 'relative, under year and month, the name without its path');
        $this->assertFileExists($this->tree . '/' . $file['path']);
        $this->assertSame('image/png', $file['mime']);
        $this->assertNotNull($file['thumbPath']);
        $this->assertFileExists($this->tree . '/' . $file['thumbPath'], 'an image has a thumbnail');
        $this->assertSame('text/plain', Files::get($text)['mime']);
        $this->assertSame([
            'Files of this kind (.pdf) are not sent here: images, video, PDF, Office documents, CSV, text and zip are.',
            'Files of this kind (.php) are not sent here: images, video, PDF, Office documents, CSV, text and zip are.',
            'A file is at most 2 MB.',
            'The file did not arrive.',
            'Only its members write in a conversation.',
        ], $refusals);
        $this->assertSame([$picture, $text], array_column($listed, 'id'), 'linked to the message they came with');
        $this->assertSame([true, false], array_column($listed, 'thumbnail'));
        $this->assertSame($message, Files::get($picture)['messageId']);
        Settings::setSetting(Files::SETTING, 1, false);
        $this->assertSame(1, Files::maxMegabytes(), 'the administrator\'s setting first');
    }

    /**
     * A video is kept like any file, without a thumbnail, under a limit of its own (FW-324): an mp4
     * larger than the files' limit is kept while it is under the videos', and one over the videos'
     * is refused saying so.
     */
    #[DataProvider('databases')]
    public function testAVideoHasALimitOfItsOwn(string $type, string $host, int $port, string $user): void
    {
        // Arrange — files up to 2 MB, videos up to 4
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree, 'max_file_mb' => 2, 'max_video_mb' => 4];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $mp4 = static fn (int $bytes): string => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", max(0, $bytes - 24));

        // Act
        $kept = Files::store($general, $eleni, $this->upload($mp4(3 * 1024 * 1024)), 'βίντεο.mp4');
        try {
            Files::store($general, $eleni, $this->upload($mp4(5 * 1024 * 1024)), 'μεγάλο.mp4');
            $refused = '';
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }

        // Assert
        $file = Files::get($kept);
        $this->assertSame('video/mp4', $file['mime']);
        $this->assertNull($file['thumbPath'], 'no thumbnail for a video');
        $this->assertSame('A video is at most 4 MB.', $refused);
        $this->assertSame(4, Files::maxVideoMegabytes());
        Settings::setSetting(Files::VIDEO_SETTING, 7, false);
        $this->assertSame(7, Files::maxVideoMegabytes(), 'the administrator\'s setting first');
        Settings::setSetting(Files::VIDEO_SETTING, 0, false);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $this->assertSame(Files::DEFAULT_MAX_VIDEO_MB, Files::maxVideoMegabytes(), 'and 100 when nothing says');
    }

    /**
     * A tree that cannot be written refuses the file loudly and leaves no row behind — a row without
     * its file would be a message pointing at nothing.
     */
    #[DataProvider('databases')]
    public function testATreeThatCannotBeWrittenLeavesNoRow(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        file_put_contents($this->tree, 'a file where the tree should be');
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree];
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);

        // Act
        try {
            Files::store($general, $this->ids['eleni'], $this->upload('κείμενο'), 'a.txt');
            $error = null;
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        @unlink($this->tree);

        // Assert
        $this->assertStringStartsWith('The file could not be kept under the conversations\' files directory', (string) $error);
        $this->assertSame([], $this->db->queryBuilder()->table('chat.conversation_files')->getAll(), 'no row without its file');
    }

    /**
     * A file is read by whoever reads its conversation and nobody else, sent with its own type and
     * name; removing it frees its space and leaves its message saying when; pruning removes the old
     * ones and says how much it freed; the use of space is summed.
     */
    #[DataProvider('databases')]
    public function testReadingRemovingAndTheSpace(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $secret = Conversations::create('private', 'finance', [$maria], $eleni);
        $old    = Files::store($secret, $eleni, $this->upload('παλιό'), 'old.txt');
        $new    = Files::store($secret, $maria, $this->upload('καινούριο αρχείο'), 'new.txt');
        Messages::post($secret, $eleni, 'δες', ['files' => [$old]]);
        $this->db->queryBuilder()->table('chat.conversation_files')->where('fileid', $old)->update(['createdat' => time() - 400 * 86400]);

        // Act
        $forMaria = Files::pathFor($old, $this->user('maria'));
        $forNikos = Files::pathFor($old, $this->user('nikos'));
        ob_start();
        $sent = Files::send($new, $this->user('eleni'));
        $body = (string) ob_get_clean();
        $usage  = Files::usage();
        $report = Files::prune(365);
        $again  = Files::prune(365);
        $after  = Messages::list($secret)[0]['files'][0];

        // Assert
        $this->assertNotNull($forMaria);
        $this->assertNull($forNikos, 'not a reader of its conversation');
        $this->assertTrue($sent);
        $this->assertSame('καινούριο αρχείο', $body);
        $this->assertFalse(Files::send(424242, $this->user('eleni')));
        $this->assertSame(['files' => 2, 'bytes' => strlen('παλιό') + strlen('καινούριο αρχείο')], $usage['total']);
        $this->assertSame([$new, $old], array_column($usage['largest'], 'id'));
        $this->assertSame([$old, $new], array_column($usage['oldest'], 'id'));
        $this->assertCount(2, $usage['byConversation'], 'two months');
        $this->assertSame(['files' => 1, 'bytes' => strlen('παλιό')], $report);
        $this->assertSame(['files' => 0, 'bytes' => 0], $again);
        $this->assertNotNull($after['removedAt'], 'its message says it was removed');
        $this->assertFileDoesNotExist($this->tree . '/' . Files::get($old)['path']);
        $this->assertNull(Files::pathFor($old, $this->user('eleni')), 'removed: nothing to send');
        $this->assertSame(0, Files::remove($old), 'removed once');
        $this->assertSame(['files' => 1, 'bytes' => strlen('καινούριο αρχείο')], Files::prune(0, $secret), 'one conversation\'s, every one');
    }

    /**
     * A CSV is `text/plain` under finfo (FW-494, from a production 422 that refused one): both
     * `text/plain` and `text/csv` must carry the `csv` extension, or every CSV is refused. A refusal
     * names the extension and writes one warning line — user, conversation, mime, extension, size —
     * so a future refusal can be told apart from a bug without reproducing it.
     */
    #[DataProvider('databases')]
    public function testACsvIsAcceptedAndARefusalIsNamedAndLogged(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree];
        $eleni   = $this->ids['eleni'];
        $general = Conversations::create('channel', 'general', [], $eleni);
        $logFile = \Pramnos\Logs\Logger::logDirectory() . '/pramnosframework.log';
        @unlink($logFile);

        // Act — a real finfo-detected CSV, as text/plain on this system
        $csv = Files::store($general, $eleni, $this->upload("name,age\nLela,30\n"), 'μισθοδοσία.csv');
        try {
            Files::store($general, $eleni, $this->upload('whatever'), 'σημείωση.rtx');
            $refused = '';
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }
        $logged = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        // Assert
        $this->assertSame('text/plain', Files::get($csv)['mime'], 'what finfo actually reports for a CSV');
        $this->assertStringEndsWith('.csv', Files::get($csv)['path']);
        $this->assertStringContainsString('(.rtx)', $refused, 'the refusal names the extension');
        $this->assertStringContainsString('not sent here', $refused);
        // One warning line with exactly what is needed to tell a refusal apart from a bug.
        $this->assertMatchesRegularExpression('/"userid":' . $eleni . '\b/', $logged, 'the user is in the log line');
        $this->assertMatchesRegularExpression('/"conversationid":' . $general . '\b/', $logged);
        $this->assertStringContainsString('"extension":"rtx"', $logged);
        $this->assertStringContainsString('"mime":"text/plain"', $logged);
        $this->assertStringContainsString('"size":8', $logged);
    }

    /**
     * An application adds its own mime/extension pairs on top of {@see Files::TYPES} — e.g. a kind
     * the framework does not know, or a second extension for a mime it already allows — without
     * forking the list.
     */
    #[DataProvider('databases')]
    public function testAnApplicationExtendsTheAllowedTypes(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree,
            'file_types' => ['text/plain' => ['weird'], 'application/x-custom' => ['xyz']]];
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);

        // Act
        $extended   = Files::store($general, $this->ids['eleni'], $this->upload('data'), 'σημείωση.weird');
        $stillCsv   = Files::types()['text/plain'];

        // Assert
        $this->assertSame('text/plain', Files::get($extended)['mime'], 'the application\'s own extension on an existing mime');
        $this->assertContains('csv', $stillCsv, 'the framework\'s own extensions stay, merged rather than replaced');
        $this->assertContains('weird', $stillCsv);
        $this->assertSame(['xyz'], Files::types()['application/x-custom'], 'and a mime the framework does not know at all');
        $this->assertContains('weird', Files::extensions(), 'extensions() carries the application\'s own too');
        $this->assertContains('xyz', Files::extensions());
        $this->assertContains('pdf', Files::extensions(), 'and the framework\'s');
    }
}
