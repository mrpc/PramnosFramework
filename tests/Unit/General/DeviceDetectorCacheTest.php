<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\General\DeviceDetectorCache;
use Pramnos\General\Helpers;

/** A YAML parser that must not be reached: the rules are to come from the cache. */
class RefusingYamlParser implements \DeviceDetector\Yaml\ParserInterface
{
    /** @var list<string> The files somebody asked for */
    public array $asked = [];

    /** Refuse, and remember which file it was. */
    public function parseFile(string $file)
    {
        $this->asked[] = basename($file);

        throw new \RuntimeException('read ' . basename($file) . ' from YAML');
    }
}

/**
 * device-detector's rules kept in the application's store: once one parse has read them, an
 * agent never seen before is parsed without opening a YAML file — `mobiles.yml` alone is about
 * 230 ms — in this process or the next.
 */
#[CoversClass(DeviceDetectorCache::class)]
#[CoversClass(Helpers::class)]
final class DeviceDetectorCacheTest extends TestCase
{
    /** An Android agent nobody has asked about: a model and a version made up for this run. */
    private static function newPhone(): string
    {
        return 'Mozilla/5.0 (Linux; Android 14; SM-S9' . random_int(100, 999) . 'B) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/' . random_int(120, 150) . '.0.0.0 Mobile Safari/537.36';
    }

    /** What this process holds, forgotten — as a new request would have it. */
    private static function newProcess(): void
    {
        (new \ReflectionProperty(DeviceDetectorCache::class, 'held'))->setValue(null, []);
    }

    /**
     * Warmed by one parse, the store holds every rule a phone needs: a new phone, in a new
     * process, is parsed by a detector whose YAML parser refuses — and is parsed right.
     */
    public function testANewAgentIsParsedWithoutReadingYaml(): void
    {
        // Arrange — one phone parsed through the framework, which keeps the rules
        Helpers::getBrowser(self::newPhone());
        self::newProcess();
        $yaml     = new RefusingYamlParser();
        $detector = new \DeviceDetector\DeviceDetector(self::newPhone());
        $detector->setCache(new DeviceDetectorCache());
        $detector->setYamlParser($yaml);

        // Act
        $detector->parse();

        // Assert
        $this->assertSame([], $yaml->asked, 'no YAML file was opened');
        $this->assertSame('Chrome Mobile', $detector->getClient('name'));
        $this->assertSame('Android', $detector->getOs('name'));
        $this->assertTrue($detector->isSmartphone(), 'the device rules — mobiles.yml — came from the store too');
    }

    /**
     * The cache's own contract: false for nothing, an entry once saved, gone once deleted or
     * flushed — in the process and in the store alike.
     */
    public function testTheCacheKeepsAndForgets(): void
    {
        // Arrange
        $cache = new DeviceDetectorCache('device-detector-test');
        $id    = 'probe' . bin2hex(random_bytes(4));

        // Act + Assert
        $this->assertFalse($cache->fetch($id));
        $this->assertFalse($cache->contains($id));
        $this->assertTrue($cache->save($id, ['rule' => 1]));
        self::newProcess();
        $this->assertSame(['rule' => 1], $cache->fetch($id), 'read back from the store');
        $this->assertTrue($cache->contains($id));
        $cache->delete($id);
        self::newProcess();
        $this->assertFalse($cache->fetch($id), 'deleted from the store');
        $cache->save($id, ['rule' => 2], 60);
        $cache->flushAll();
        self::newProcess();
        $this->assertFalse($cache->fetch($id), 'flushed');
    }

    /**
     * A store that cannot be reached answers «nothing kept», and keeping or forgetting in it
     * says it failed — the rules are then read from YAML, never an error out of a parse.
     */
    public function testAStoreThatIsDownIsNoEntry(): void
    {
        // Arrange
        $down = new class ('device-detector-test') extends \Pramnos\Cache\Cache {
            /** The store refuses every read. */
            public function load($id, $category = null, $timeout = null)
            {
                throw new \RuntimeException('redis is away');
            }

            /** And every write. */
            public function save($data = '', $id = null)
            {
                throw new \RuntimeException('redis is away');
            }
        };
        $cache = new DeviceDetectorCache('device-detector-test', $down);
        self::newProcess();

        // Act + Assert
        $this->assertFalse($cache->fetch('rules'));
        $this->assertFalse($cache->save('rules', ['rule' => 1]), 'not kept in the store');
        $this->assertSame(['rule' => 1], $cache->fetch('rules'), 'but held for this process');
    }
}
