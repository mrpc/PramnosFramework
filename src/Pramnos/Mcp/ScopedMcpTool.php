<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * A tool that may be served to a caller outside this machine.
 *
 * The internal MCP server exposes twenty tools that read coverage reports, run the code-style
 * checker and search the framework's own documentation. Every one of them is written for somebody
 * who already has a shell on the box, and none should ever answer an authenticated stranger.
 *
 * Which is why public exposure is an **opt-in expressed by type** rather than a flag on a list.
 * A registry of names to exclude is a list somebody forgets to add to, and the failure is silent
 * and remote: a developer adds a twenty-first tool, and it is reachable over HTTP before anybody
 * notices it is there. A tool that does not implement this interface is not servable publicly,
 * and cannot be made so by editing a configuration file.
 *
 * ### The scope is the whole of the authorization
 *
 * {@see requiredScope()} names the OAuth scope a caller's token must carry. It is checked when
 * the tool list is built **and again** when a call arrives — never once. Filtering a list is a
 * courtesy to the client; refusing the call is the security boundary, and a client that never
 * read the list still reaches `tools/call`.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
interface ScopedMcpTool extends McpToolInterface
{
    /**
     * The OAuth scope a token must hold to see or call this tool.
     *
     * One scope, not a list. A tool that needs two unrelated permissions is two tools, and a tool
     * that accepts either of two is a tool whose author has not decided what it does.
     */
    public function requiredScope(): string;
}
