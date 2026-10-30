<?php

/**
 * A small MCP server over stdio, for the client's tests: newline-delimited JSON-RPC.
 *
 * Tools: `echo` (returns its arguments, read-only), `fail` (an error result), `slow` (answers
 * after two seconds), `quit` (exits without answering). `tools/list` comes in two pages. Before
 * answering a call it pings the client and sends a notification, as a real server may. It writes
 * to stderr too, which the client must not read as output. `MCP_GREETING` in the environment is
 * returned by `echo`, to show the environment reached it. It starts with a line that is not JSON,
 * as some servers print a banner.
 */

$out = static function (array $message): void {
    fwrite(STDOUT, json_encode($message) . "\n");
    fflush(STDOUT);
};

// Some servers print a banner on stdout before speaking JSON-RPC: the client skips it.
fwrite(STDOUT, "fixture server starting\n");
fflush(STDOUT);

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if (!is_array($message) || !isset($message['id'])) {
        continue;                                   // notifications, the client's answers to our ping
    }
    fwrite(STDERR, "log line\n");
    $id = $message['id'];

    switch ($message['method'] ?? '') {
        case 'initialize':
            $out(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => $message['params']['protocolVersion'], 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'fixture', 'version' => '1']]]);
            break;
        case 'tools/list':
            $out(['jsonrpc' => '2.0', 'id' => $id, 'result' => empty($message['params']['cursor'])
                ? ['tools' => [['name' => 'echo', 'description' => 'Says it back', 'inputSchema' => ['type' => 'object', 'properties' => ['say' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]]], 'nextCursor' => 'p2']
                : ['tools' => [['name' => 'fail', 'description' => 'Fails', 'inputSchema' => ['type' => 'object']], ['name' => 'slow', 'inputSchema' => ['type' => 'object']], ['name' => 'quit', 'inputSchema' => ['type' => 'object']]]]]);
            break;
        case 'tools/call':
            $out(['jsonrpc' => '2.0', 'id' => 'ping-1', 'method' => 'ping']);
            $out(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'working']]);
            $name = $message['params']['name'];
            if ($name === 'quit') {
                exit(0);
            }
            if ($name === 'slow') {
                sleep(2);
            }
            $out(['jsonrpc' => '2.0', 'id' => $id, 'result' => $name === 'fail'
                ? ['content' => [['type' => 'text', 'text' => 'no such record']], 'isError' => true]
                : ['content' => [['type' => 'text', 'text' => json_encode($message['params']['arguments']) . ' ' . getenv('MCP_GREETING')]]]]);
            break;
        default:
            $out(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}
