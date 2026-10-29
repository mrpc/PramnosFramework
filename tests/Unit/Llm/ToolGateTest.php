<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Client;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Policy\GrantGate;
use Pramnos\Llm\Policy\Grants\DomainMatcher;
use Pramnos\Llm\Policy\Grants\MaxNumberMatcher;
use Pramnos\Llm\Policy\Grants\OneOfMatcher;
use Pramnos\Llm\Policy\Grants\PrefixPathMatcher;
use Pramnos\Llm\Policy\Grants\ToolGrant;
use Pramnos\Llm\Policy\OpenGate;
use Pramnos\Llm\Policy\ToolGate;

/**
 * The gate before every tool call, and approvals given in advance with typed limits on the
 * arguments.
 *
 * What has to hold: a matcher reads the decoded value, so formatting cannot get past it; a grant
 * accounts for every argument a call carries, so an approved `to` does not cover an unapproved
 * `cc`; and a call the gate refuses never runs, alone or with others.
 */
#[CoversClass(GrantGate::class)]
#[CoversClass(ToolGrant::class)]
#[CoversClass(DomainMatcher::class)]
#[CoversClass(MaxNumberMatcher::class)]
#[CoversClass(PrefixPathMatcher::class)]
#[CoversClass(OneOfMatcher::class)]
#[CoversClass(OpenGate::class)]
class ToolGateTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'deepseek');
    }

    /**
     * Every address must be at an approved domain, however the addresses are written: a list,
     * one string of several, or "Name <address>". Anything that is not an address does not match.
     */
    public function testADomainMatcherReadsEveryAddress(): void
    {
        // Arrange
        $matcher = new DomainMatcher('@Example.com', 'example.org');

        // Act / Assert
        $this->assertTrue($matcher->matches('a@example.com'));
        $this->assertTrue($matcher->matches(['A@EXAMPLE.COM', 'b@example.org']), 'case does not matter');
        $this->assertTrue($matcher->matches('Ann <ann@example.com>, bob@example.org'));
        $this->assertFalse($matcher->matches('a@example.com, eve@other.test'), 'one outside is enough to refuse');
        $this->assertFalse($matcher->matches('a@example.com.other.test'), 'a domain that only starts the same');
        $this->assertFalse($matcher->matches('Ann <eve@other.test> a@example.com'), 'the bracketed address is the one that counts');
        $this->assertFalse($matcher->matches('not an address'));
        $this->assertFalse($matcher->matches(''), 'nothing is not within the grant');
        $this->assertFalse($matcher->matches(['a@example.com', 7]));
    }

    /** A number no greater than the limit, and only a number. */
    public function testAMaxNumberMatcher(): void
    {
        // Arrange
        $matcher = new MaxNumberMatcher(100.0);

        // Act / Assert
        $this->assertTrue($matcher->matches(100));
        $this->assertTrue($matcher->matches('99.5'));
        $this->assertFalse($matcher->matches(100.01));
        $this->assertFalse($matcher->matches('a hundred'));
        $this->assertFalse($matcher->matches(INF));
        $this->assertFalse($matcher->matches([50]));
    }

    /** A path under the directory once `..` is worked out; a NUL byte is never a path. */
    public function testAPrefixPathMatcher(): void
    {
        // Arrange
        $matcher = new PrefixPathMatcher('/var/safe');

        // Act / Assert
        $this->assertTrue($matcher->matches('/var/safe/report.csv'));
        $this->assertTrue($matcher->matches('/var/safe/./a/../b.txt'));
        $this->assertFalse($matcher->matches('/var/safe/../etc/passwd'), '.. leaves the directory');
        $this->assertFalse($matcher->matches('/var/safely/x'), 'a sibling with the same start');
        $this->assertFalse($matcher->matches("/var/safe/x\0.txt"));
        $this->assertFalse($matcher->matches(['/var/safe/x']));
    }

    /** One of the values exactly; a list matches when each does. */
    public function testAOneOfMatcher(): void
    {
        // Arrange
        $matcher = new OneOfMatcher('draft', 'review');

        // Act / Assert
        $this->assertTrue($matcher->matches('draft'));
        $this->assertTrue($matcher->matches(['draft', 'review']));
        $this->assertFalse($matcher->matches(['draft', 'published']));
        $this->assertFalse($matcher->matches([]));
        $this->assertFalse($matcher->matches(0), 'compared strictly');
    }

    /**
     * A grant accounts for every argument: matched, or named free. An argument it does not name
     * refuses the call — the `cc` beside an approved `to`. And it holds only for its tool, its
     * person and its time.
     */
    public function testAGrantAccountsForEveryArgument(): void
    {
        // Arrange
        $grant = new ToolGrant('send_email', ['to' => new DomainMatcher('example.com')], free: ['subject'], userId: 7, expiresAt: 1000);
        $mine  = ['userid' => 7];

        // Act / Assert
        $this->assertTrue($grant->allows('send_email', ['to' => 'a@example.com', 'subject' => 'Hi'], $mine, 900));
        $this->assertTrue($grant->allows('send_email', ['subject' => 'Hi'], $mine, 900), 'an argument left out is nothing to check');
        $this->assertFalse($grant->allows('send_email', ['to' => 'a@example.com', 'cc' => 'eve@other.test'], $mine, 900), 'cc is not covered');
        $this->assertFalse($grant->allows('send_email', ['to' => 'eve@other.test'], $mine, 900));
        $this->assertFalse($grant->allows('delete_user', ['to' => 'a@example.com'], $mine, 900), 'another tool');
        $this->assertFalse($grant->allows('send_email', ['to' => 'a@example.com'], ['userid' => 8], 900), 'another person');
        $this->assertFalse($grant->allows('send_email', ['to' => 'a@example.com'], $mine, 1001), 'expired');
        $this->assertTrue((new ToolGrant('send_email', free: ['to']))->allows('send_email', ['to' => 'x'], [], PHP_INT_MAX), 'anybody, for ever');
    }

    /**
     * The grant gate stops the calls that write, or are marked gated, unless a grant covers them;
     * every other call runs. With no gate configured, the open one lets everything run.
     */
    public function testTheGrantGate(): void
    {
        // Arrange
        $gate   = new GrantGate([new ToolGrant('send', free: ['to'])]);
        $writes = ['writes' => true];

        // Act / Assert
        $this->assertNull($gate->before('send', ['to' => 'x'], $writes, []));
        $this->assertSame(sprintf(GrantGate::NEEDS_APPROVAL, 'delete'), $gate->before('delete', [], $writes, []));
        $this->assertNotNull($gate->before('export', [], ['gated' => true], []), 'marked gated');
        $this->assertNull($gate->before('read', ['anything' => 1], [], []), 'a read is not gated');
        $this->assertInstanceOf(OpenGate::class, Llm::gate());
        $this->assertNull(Llm::gate()->before('delete', [], $writes, []));
    }

    /**
     * A call the gate refuses is never run — not alone, not with the reads run at once — and the
     * model is told why in its place; the gate is asked with the call's context.
     */
    public function testARefusedCallNeverRuns(): void
    {
        // Arrange — two parallel reads and a write, in one round; the gate refuses one read
        $this->answer([
            [200, self::calls([['a', ['n' => 1]], ['b', ['n' => 2]], ['c', ['n' => 3]]])],
            [200, self::says('Done')],
        ]);
        $ran  = [];
        $tool = static function (string $name) use (&$ran): array {
            return ['description' => $name, 'parameters' => [], 'parallel' => true, 'run' => static function () use ($name, &$ran): string {
                $ran[] = $name;

                return 'ran ' . $name;
            }];
        };
        $asked = [];
        Llm::use('tool_gate', new class ($asked) implements ToolGate {
            public function __construct(private array &$asked)
            {
            }

            public function before(string $name, array $arguments, array $tool, array $context): ?string
            {
                $this->asked[] = [$name, $arguments['n'] ?? null, $context['purpose'] ?? null];

                return $name === 'b' ? 'Not done: b is closed.' : null;
            }
        });
        $handed = [];
        $runner = static function (array $calls, callable $runHere) use (&$handed): array {
            $handed = array_column($calls, 'name');

            return array_map(static fn (array $c): string => $runHere($c['name'], $c['arguments']), $calls);
        };

        // Act
        $answer = (new Client(null, self::memoryLog()))->about(['purpose' => 'gate-test'])->inParallel($runner)
            ->askWithTools('', [['role' => 'user', 'content' => 'Go']], ['a' => $tool('a'), 'b' => $tool('b'), 'c' => $tool('c')], 3);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame([['a', 1, 'gate-test'], ['b', 2, 'gate-test'], ['c', 3, 'gate-test']], $asked);
        $this->assertSame(['a', 'c'], $handed, 'the refused call was not handed over');
        $this->assertSame(['a', 'c'], $ran);
        $results = array_values(array_filter($this->sent[1]['body']['messages'], static fn (array $m): bool => $m['role'] === 'tool'));
        $this->assertSame(['ran a', 'Not done: b is closed.', 'ran c'], array_column($results, 'content'));
    }
}
