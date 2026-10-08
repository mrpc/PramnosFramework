<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;
use Pramnos\Media\MediaObject;
use Pramnos\User\ProfilePhoto;
use Pramnos\User\User;

/**
 * A media object whose remote fetch answers with a local image: no network in a test.
 */
final class LocalFetchMediaObject extends MediaObject
{
    public function __construct(private string $bytes)
    {
        parent::__construct();
    }

    protected function fetchRemote(string $url, ?string &$reason, ?int &$status): string|false
    {
        $status = 200;

        return $this->bytes;
    }
}

/**
 * A profile picture is set, shown, replaced, removed, copied from a provider, and erased.
 *
 * `users.photo` holds a media usage; `avatarurl` — which every API and screen reads — is made
 * from it on load. These tests run the real Media pipeline against the database and the upload
 * directory, so they are serial: the upload directory is shared by every worker.
 */
#[CoversClass(ProfilePhoto::class)]
#[CoversClass(\Pramnos\Auth\Controllers\Account::class)]
#[CoversClass(\Pramnos\User\User::class)]
#[CoversClass(\Pramnos\Auth\AccountErasure::class)]
#[CoversClass(\Pramnos\Auth\OAuth2\UserClaims::class)]
#[CoversClass(MediaObject::class)]
#[Group('serial')]
class ProfilePhotoTest extends BaseTestCase
{
    private User $user;

    /** @var list<string> Image files this test made, removed at the end */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        Schema::table('users', $db);
        Schema::table('userdetails', $db);
        Schema::table('mediause', $db);
        ProfilePhoto::reset();

