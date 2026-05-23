<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\McpResource;

/**
 * Unit tests for McpResource — file-backed resource that the MCP server exposes.
 *
 * Verifies that read() correctly returns file content, returns null for missing
 * files, and that toListItem() produces the expected MCP list-item shape.
 */
class McpResourceTest extends TestCase
{
    /**
     * read() must return the file contents when the file exists and is readable.
     */
    public function testReadReturnsFileContents(): void
    {
        // Arrange
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_res_');
        file_put_contents($tmp, 'resource content');
        $resource = new McpResource('file://test', 'Test', $tmp);

        // Act
        $content = $resource->read();

        // Assert
        $this->assertSame('resource content', $content);

        unlink($tmp);
    }

    /**
     * read() must return null when the file path does not exist.
     *
     * The server handles the null case by returning a JSON-RPC error, so the
     * resource itself should not throw.
     */
    public function testReadReturnsNullForMissingFile(): void
    {
        // Arrange
        $resource = new McpResource('file://missing', 'Missing', '/nonexistent/path/file.txt');

        // Act
        $content = $resource->read();

        // Assert
        $this->assertNull($content);
    }

    /**
     * toListItem() must return the correct MCP resource-list shape with uri,
     * name, and mimeType keys.
     */
    public function testToListItemReturnsCorrectShape(): void
    {
        // Arrange
        $resource = new McpResource('file://doc.md', 'Documentation', '/any/path', 'text/markdown');

        // Act
        $item = $resource->toListItem();

        // Assert
        $this->assertSame('file://doc.md', $item['uri']);
        $this->assertSame('Documentation', $item['name']);
        $this->assertSame('text/markdown', $item['mimeType']);
    }

    /**
     * Default mimeType must be 'text/plain' when not specified.
     */
    public function testDefaultMimeTypeIsTextPlain(): void
    {
        // Arrange / Act
        $resource = new McpResource('file://x', 'X', '/path');

        // Assert
        $this->assertSame('text/plain', $resource->mimeType);
    }
}
