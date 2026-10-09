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
        // The Gravatar and adoption switches are settings, written and deleted here.
        Schema::table('settings', $db);
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
        $provider = static fn (string $url): string => $bytes;
        $this->assertFalse(ProfilePhoto::adoptFromProvider($this->user, 'https://provider.example/p.png', $provider));

        Settings::setSetting(ProfilePhoto::ADOPT_SETTING, '1', false);
        $this->assertTrue(ProfilePhoto::adoptFromProvider($this->user, 'https://provider.example/p.png', $provider));
        $this->assertGreaterThan(0, (int) $this->user->photo);

        // A picture of the user's own is never replaced by the provider's.
        $this->assertFalse(ProfilePhoto::adoptFromProvider($this->user, 'https://provider.example/p.png', $provider));
        // A provider that answers with nothing copies nothing.
        $this->assertFalse(ProfilePhoto::adoptFromProvider(new User($this->createTestUser()), 'https://provider.example/p.png', static fn (string $url): bool => false));
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

    /** The stored files of the user's picture: its thumb and its original. @return list<string> */
    private function pictureFiles(User $user): array
    {
        $media = (new MediaObject())->loadByUsage((int) $user->photo);

        return [$media->getThumb()->filename, $media->filename];
    }

    /**
     * A WebP picture is accepted, and stored — thumb and original — as JPEG.
     */
    public function testAWebpPictureIsStoredAsJpeg(): void
    {
        // Arrange
        $file  = tempnam(sys_get_temp_dir(), 'pf-photo') . '.webp';
        $image = imagecreatetruecolor(500, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 220));
        imagewebp($image, $file);
        $this->files[] = $file;

        // Act
        $error = ProfilePhoto::set($this->user, ['name' => 'me.webp', 'type' => 'image/webp', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]);

        // Assert
        $this->assertNull($error);
        foreach ($this->pictureFiles($this->user) as $stored) {
            $this->assertSame(IMAGETYPE_JPEG, getimagesize($stored)[2] ?? null, basename($stored) . ' is not a JPEG');
        }
        $this->assertSame([ProfilePhoto::SIZE, ProfilePhoto::SIZE], array_slice(getimagesize($this->pictureFiles($this->user)[0]), 0, 2));
    }

    /**
     * A transparent PNG becomes a JPEG on white, not on black.
     */
    public function testATransparentPictureIsFlattenedOntoWhite(): void
    {
        // Arrange — fully transparent
        $file  = tempnam(sys_get_temp_dir(), 'pf-photo') . '.png';
        $image = imagecreatetruecolor(300, 300);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $file);
        $this->files[] = $file;

        // Act
        ProfilePhoto::set($this->user, ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]);

        // Assert — the centre pixel is white
        $stored = imagecreatefromjpeg($this->pictureFiles($this->user)[0]);
        $rgb    = imagecolorat($stored, 128, 128);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF, 'transparency became a dark background');
    }

    /**
     * A file that declares a huge image is refused from its header, before it is decoded.
     *
     * A few bytes can announce 10000x10000 pixels; decoding that takes the memory announced.
     */
    public function testAHugeImageIsRefusedBeforeItIsDecoded(): void
    {
        // Arrange — a GIF header declaring 10000 x 10000, and nothing after it
        $bytes = "GIF89a\x10\x27\x10\x27\x00\x00\x00";

        // Act
        $file = ProfilePhoto::toJpeg($bytes, $reason);

        // Assert
        $this->assertNull($file);
        $this->assertStringContainsString('too large', (string) $reason);
        $this->assertNull(ProfilePhoto::toJpeg('', $empty));
        $this->assertNotNull($empty);
    }

    /**
     * A header that reads but a body that does not decode is refused, as is an upload with no
     * file behind it.
     */
    public function testAPictureThatCannotBeDecodedIsRefused(): void
    {
        // Arrange — a valid 10 x 10 GIF header, and a body cut off
        $bytes = "GIF89a\x0a\x00\x0a\x00\x00\x00\x00";

        // Act
        $file = ProfilePhoto::toJpeg($bytes, $reason);

        // Assert
        $this->assertNull($file);
        $this->assertSame('The picture could not be read.', $reason);
        $this->assertNotNull(ProfilePhoto::set($this->user, ['error' => UPLOAD_ERR_OK, 'tmp_name' => '']));
    }

    /**
     * The Gravatar button copies the picture of the account's address, looked up by SHA-256,
     * with d=404, and stores it as JPEG like an upload.
     */
    public function testGravatarCopiesThePictureOfTheAddress(): void
    {
        // Arrange — a Gravatar answering with an image, recording what it was asked
        ob_start();
        imagepng(imagecreatetruecolor(400, 400));
        $png   = (string) ob_get_clean();
        $asked = '';
        $fetch = static function (string $url, ?int &$status) use ($png, &$asked): string {
            $asked  = $url;
            $status = 200;

            return $png;
        };
        $this->user->email = '  Someone@Example.COM ';

        // Act
        $error = ProfilePhoto::fromGravatar($this->user, $fetch);

        // Assert
        $this->assertNull($error);
        $this->assertSame(
            'https://www.gravatar.com/avatar/' . hash('sha256', 'someone@example.com') . '?s=1024&d=404',
            $asked,
            'Gravatar was not asked by the trimmed, lowercased address'
        );
        $this->assertGreaterThan(0, (int) $this->user->photo);
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($this->pictureFiles($this->user)[0])[2] ?? null);
    }

    /**
     * No Gravatar, no answer, no address, or the button turned off: each says why, and the
     * picture stays as it was.
     */
    public function testGravatarSaysWhyWhenItCannot(): void
    {
        // Arrange
        $notFound = static function (string $url, ?int &$status): string {
            $status = 404;

            return 'Not Found';
        };
        $down = static function (string $url, ?int &$status): bool {
            $status = 0;

            return false;
        };

        // Act + Assert
        $this->assertStringContainsString('no Gravatar picture', (string) ProfilePhoto::fromGravatar($this->user, $notFound));
        $this->assertStringContainsString('could not be reached', (string) ProfilePhoto::fromGravatar($this->user, $down));

        $this->user->email = '';
        $this->assertStringContainsString('no email address', (string) ProfilePhoto::fromGravatar($this->user, $down));

        Settings::setSetting(ProfilePhoto::GRAVATAR_SETTING, 'off', false);
        try {
            $this->assertFalse(ProfilePhoto::gravatarAllowed());
            $this->assertStringContainsString('not available', (string) ProfilePhoto::fromGravatar($this->user, $down));
        } finally {
            Settings::deleteSetting(ProfilePhoto::GRAVATAR_SETTING);
        }
        $this->assertTrue(ProfilePhoto::gravatarAllowed(), 'the button is on unless turned off');
        $this->assertSame(0, (int) $this->user->photo);
    }

    /**
     * The profile action's `gravatar` submit goes to Gravatar, and reports what came back.
     */
    public function testTheProfileActionTakesTheGravatarButton(): void
    {
        // Arrange — a user whose address has no Gravatar answer reachable from a test: the
        // action's own fetch is the real one, so the answer is an error either way
        Settings::setSetting(ProfilePhoto::GRAVATAR_SETTING, 'off', false);
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => (int) $this->user->userid, 'usertype' => 1], 'test');
        $account = new class () extends \Pramnos\Auth\Controllers\Account {
            public array $errors = [];

            public function __construct()
            {
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }
        };

        try {
            // Act
            $_POST = ['gravatar' => '1'];
            $account->profilephoto();
        } finally {
            $_POST = [];
            \Pramnos\Http\RequestIdentity::reset();
            Settings::deleteSetting(ProfilePhoto::GRAVATAR_SETTING);
        }

        // Assert — it reached fromGravatar(), which refused with the setting off
        $this->assertSame(['Gravatar is not available here.'], $account->errors);
    }

    /**
     * A Gravatar that answers becomes the picture, and the action says so.
     */
    public function testTheGravatarButtonSetsThePicture(): void
    {
        // Arrange
        ob_start();
        imagepng(imagecreatetruecolor(300, 300));
        $png = (string) ob_get_clean();
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => (int) $this->user->userid, 'usertype' => 1], 'test');
        $account = new class ($png) extends \Pramnos\Auth\Controllers\Account {
            public array $messages = [];

            public function __construct(private string $png)
            {
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            protected function gravatarFetcher(): ?callable
            {
                $png = $this->png;

                return static function (string $url, ?int &$status) use ($png): string {
                    $status = 200;

                    return $png;
                };
            }
        };

        try {
            // Act
            $_POST = ['gravatar' => '1'];
            $account->profilephoto();
        } finally {
            $_POST = [];
            \Pramnos\Http\RequestIdentity::reset();
        }

        // Assert
        $this->assertSame(['Your Gravatar picture is now your profile picture.'], $account->messages);
        $this->assertGreaterThan(0, (int) $this->reloaded()->photo);
    }

    /**
     * A phone photo is turned upright from its EXIF orientation.
     *
     * The rotation is asserted on an image whose top-left pixel is marked: orientation 6 (turned
     * a quarter clockwise to view) moves it to the top right; 8 to the bottom left; 3 to the
     * bottom right; 2 mirrors it to the top right without turning.
     */
    public function testAnImageIsTurnedUprightFromItsOrientation(): void
    {
        // Arrange — 40 x 20, with the top-left pixel red
        $make = static function (): \GdImage {
            $image = imagecreatetruecolor(40, 20);
            imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
            imagesetpixel($image, 0, 0, imagecolorallocate($image, 255, 0, 0));

            return $image;
        };
        $redAt = static fn (\GdImage $image, int $x, int $y): bool => ((imagecolorat($image, $x, $y) >> 16) & 0xFF) > 200;

        // Act
        $six   = ProfilePhoto::orient($make(), 6);
        $eight = ProfilePhoto::orient($make(), 8);
        $three = ProfilePhoto::orient($make(), 3);
        $two   = ProfilePhoto::orient($make(), 2);
        $one   = ProfilePhoto::orient($make(), 1);

        // Assert
        $this->assertSame([20, 40], [imagesx($six), imagesy($six)], 'a quarter turn did not swap the sides');
        $this->assertTrue($redAt($six, 19, 0), 'orientation 6 is not a quarter turn clockwise');
        $this->assertTrue($redAt($eight, 0, 39), 'orientation 8 is not a quarter turn anticlockwise');
        $this->assertTrue($redAt($three, 39, 19), 'orientation 3 is not a half turn');
        $this->assertTrue($redAt($two, 39, 0), 'orientation 2 is not a mirror');
        $this->assertTrue($redAt($one, 0, 0), 'an upright image was changed');
        foreach ([4, 5, 7] as $mirroredTurn) {
            $this->assertInstanceOf(\GdImage::class, ProfilePhoto::orient($make(), $mirroredTurn));
        }
    }
}