        $this->user = new User($this->createTestUser());
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        // Every picture this class left, and its files under www/uploads: the module is the
        // pictures', and this class is the only one that writes it.
        $usages = Factory::getDatabase()->queryBuilder()->table('#PREFIX#mediause')
            ->select('usageid')->where('module', ProfilePhoto::MODULE)->get();
        while ($usages && ($row = $usages->fetch()) !== null) {
            ProfilePhoto::release((int) $row['usageid']);
        }
        Settings::deleteSetting(ProfilePhoto::ADOPT_SETTING);
        ProfilePhoto::reset();
        parent::tearDown();
    }

    /** A PNG of the given size and colour, as an upload entry. @return array<string, mixed> */
    private function upload(int $width, int $height, int $red = 200): array
    {
        $file  = tempnam(sys_get_temp_dir(), 'pf-photo') . '.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 80, 40));
        imagepng($image, $file);
        $this->files[] = $file;

        return ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    /** The user as a fresh load sees it, past every cache. */
    private function reloaded(): User
    {
        User::clearUserCache();
        Factory::getDatabase()->cacheflush('userlist');
        ProfilePhoto::reset();

        return new User((int) $this->user->userid);
    }

    /** The files stored under the pictures' upload directory. @return list<string> */
    private function storedFiles(): array
    {
        $dir = ROOT . DS . 'www' . DS . 'uploads' . DS . ProfilePhoto::MODULE;
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    /** Whether a usage row exists. */
    private function usageExists(int $usageId): bool
    {
        $row = Factory::getDatabase()->queryBuilder()->table('#PREFIX#mediause')->where('usageid', $usageId)->first();

        return $row !== null && $row !== false && (int) ($row->numRows ?? 0) > 0;
    }

    /**
     * An uploaded picture becomes the user's photo, as a square, and avatarurl is its absolute URL.
     */
    public function testAnUploadedPictureIsSquareAndBecomesTheAvatar(): void
    {
        // Act
        $error = ProfilePhoto::set($this->user, $this->upload(600, 400));

        // Assert
        $this->assertNull($error);
        $loaded = $this->reloaded();
        $this->assertGreaterThan(0, (int) $loaded->photo);
        $this->assertMatchesRegularExpression('#^https?://#', $loaded->avatarurl, 'avatarurl is not absolute');

        $media = (new MediaObject())->loadByUsage((int) $loaded->photo);
        $thumb = $media->getThumb();
        $size  = getimagesize($thumb->filename);
        $this->assertSame([ProfilePhoto::SIZE, ProfilePhoto::SIZE], [$size[0], $size[1]], 'the picture is not a square');
    }

    /**
     * Replacing the picture releases the previous one's usage; removing it releases the last.
     */
    public function testReplacingAndRemovingReleaseTheUsage(): void
    {
        // Arrange
        ProfilePhoto::set($this->user, $this->upload(400, 400, 10));
        $first = (int) $this->user->photo;

        // Act — replace, then remove
        ProfilePhoto::set($this->user, $this->upload(400, 400, 250));
        $second = (int) $this->user->photo;
        ProfilePhoto::remove($this->user);

        // Assert
        $this->assertNotSame($first, $second);
        $this->assertFalse($this->usageExists($first), 'the replaced picture was not released');
        $this->assertFalse($this->usageExists($second), 'the removed picture was not released');
        $loaded = $this->reloaded();
        $this->assertSame(0, (int) $loaded->photo);
        $this->assertSame((string) Settings::getSetting('defaultAvatarUrl'), (string) $loaded->avatarurl);
    }

    /**
     * The same image uploaded twice is stored once, and still becomes the picture.
     *
     * Media does not keep a duplicate: it points at the first copy, with no id of its own.
     */
    public function testTheSameImageTwiceStillBecomesThePicture(): void
    {
        // Arrange
        $upload = $this->upload(300, 300, 77);
        ProfilePhoto::set($this->user, $upload);
        $filesBefore = $this->storedFiles();

        // Act — the same bytes again, as another user's picture
        $other = new User($this->createTestUser());
        $error = ProfilePhoto::set($other, $this->upload(300, 300, 77));

        // Assert
        $this->assertNull($error);
        $this->assertGreaterThan(0, (int) $other->photo);
        // The repeated upload is not kept beside the first: it would be a file nothing uses.
        $this->assertSame($filesBefore, $this->storedFiles(), 'the duplicate upload was left on disk');
    }

    /**
     * A file that is not an image is refused, and the current picture is kept.
     */
    public function testANonImageIsRefusedAndThePictureKept(): void
    {
        // Arrange
        ProfilePhoto::set($this->user, $this->upload(300, 300));
        $kept = (int) $this->user->photo;
        $text = tempnam(sys_get_temp_dir(), 'pf-photo') . '.txt';
        file_put_contents($text, 'not an image');
        $this->files[] = $text;

        // Act
        $error = ProfilePhoto::set($this->user, ['name' => 'me.txt', 'type' => 'text/plain', 'tmp_name' => $text, 'error' => UPLOAD_ERR_OK, 'size' => 12]);

        // Assert
        $this->assertNotNull($error);
        $this->assertSame($kept, (int) $this->user->photo);
        $this->assertNotNull(ProfilePhoto::set($this->user, ['error' => UPLOAD_ERR_NO_FILE]), 'a missing file was accepted');
    }

    /**
     * A provider's picture is copied only when the installation allows it and the user has none.
     */
    public function testAProvidersPictureIsCopiedOnlyWhenAllowedAndNoneIsSet(): void
    {
        // Arrange — a provider answering with a real image
        ob_start();
        $image = imagecreatetruecolor(320, 320);
        imagepng($image);
        $bytes = (string) ob_get_clean();

        // Act + Assert — off by default
        $this->assertFalse(ProfilePhoto::adoptFromProvider($this->user, 'https://provider.example/p.png', new LocalFetchMediaObject($bytes)));

        Settings::setSetting(ProfilePhoto::ADOPT_SETTING, '1', false);
        $this->assertTrue(ProfilePhoto::adoptFromProvider($this->user, 'https://provider.example/p.png', new LocalFetchMediaObject($bytes)));
        $this->assertGreaterThan(0, (int) $this->user->photo);

        // A picture of the user's own is never replaced by the provider's.
        $this->assertFalse(ProfilePhoto::adoptFromProvider($this->user, 'https://provider.example/p.png', new LocalFetchMediaObject($bytes)));
    }

    /**
     * The picture is in the OIDC claims, and erasing the account releases it.
     */
    public function testThePictureIsAClaimAndErasureReleasesIt(): void
    {
        // Arrange
        ProfilePhoto::set($this->user, $this->upload(300, 300, 5));
        $usage = (int) $this->user->photo;
        ProfilePhoto::reset();

        // Act
        $claims = \Pramnos\Auth\OAuth2\UserClaims::for((int) $this->user->userid, ['openid', 'profile']);
        (new \Pramnos\Auth\AccountErasure(Factory::getDatabase()))->erase((int) $this->user->userid);

        // Assert
        $this->assertMatchesRegularExpression('#^https?://#', (string) ($claims['picture'] ?? ''), 'the picture claim is empty');
        $this->assertFalse($this->usageExists($usage), 'erasing the account left the picture');
    }

    /**
     * The profile screen's action sets the picture from the upload, and removes it on `remove`.
     *
     * Called directly: the token check that guards it runs in exec(), before any action, and is
     * the controller's own tested behaviour.
     */
    public function testTheProfileActionSetsAndRemovesThePicture(): void
    {
        // Arrange
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => (int) $this->user->userid, 'usertype' => 1], 'test');
        $account = new class () extends \Pramnos\Auth\Controllers\Account {
            public array $messages = [];
            public array $errors = [];
            public array $redirects = [];

            public function __construct()
            {
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }
        };

        try {
            // Act — an upload, then a remove, then an upload that is not there
            $_FILES = ['photo' => $this->upload(300, 300, 33)];
            $_POST  = [];
            $account->profilephoto();
            $set = (int) $this->reloaded()->photo;

            $_FILES = [];
            $_POST  = ['remove' => '1'];
            $account->profilephoto();
            $removed = (int) $this->reloaded()->photo;

            $_POST = [];
            $account->profilephoto();
        } finally {
            $_FILES = [];
            $_POST  = [];
            \Pramnos\Http\RequestIdentity::reset();
        }

        // Assert
        $this->assertGreaterThan(0, $set, 'the upload did not become the picture');
        $this->assertSame(0, $removed, 'remove left the picture');
        $this->assertSame(['Your profile picture has been updated.', 'Your profile picture has been removed.'], $account->messages);
        $this->assertCount(1, $account->errors, 'a post with no file was not refused');
        $this->assertCount(3, $account->redirects);
        $this->assertStringEndsWith('/profile', $account->redirects[0]);
    }

    /**
     * Nothing to remove, nothing to release, no id: each is a no-op, not an error.
     */
    public function testTheEmptyCasesDoNothing(): void
    {
        // Act
        ProfilePhoto::remove($this->user);
        ProfilePhoto::release(0);

        // Assert
        $this->assertSame(0, (int) $this->reloaded()->photo);
        $this->assertSame('', ProfilePhoto::url(0));
        // Without a picture the claim is the installation's default, or nothing.
        $claims = \Pramnos\Auth\OAuth2\UserClaims::for((int) $this->user->userid, ['openid', 'profile']);
        $this->assertNull($claims['picture']);
    }

    /**
     * When the media store cannot be read, the URL is empty and a release is logged, not thrown.
     *
     * The avatar is read on every user load: an unreadable store must cost the picture, never
     * the page that loads a user.
     */
    public function testAnUnreadableMediaStoreCostsOnlyThePicture(): void
    {
        // Arrange — the usage table out of the way for a moment
        $db    = Factory::getDatabase();
        $table = $db->schema()->quoteTable('#PREFIX#mediause');
        $aside = $db->schema()->quoteTable('#PREFIX#mediause_aside');
        $db->query("ALTER TABLE {$table} RENAME TO " . ($db->type === 'postgresql' ? '"mediause_aside"' : $aside));

        try {
            // Act
            $url = ProfilePhoto::url(987654);
            ProfilePhoto::release(987654);
        } finally {
            $db->query("ALTER TABLE {$aside} RENAME TO " . ($db->type === 'postgresql' ? '"mediause"' : $table));
        }

        // Assert
        $this->assertSame('', $url);
    }

    /**
     * The action is a write action of the real controller, and refuses a visitor who is not
     * signed in.
     */
    public function testTheActionIsAWriteActionAndNeedsASignedInUser(): void
    {
        // Arrange
        $account = new \Pramnos\Auth\Controllers\Account(\Pramnos\Application\Application::getInstance());
        $writes  = (new \ReflectionProperty(\Pramnos\Application\Controller::class, 'writeActions'))->getValue($account);
        $probe   = new class () extends \Pramnos\Auth\Controllers\Account {
            public array $redirects = [];

            public function __construct()
            {
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }
        };

        // Act — nobody signed in
        \Pramnos\Http\RequestIdentity::reset();
        $probe->profilephoto();

        // Assert
        $this->assertContains('profilephoto', $writes, 'the picture can be changed without a token');
        $this->assertStringEndsWith('login', $probe->redirects[0] ?? '');
    }

    /**
     * The data export names the picture's URL, under its own label.
     */
    public function testTheExportIncludesThePicture(): void
    {
        // Arrange
        ProfilePhoto::set($this->user, $this->upload(300, 300, 99));
        $probe = new class () extends \Pramnos\Auth\Controllers\Account {
            public function __construct()
            {
            }

            public function export(int $userId): array
            {
                return $this->buildExportData($userId);
            }

            public function labels(): array
            {
                return $this->exportSectionLabels();
            }
        };

        // Act
        $export = $probe->export((int) $this->user->userid);

        // Assert
        $this->assertMatchesRegularExpression('#^https?://#', (string) ($export['profile_photo']['url'] ?? ''));
        $this->assertContains('Profile picture', $probe->labels());
    }
}
