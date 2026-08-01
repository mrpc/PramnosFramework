<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\RealtimeConfig;

/**
 * Unit tests for the client-safe realtime config builder.
 *
 * Verifies each transport produces the values pramnos-realtime.js expects, that
 * SSE is the default, and — critically — that no server secret (Pusher
 * app_secret, Redis password) ever appears in client output.
 */
#[CoversClass(RealtimeConfig::class)]
class RealtimeConfigTest extends TestCase
{
    public function testDefaultsToSse(): void
    {
        $this->assertSame(
            ['transport' => 'sse', 'url' => '/api/stream'],
            RealtimeConfig::forClient([])
        );
    }

    public function testSseUsesConfiguredUrl(): void
    {
        $out = RealtimeConfig::forClient(['transport' => 'sse', 'sse' => ['url' => '/events']]);
        $this->assertSame('/events', $out['url']);
    }

    public function testWebsocketConfig(): void
    {
        $out = RealtimeConfig::forClient([
            'transport' => 'websocket',
            'websocket' => ['scheme' => 'wss', 'host' => 'rt.example.com', 'port' => 443, 'app_key' => 'k1'],
        ]);
        $this->assertSame(
            [
                'transport' => 'websocket', 'scheme' => 'wss', 'host' => 'rt.example.com',
                'port' => 443, 'appKey' => 'k1',
                'fallback' => ['transport' => 'sse', 'url' => '/api/stream'],
            ],
            $out
        );
    }

    /** A socket transport advertises the (optionally configured) SSE fallback. */
    public function testWebsocketAdvertisesConfiguredSseFallback(): void
    {
        $out = RealtimeConfig::forClient([
            'transport' => 'websocket',
            'websocket' => ['host' => 'rt.example.com'],
            'sse'       => ['url' => '/api/stream?mode=fallback'],
        ]);
        $this->assertSame(
            ['transport' => 'sse', 'url' => '/api/stream?mode=fallback'],
            $out['fallback']
        );
    }

    /** Pusher carries the same SSE fallback, and still never leaks the secret. */
    public function testPusherAdvertisesFallback(): void
    {
        $out = RealtimeConfig::forClient([
            'transport' => 'pusher',
            'pusher'    => ['app_key' => 'pub', 'app_secret' => 'SECRET'],
        ]);
        $this->assertSame(['transport' => 'sse', 'url' => '/api/stream'], $out['fallback']);
        $this->assertNotContains('SECRET', $out);
    }

    /** SSE is the terminal transport — it carries no fallback key. */
    public function testSseHasNoFallback(): void
    {
        $this->assertArrayNotHasKey('fallback', RealtimeConfig::forClient([]));
    }

    public function testPusherConfigExposesKeyButNeverSecret(): void
    {
        $out = RealtimeConfig::forClient([
            'transport' => 'pusher',
            'pusher'    => ['app_key' => 'pub-key', 'app_secret' => 'TOP-SECRET', 'cluster' => 'eu', 'scheme' => 'https'],
        ]);

        $this->assertSame('pusher', $out['transport']);
        $this->assertSame('pub-key', $out['key']);
        $this->assertSame('eu', $out['cluster']);
        $this->assertTrue($out['forceTLS']);
        $this->assertNotContains('TOP-SECRET', $out, 'the app secret must never reach the client');
        $this->assertArrayNotHasKey('app_secret', $out);
    }
}
