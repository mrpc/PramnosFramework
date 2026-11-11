<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Photos;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * Photos with the companion (FW-445), on MySQL and PostgreSQL.
 *
 * What has to hold: a photo is cleaned on the way in — its metadata, the place among it, left
 * behind — and kept outside the web root with a thumbnail; read back only through an address
 * signed for it, which a wrong or old signature does not open; listed by day and conversation, the
 * persona's own; told to its conversation as a line the model reads, never as pixels; changed,
 * deleted with its files, and limited in size and number.
 */
#[CoversClass(Photos::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class PhotosTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed');
        }
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['photos' => ['disk' => 'local'], 'memory' => ['learn' => false],
            'personas' => ['naya' => ['name' => 'Naya', 'default' => true], 'tesla' => ['name' => 'Tesla']]]]);
        $this->root = sys_get_temp_dir() . '/pf-photos-' . bin2hex(random_bytes(4));
        \Pramnos\Storage\Storage::init(['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $this->root]]]);
    }

    protected function tearDown(): void
    {
        if ($this->person > 0) {
            foreach (['assist.chat_artifacts', 'assist.chat_conversations'] as $table) {
                $this->db->queryBuilder()->table($table)->where('userid', $this->person)->delete();
            }
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        \Pramnos\Storage\Storage::reset();
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
        unset($_SERVER['REQUEST_METHOD']);
        $_GET = [];
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A request through the routes. */
    private function call(string $method, string $uri, array $input = []): mixed
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        ApiFixture::$input = $input;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);

        return $router->dispatch(\Pramnos\Http\Request::create($uri, $method));
    }

    /** A JPEG of a size, with an APP1 (EXIF) segment carrying a marker a cleaned photo must not keep. */
    private static function jpeg(int $width, int $height, string $marker): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $plain   = (string) ob_get_clean();
        $payload = "Exif\0\0" . $marker;

        // After the start of image: an APP1 segment, as a camera writes its metadata.
        return substr($plain, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($plain, 2);
    }

    /**
     * Kept, cleaned, read by a signed address alone; listed, told, changed, deleted; the limits;
     * each persona's own.
     */
    #[DataProvider('databases')]
    public function testAPhotoIsKeptCleanAndReadOnlyByItsAddress(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username = 'photo' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->person      = (int) $user->userid;
        ApiFixture::$scope = new Scope($this->person);
        ApiFixture::$stop  = null;
        $this->answer([[200, self::says('Γεια!')]]);
        $conversation = (new Conversations(new BaseAssistant()))->as(new Scope($this->person))->ask(null, 'Γεια');
        $id           = (int) $conversation['conversation_id'];
        $picture      = self::jpeg(3000, 1500, 'GPSLatitude=37.9838-SECRET');

        // Act
        $kept    = $this->call('POST', '/chat/photos', ['data' => base64_encode($picture), 'name' => 'beach.jpg', 'caption' => 'Παραλία', 'conversation_id' => $id, 'day' => '2026-11-08']);
        $photo   = $kept['data'];
        parse_str((string) parse_url($photo['url'], PHP_URL_QUERY), $query);
        $_GET    = $query;
        $full    = $this->call('GET', '/chat/' . strtok($photo['url'], '?'));
        parse_str((string) parse_url($photo['thumb_url'], PHP_URL_QUERY), $thumbQuery);
        $_GET    = $thumbQuery;
        $thumb   = $this->call('GET', '/chat/' . strtok($photo['thumb_url'], '?'));
        $_GET    = ['size' => 'full', 'e' => $query['e'], 's' => str_repeat('0', 64)];
        $forged  = $this->call('GET', '/chat/' . strtok($photo['url'], '?'));
        $_GET    = [];
        $byDay   = $this->call('GET', '/chat/photos?day=2026-11-08');
        $history = (new Conversations(new BaseAssistant()))->as(new Scope($this->person))->find($id)['messages'];
        $tool    = (new Photos(new Scope($this->person)))->tools()['list_photos']['run'](['day' => '2026-11-08']);
        $changed = $this->call('PATCH', '/chat/photos/' . $photo['artifact_id'], ['caption' => 'Η παραλία μας']);
        $wrongDay = $this->call('PATCH', '/chat/photos/' . $photo['artifact_id'], ['day' => 'yesterday']);
        $notAPicture = $this->call('POST', '/chat/photos', ['data' => base64_encode('not a picture')]);
        ApiFixture::$scope = new Scope($this->person, null, Scope::CHAT, 'tesla');
        $hers    = $this->call('GET', '/chat/photos');
        ApiFixture::$scope = new Scope($this->person);
        $this->configure(['chat' => ['photos' => ['disk' => 'local', 'max' => 1]]]);
        $tooMany = $this->call('POST', '/chat/photos', ['data' => base64_encode(self::jpeg(10, 10, 'x'))]);
        $this->configure(['chat' => ['photos' => ['disk' => 'local', 'bytes' => 10]]]);
        $tooBig  = $this->call('POST', '/chat/photos', ['data' => base64_encode(self::jpeg(10, 10, 'x'))]);
        $files   = glob($this->root . '/chat-photos/' . $this->person . '/*') ?: [];
        $onDisk  = implode('', array_map(static fn (string $f): string => (string) file_get_contents($f), $files));
        $deleted = $this->call('DELETE', '/chat/photos/' . $photo['artifact_id']);
        $after   = glob($this->root . '/chat-photos/' . $this->person . '/*') ?: [];
        $again   = $this->call('DELETE', '/chat/photos/' . $photo['artifact_id']);
        $this->configure([]);
        $off     = $this->call('GET', '/chat/photos');

        // Assert
        $this->assertSame(201, $kept['status']);
        $this->assertSame([2560, 1280], [$photo['width'], $photo['height']], 'at most 2560 on its longest side');
        $this->assertSame('Παραλία', $photo['caption']);
        $bytes = (string) $full->getBody();
        $this->assertStringStartsWith("\xFF\xD8", $bytes, 'a JPEG');
        $this->assertStringNotContainsString('SECRET', $bytes, 'its metadata, the place among it, left behind');
        $this->assertStringNotContainsString('SECRET', $onDisk, 'on disk too');
        $this->assertSame('image/jpeg', $full->getHeaderLine('Content-Type'));
        $this->assertSame(480, imagesx(imagecreatefromstring((string) $thumb->getBody())), 'the thumbnail');
        $this->assertSame(404, $forged['status'], 'a forged signature reads nothing');
        $this->assertNull(Photos::signed((int) $photo['artifact_id'], 'full', time() - 1, (string) $query['s']), 'nor an old one');
        $this->assertSame([$photo['artifact_id']], array_column($byDay['data']['photos'], 'artifact_id'));
        $this->assertSame('[photo: Παραλία]', end($history)['content'], 'the model reads a line, never the pixels');
        $this->assertStringContainsString('Παραλία', $tool);
        $this->assertSame('Η παραλία μας', $changed['data']['caption']);
        $this->assertSame(422, $wrongDay['status']);
        $this->assertSame(422, $notAPicture['status']);
        $this->assertSame([], $hers['data']['photos'], 'each persona\'s own');
        $this->assertSame(422, $tooMany['status'], 'no more than the application allows');
        $this->assertSame(422, $tooBig['status']);
        $this->assertCount(2, $files, 'the photo and its thumbnail, outside the web root');
        $this->assertSame(200, $deleted['status']);
        $this->assertSame([], $after, 'its files gone with it');
        $this->assertSame(404, $again['status']);
        $this->assertSame(501, $off['status'], 'nothing where the application keeps no photos');
    }

    /** An account's erasure takes its photos' files, not only their rows. */
    #[DataProvider('databases')]
    public function testErasedWithTheAccount(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username = 'photo' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->person = (int) $user->userid;
        (new Photos(new Scope($this->person)))->add(self::jpeg(20, 20, 'x'));
        (new Photos(new Scope($this->person, null, Scope::CHAT, 'tesla')))->add(self::jpeg(20, 20, 'x'));

        // Act
        $gone = Photos::eraseFiles($this->person);

        // Assert
        $this->assertSame(2, $gone);
        $this->assertSame([], glob($this->root . '/chat-photos/' . $this->person . '/*') ?: [], 'every persona\'s');
        $this->assertTrue(\Pramnos\Security\PersonalDataRegistry::isPersonalTable('assist.chat_artifacts'));
    }

    /**
     * With no disk named, photos are kept in a place of their own outside the web root — never the
     * default disk, which with no storage configured is `www/`, where a photo would be anybody's
     * without a signature.
     */
    public function testWithNoDiskNamedThePhotosAreNotUnderTheWebRoot(): void
    {
        // Arrange
        $this->configure(['chat' => ['photos' => true]]);
        \Pramnos\Storage\Storage::reset();
        $web     = ROOT . DIRECTORY_SEPARATOR . 'www';
        $disk    = (new \ReflectionMethod(Photos::class, 'disk'))->invoke(null);
        $default = \Pramnos\Storage\Storage::getManager()->defaultDisk();

        // Act
        $root = (new \ReflectionProperty($disk, 'root'))->getValue($disk);

        // Assert
        $this->assertSame(Photos::privateRoot(), $root);
        $this->assertStringStartsNotWith($web, $root, 'not under the web root');
        $this->assertNotSame($default, $disk, 'never the default disk');
    }
}
