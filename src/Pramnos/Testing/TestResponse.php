<?php

namespace Pramnos\Testing;

use Pramnos\Http\Response;
use Symfony\Component\DomCrawler\Crawler;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Wrapper around the HTTP Response to provide fluent testing assertions.
 */
class TestResponse
{
    private ?Crawler $crawler = null;

    public function __construct(
        private Response $response
    ) {}

    /**
     * Get the underlying Response object.
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Assert that the response has a successful status code (200-299).
     */
    public function assertSuccessful(): static
    {
        $status = $this->response->getStatusCode();
        PHPUnit::assertTrue(
            $status >= 200 && $status < 300,
            "Expected successful status code, but received {$status}."
        );
        return $this;
    }

    /**
     * Assert that the response has a specific status code.
     */
    public function assertStatus(int $code): static
    {
        $status = $this->response->getStatusCode();
        PHPUnit::assertSame(
            $code,
            $status,
            "Expected status code {$code}, but received {$status}."
        );
        return $this;
    }

    /**
     * Assert that the response body contains the given string.
     */
    public function assertSee(string $string): static
    {
        PHPUnit::assertStringContainsString(
            $string,
            $this->response->getBody(),
            "Expected response to contain '{$string}' but it did not."
        );
        return $this;
    }

    /**
     * Assert that the response body does not contain the given string.
     */
    public function assertDontSee(string $string): static
    {
        PHPUnit::assertStringNotContainsString(
            $string,
            $this->response->getBody(),
            "Expected response not to contain '{$string}' but it did."
        );
        return $this;
    }

    /**
     * Assert that the response body (with HTML tags stripped) contains the given string.
     */
    public function assertSeeText(string $string): static
    {
        $text = strip_tags($this->response->getBody());
        PHPUnit::assertStringContainsString(
            $string,
            $text,
            "Expected response text to contain '{$string}' but it did not."
        );
        return $this;
    }

    /**
     * Assert that the response is JSON and contains the given array as a subset.
     */
    public function assertJson(array $data): static
    {
        $decoded = json_decode($this->response->getBody(), true);
        PHPUnit::assertIsArray(
            $decoded,
            'Failed to decode response as JSON.'
        );

        foreach ($data as $key => $value) {
            PHPUnit::assertArrayHasKey($key, $decoded);
            PHPUnit::assertEquals($value, $decoded[$key]);
        }

        return $this;
    }

    /**
     * Assert that the JSON response contains the given value at the specified dot-notated path.
     */
    public function assertJsonPath(string $path, mixed $expectedValue): static
    {
        $decoded = json_decode($this->response->getBody(), true);
        PHPUnit::assertIsArray(
            $decoded,
            'Failed to decode response as JSON.'
        );

        $keys = explode('.', $path);
        $current = $decoded;

        foreach ($keys as $key) {
            PHPUnit::assertIsArray($current, "Path '{$path}' not found in JSON (stopped at '{$key}').");
            PHPUnit::assertArrayHasKey($key, $current, "Path '{$path}' not found in JSON (missing '{$key}').");
            $current = $current[$key];
        }

        PHPUnit::assertEquals($expectedValue, $current);

        return $this;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DOM Assertions (requires symfony/dom-crawler and symfony/css-selector)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get the DomCrawler instance for the response body.
     */
    private function getCrawler(): Crawler
    {
        if ($this->crawler === null) {
            $this->crawler = new Crawler($this->response->getBody());
        }
        return $this->crawler;
    }

    /**
     * Assert that a specific CSS selector exists in the DOM.
     */
    public function assertSelectorExists(string $selector): static
    {
        $crawler = $this->getCrawler();
        $count = $crawler->filter($selector)->count();
        PHPUnit::assertGreaterThan(
            0,
            $count,
            "Expected to find CSS selector '{$selector}' but none found."
        );
        return $this;
    }

    /**
     * Assert that a specific CSS selector exists and contains the given text.
     */
    public function assertSelectorContains(string $selector, string $text): static
    {
        $crawler = $this->getCrawler();
        $node = $crawler->filter($selector);
        
        PHPUnit::assertGreaterThan(
            0,
            $node->count(),
            "Expected to find CSS selector '{$selector}' but none found."
        );

        PHPUnit::assertStringContainsString(
            $text,
            $node->text(),
            "Expected selector '{$selector}' to contain '{$text}'."
        );

        return $this;
    }

    /**
     * Assert that a specific CSS selector exists and has the given attribute value.
     */
    public function assertSelectorAttribute(string $selector, string $attribute, string $value): static
    {
        $crawler = $this->getCrawler();
        $node = $crawler->filter($selector);
        
        PHPUnit::assertGreaterThan(
            0,
            $node->count(),
            "Expected to find CSS selector '{$selector}' but none found."
        );

        $attrValue = $node->attr($attribute);
        
        PHPUnit::assertSame(
            $value,
            $attrValue,
            "Expected selector '{$selector}' to have attribute '{$attribute}'='{$value}', but found '{$attrValue}'."
        );

        return $this;
    }
}
