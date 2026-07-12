<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * Represents a file-backed resource exposed to the AI assistant.
 *
 * Resources appear in the resources/list response and can be fetched by the
 * AI via resources/read. Typically used for config files, CLAUDE.md, views,
 * or any other read-only file the assistant might need for context.
 *
 */
class McpResource
{
    /**
     * @param string $uri      Unique URI for this resource (e.g. 'file://CLAUDE.md').
     * @param string $name     Human-readable name shown in the resource list.
     * @param string $filePath Absolute path to the file on disk.
     * @param string $mimeType MIME type hint (default: 'text/plain').
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly string $filePath,
        public readonly string $mimeType = 'text/plain',
    ) {}

    /**
     * Read and return the resource contents, or null if the file is missing.
     */
    public function read(): ?string
    {
        if (!is_file($this->filePath) || !is_readable($this->filePath)) {
            return null;
        }
        $content = file_get_contents($this->filePath);
        return $content === false ? null : $content;
    }

    /**
     * Serialize to the MCP resources/list item format.
     *
     * @return array{uri: string, name: string, mimeType: string}
     */
    public function toListItem(): array
    {
        return [
            'uri'      => $this->uri,
            'name'     => $this->name,
            'mimeType' => $this->mimeType,
        ];
    }
}
