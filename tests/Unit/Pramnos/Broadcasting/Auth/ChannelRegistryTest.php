<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\ChannelRegistry;

/**
 * Covers which user may subscribe to which channel.
 *
 * The default matters more than any single rule here: an unmatched channel is
 * denied. A registry that defaulted to open would turn every misspelled pattern
 * into an authorization hole that no test would notice, because the misspelled
 * rule would still be registered and the channel would still work.
 */
#[CoversClass(ChannelRegistry::class)]
class ChannelRegistryTest extends TestCase
{
    /** A stand-in for an authenticated user. */
    private function user(int $id): object
    {
        return (object) ['userid' => $id, 'name' => 'User ' . $id];
    }

    /**
     * A matching rule that returns true authorizes a private channel, and the
     * placeholder value reaches the callback.
     */
    public function testAuthorizesPrivateChannelAndPassesPlaceholders(): void
    {
        // Arrange
        $seen     = null;
        $registry = (new ChannelRegistry())->channel(
            'order.{id}',
            function (?object $user, string $id) use (&$seen): bool {
                $seen = $id;
                return $user !== null && $id === '42';
            }
        );

        // Act
        $result = $registry->authorize('private-order.42', $this->user(7));

        // Assert
        $this->assertTrue($result);
        $this->assertSame('42', $seen, 'the placeholder is passed as a string argument');
    }

    /**
     * A rule returning false denies, even though the pattern matched.
     */
    public function testDeniesWhenRuleReturnsFalse(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel('order.{id}', fn () => false);

        // Act & Assert
        $this->assertFalse($registry->authorize('private-order.42', $this->user(7)));
    }

    /**
     * A channel no rule matches is denied.
     *
     * This is the whole safety property of the class: a missing rule must not be
     * an open channel.
     */
    public function testDeniesUnmatchedChannel(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel('order.{id}', fn () => true);

        // Act & Assert
        $this->assertFalse($registry->authorize('private-invoice.42', $this->user(7)));
        $this->assertFalse((new ChannelRegistry())->authorize('private-anything', $this->user(7)));
    }

    /**
     * A presence rule returns member data, which is handed back unchanged for the
     * signer to encode.
     */
    public function testReturnsPresenceMemberData(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel(
            'room.{room}',
            fn (?object $user, string $room): array => [
                'user_id'   => (string) $user->userid,
                'user_info' => ['name' => $user->name, 'room' => $room],
            ]
        );

        // Act
        $result = $registry->authorize('presence-room.lobby', $this->user(9));

        // Assert
        $this->assertIsArray($result);
        $this->assertSame('9', $result['user_id']);
        $this->assertSame('lobby', $result['user_info']['room']);
    }

    /**
     * A public channel needs no authorization and is reported as allowed without
     * consulting any rule.
     *
     * The endpoint still refuses to sign one — see the controller — but the
     * registry's answer is "nothing to decide", not "denied".
     */
    public function testPublicChannelNeedsNoRule(): void
    {
        // Arrange
        $called   = false;
        $registry = (new ChannelRegistry())->channel('updates', function () use (&$called) {
            $called = true;
            return false;
        });

        // Act
        $result = $registry->authorize('updates', null);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($called, 'a public channel must not consult a rule');
    }

    /**
     * A placeholder matches one segment and never a dot.
     *
     * `order.{id}` must not swallow `order.42.items`: a pattern matching more than
     * it names hands one rule's decision to a channel it was never written for,
     * which is a privilege escalation that looks like a working rule.
     */
    public function testPlaceholderDoesNotCrossDots(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel('order.{id}', fn () => true);

        // Act & Assert
        $this->assertTrue($registry->authorize('private-order.42', null));
        $this->assertFalse(
            $registry->authorize('private-order.42.items', null),
            'a nested channel must need its own rule'
        );
    }

    /**
     * Multiple placeholders arrive in pattern order.
     */
    public function testPassesMultiplePlaceholdersInOrder(): void
    {
        // Arrange
        $captured = [];
        $registry = (new ChannelRegistry())->channel(
            'org.{org}.team.{team}',
            function (?object $user, string $org, string $team) use (&$captured): bool {
                $captured = [$org, $team];
                return true;
            }
        );

        // Act
        $registry->authorize('private-org.acme.team.ops', null);

        // Assert
        $this->assertSame(['acme', 'ops'], $captured);
    }

    /**
     * Registering the same pattern twice replaces the rule rather than stacking
     * two, so an application overriding a rule gets the override.
     */
    public function testLaterRegistrationReplacesEarlier(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())
            ->channel('order.{id}', fn () => true)
            ->channel('order.{id}', fn () => false);

        // Act & Assert
        $this->assertFalse($registry->authorize('private-order.1', null));
    }

    /**
     * A rule returning a truthy non-bool, non-array value is treated as a denial.
     *
     * Only an explicit `true` or member data authorizes: a callback that
     * accidentally returns a string or an integer must not be read as consent.
     */
    public function testNonBooleanTruthyResultIsNotConsent(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel('order.{id}', fn () => 'yes');

        // Act & Assert
        $this->assertFalse($registry->authorize('private-order.1', null));
    }

    /**
     * has() reports whether a channel is routable at all, for a caller that wants
     * to distinguish "no rule" from "rule said no".
     */
    public function testHasReportsPatternCoverage(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel('order.{id}', fn () => true);

        // Act & Assert
        $this->assertTrue($registry->has('private-order.5'));
        $this->assertFalse($registry->has('private-invoice.5'));
    }

    /**
     * The prefix helpers classify channel names the way the protocol does,
     * including the encrypted-private prefix, whose longer form must be stripped
     * before the shorter one it starts with.
     */
    public function testPrefixHelpers(): void
    {
        // Assert
        $this->assertTrue(ChannelRegistry::needsAuthorization('private-x'));
        $this->assertTrue(ChannelRegistry::needsAuthorization('presence-x'));
        $this->assertFalse(ChannelRegistry::needsAuthorization('x'));

        $this->assertTrue(ChannelRegistry::isPresence('presence-room'));
        $this->assertFalse(ChannelRegistry::isPresence('private-room'));

        $this->assertSame('room', ChannelRegistry::stripPrefix('presence-room'));
        $this->assertSame('room', ChannelRegistry::stripPrefix('private-room'));
        // 'private-encrypted-' must win over the 'private-' it begins with.
        $this->assertSame('room', ChannelRegistry::stripPrefix('private-encrypted-room'));
        $this->assertSame('room', ChannelRegistry::stripPrefix('room'));
    }

    /**
     * A pattern with no placeholders matches exactly, and a regex metacharacter in
     * it is treated as a literal.
     *
     * Without quoting, a channel named `order.x` would match a pattern `order.x`
     * as intended but a channel `orderZx` would match too, because the dot is a
     * regex wildcard.
     */
    public function testPatternMetacharactersAreLiteral(): void
    {
        // Arrange
        $registry = (new ChannelRegistry())->channel('order.x', fn () => true);

        // Act & Assert
        $this->assertTrue($registry->authorize('private-order.x', null));
        $this->assertFalse(
            $registry->authorize('private-orderZx', null),
            'the dot in a pattern must be a literal dot'
        );
    }
}
