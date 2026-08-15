<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\User;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\SignInFingerprint;
use Pramnos\User\User;

/**
 * `usertokens.deviceinfo` holds what its own column comment promised.
 *
 * The column is declared as *"JSON-encoded device/client information (browser, OS, IP
 * at token creation)"*, `Token` has decoded it for years, and `addToken()` wrote `''`
 * at every call site. So the active-sessions list — which exists so a person can
 * recognise a session they do not remember — had nothing to recognise it by.
 *
 * This is the third variant of the same shape found this week: a control described
 * more strongly than it was built. A guide naming methods that never existed, a class
 * attribute implying a mechanism that had been deleted, and now a column whose comment
 * describes data nothing ever wrote.
 */
class DeviceInfoTest extends TestCase
{
    /** @var string|null The agent the environment had */
    private ?string $originalAgent = null;

    /**
     * Remembers the request header these tests forge.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->originalAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Restores it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->originalAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $this->originalAgent;
        }
    }

    /**
     * Calls the private builder.
     *
     * @return string
     */
    private function build(): string
    {
        return (string) (new \ReflectionMethod(User::class, 'currentDeviceInfo'))
            ->invoke(null);
    }

    /**
     * It records the device, in words and as a fingerprint.
     *
     * @return void
     */
    public function testItRecordsTheDeviceInBothForms(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';

        // Act
        $info = json_decode($this->build(), true);

        // Assert
        $this->assertIsArray($info);
        $this->assertSame('chrome|windows', $info['device']);
        $this->assertSame('Chrome on Windows', $info['label']);
    }

    /**
     * What it stores is decodable by the class that reads it.
     *
     * `Token::load()` accepts either a serialised value or JSON. Writing something
     * neither would leave the column as unreadable as it was empty, which is the
     * failure this change exists to end rather than to reshape.
     *
     * @return void
     */
    public function testWhatIsWrittenCanBeReadBack(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

        // Act
        $raw     = $this->build();
        $decoded = json_decode($raw, true);

        // Assert
        $this->assertNotSame('', $raw);
        $this->assertNotNull($decoded, 'Token::load() decodes this with json_decode().');
        $this->assertSame('Safari on iPhone or iPad', $decoded['label']);
    }

    /**
     * Two sessions from the same browser record the same device across an update.
     *
     * The reason the fingerprint is stored rather than the raw user agent. Storing the
     * agent would make every session look like a different device after any browser
     * update — turning a list meant for recognition into a list of strangers.
     *
     * @return void
     */
    public function testABrowserUpdateDoesNotChangeTheRecordedDevice(): void
    {
        // Arrange & Act
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36';
        $before = json_decode($this->build(), true)['device'];

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';
        $after = json_decode($this->build(), true)['device'];

        // Assert
        $this->assertSame($before, $after);
    }

    /**
     * The raw user agent is not stored.
     *
     * It carries the version and the build number, so keeping it would reintroduce the
     * churn the fingerprint removes — and it is the longest thing that could go in
     * this column, on a row written at every login.
     *
     * @return void
     */
    public function testTheRawUserAgentIsNotStored(): void
    {
        // Arrange
        $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';
        $_SERVER['HTTP_USER_AGENT'] = $agent;

        // Act
        $raw = $this->build();

        // Assert
        $this->assertStringNotContainsString('AppleWebKit', $raw);
        $this->assertStringNotContainsString('133.0.0.0', $raw);
    }

    /**
     * A request with no user agent still produces a readable record.
     *
     * A token issued from the console, or by a client that sends no agent. An empty
     * string here would be indistinguishable from the bug being fixed.
     *
     * @return void
     */
    public function testARequestWithNoAgentStillRecordsSomething(): void
    {
        // Arrange
        unset($_SERVER['HTTP_USER_AGENT']);

        // Act
        $info = json_decode($this->build(), true);

        // Assert
        $this->assertIsArray($info);
        $this->assertSame(
            SignInFingerprint::fromUserAgent(null),
            $info['device']
        );
    }
}
