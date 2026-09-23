<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Storage\Drivers\LocalDriver;
use Pramnos\Storage\Storage;
use Pramnos\Storage\StorageManager;

/**
 * The façade works without anybody bootstrapping it, and stays optional.
 *
 * WHAT: `Storage::getManager()` builds itself from the `storage` block of `app.php`, and
 *       falls back to one `local` disk rooted at `www/` when there is none.
 *
 * WHY:  it used to throw *"Storage has not been initialised. Call Storage::init()"*, and
 *       **nothing in the framework called it**. Three drivers, a façade proxying twenty
 *       methods, and no way in unless an application already knew the subsystem existed —
 *       which is how a subsystem comes to be rediscovered and written a second time beside
 *       itself.
 *
 *       The fallback is what makes it optional in the sense that matters: an installation
 *       that never mentions storage gets a disk pointing at the directory its uploads are
 *       already in, so nothing about where files land changes, and nothing new has to be
 *       configured for the façade to be usable.
 */
#[CoversClass(Storage::class)]
class StorageBootstrapTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = (array) (new \ReflectionProperty(Settings::class, 'settings'))->getValue();
        Storage::reset();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $this->saved);
        Storage::reset();
    }

    /** @param array<string, mixed>|null $storage */
    private function configure(?array $storage): void
    {
        $settings = $this->saved;

        if ($storage === null) {
            unset($settings['storage']);
        } else {
            $settings['storage'] = $storage;
        }

        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);
        Storage::reset();
    }

    /**
     * With no configuration at all, the façade still works.
     *
     * **The assertion that makes it optional.** Every existing installation has no
     * `storage` block, and every one of them must be able to call `Storage::put()` without
     * first learning that a subsystem exists.
     */
    public function testWithNoConfigurationItStillWorks(): void
    {
        // Arrange
        $this->configure(null);

        // Act + Assert
        $this->assertInstanceOf(LocalDriver::class, Storage::getManager()->defaultDisk());
    }

    /**
     * And the default disk points at the directory uploads are already in.
     *
     * `www/` rather than `www/uploads/`: the paths the media system stores are relative to
     * the web root — `uploads/2026/09/x.png` — so a disk rooted deeper would mean rewriting
     * every row in `media` to use it.
     */
    public function testTheDefaultDiskIsRootedAtTheWebRoot(): void
    {
        // Arrange
        $this->configure(null);

        // Act — a write through the façade lands where the framework already writes
        $probe = 'uploads/.storage-probe-' . bin2hex(random_bytes(4));

        try {
            $this->assertTrue(Storage::put($probe, 'x'));

            // Assert
            $expected = (defined('ROOT') ? ROOT : getcwd()) . '/www/' . $probe;
            $this->assertFileExists($expected, 'the default disk is not rooted at www/');
        } finally {
            Storage::delete($probe);
        }
    }

    /**
     * A configured block is used, including its named disks.
     */
    public function testAConfiguredBlockIsUsed(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/pramnos_configured_' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $this->configure([
            'default' => 'media',
            'disks'   => ['media' => ['driver' => 'local', 'root' => $root, 'url' => '/m']],
        ]);

        try {
            // Act + Assert
            $this->assertSame('/m/a.png', Storage::disk('media')->url('a.png'));
            $this->assertSame('/m/a.png', Storage::url('a.png'), 'the default is not the named disk');
        } finally {
            @rmdir($root);
        }
    }

    /**
     * A settings block arriving as an object is read, not ignored.
     *
     * `Settings::getSetting()` casts an array setting to a `stdClass`, and the nested
     * `disks` with it. Reading either with `is_array()` makes a configured block read as
     * **no configuration** — which this framework did twice in one day, once in this
     * file's neighbour. So the cast is asserted rather than assumed.
     */
    public function testASettingsBlockArrivingAsAnObjectIsRead(): void
    {
        // Arrange — exactly what getSetting() hands back
        $root = sys_get_temp_dir() . '/pramnos_object_' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $settings            = $this->saved;
        $settings['storage'] = (object) [
            'default' => 'media',
            'disks'   => (object) [
                'media' => (object) ['driver' => 'local', 'root' => $root, 'url' => '/o'],
            ],
        ];
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);
        Storage::reset();

        try {
            // Act + Assert
            $this->assertSame('/o/a.png', Storage::url('a.png'));
        } finally {
            @rmdir($root);
        }
    }

    /**
     * `init()` still works and still wins.
     *
     * For an application that would rather configure in code, and because it is the
     * documented entry point — removing it would break anybody who read the class.
     */
    public function testInitStillWinsOverSettings(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/pramnos_init_' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $this->configure(['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => '/nowhere', 'url' => '/settings']]]);
        Storage::init(['default' => 'code', 'disks' => ['code' => ['driver' => 'local', 'root' => $root, 'url' => '/code']]]);

        try {
            // Act + Assert
            $this->assertSame('/code/a.png', Storage::url('a.png'));
        } finally {
            @rmdir($root);
        }
    }

    /**
     * A pre-built manager can still be injected.
     *
     * The seam an application with two buckets, or a test with a double, already uses.
     */
    public function testAManagerCanBeInjected(): void
    {
        // Arrange
        $manager = new StorageManager([
            'default' => 'x',
            'disks'   => ['x' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/x']],
        ]);

        // Act
        Storage::setManager($manager);

        // Assert
        $this->assertSame($manager, Storage::getManager());
    }
}
