<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scaffolding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cache screen marks an entry past its timeout as expired, in every bundled theme.
 *
 * A cache browser that hides or disguises expired entries cannot answer the question it is
 * usually opened for — why a value is being recomputed every time. The adapter half is
 * `FileAdapterTest::testAnEntryPastItsTimeoutIsListedAsExpired`; this is the half that shows it.
 * An application kept the only end-to-end test of it, which could run only on the file adapter
 * and was skipped on every run of a server on Redis.
 */
class DashboardCacheViewTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /**
     * An expired entry and a live one, as `FileAdapter::getAllItems()` lists them.
     */
    #[DataProvider('themes')]
    public function testAnExpiredEntryIsMarkedExpiredAndALiveOneIsNot(string $theme): void
    {
        // Arrange
        $view = dirname(__DIR__, 3) . "/scaffolding/themes/{$theme}/views/dashboard/cache.html.php";
        $this->assertFileExists($view);
        $data = (object) [
            'cacheStatus' => true,
            'cacheStats'  => ['method' => 'file', 'categories' => 1, 'items' => 2],
            'cacheItems'  => [
                ['key' => 'stale_key', 'namespace' => 'app', 'size' => 10, 'created_time' => '2026-09-28 10:00:00',
                 'ttl' => -3540, 'type' => 'string', 'expired' => true],
                ['key' => 'live_key', 'namespace' => 'app', 'size' => 10, 'created_time' => '2026-09-29 10:00:00',
                 'ttl' => 3500, 'type' => 'string', 'expired' => false],
            ],
        ];

        // Act
        $html = $this->render($view, $data);

        // Assert — one row each, and "Expired" belongs to the stale one only
        $rows = $this->rowsByKey($html);
        $this->assertArrayHasKey('stale_key', $rows, 'the expired entry must be listed');
        $this->assertArrayHasKey('live_key', $rows);
        $this->assertStringContainsString('Expired', $rows['stale_key']);
        $this->assertStringNotContainsString('Expired', $rows['live_key']);
    }

    /** Render a view with `$this` bound to the data it reads. */
    private function render(string $view, object $data): string
    {
        $render = function (string $__view): void {
            include $__view;
        };

        ob_start();
        try {
            \Closure::bind($render, $data, null)($view);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The table rows of the listing, keyed by the cache key each one shows.
     *
     * @return array<string, string>
     */
    private function rowsByKey(string $html): array
    {
        preg_match_all('#<tr\b.*?</tr>#s', $html, $rows);
        $byKey = [];
        foreach ($rows[0] as $row) {
            foreach (['stale_key', 'live_key'] as $key) {
                if (str_contains($row, $key)) {
                    $byKey[$key] = $row;
                }
            }
        }

        return $byKey;
    }
}
