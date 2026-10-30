<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

/**
 * An MCP server run as a process of its own, spoken to over its standard input and output: one
 * JSON-RPC message a line each way. What it writes to standard error is dropped.
 *
 * Started on the first message and kept until {@see close()}, so the tools of an answer share one
 * process. A request the server makes of the client meanwhile — `ping` — is answered; anything
 * else it says before the response is skipped.
 */
final class StdioTransport implements Transport
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';

    /**
     * @param list<string>          $command The program and its arguments, run without a shell
     * @param array<string, string> $env     Set beside the environment this process has
     */
    public function __construct(private readonly array $command, private readonly array $env = [], private readonly ?string $cwd = null)
    {
    }

    public function request(array $message, int $timeout): array
    {
        $this->write($message);
        $deadline = microtime(true) + $timeout;

        while (true) {
            while (($at = strpos($this->buffer, "\n")) !== false) {
                $line         = trim(substr($this->buffer, 0, $at));
                $this->buffer = substr($this->buffer, $at + 1);
                $answer       = $line === '' ? null : json_decode($line, true);
                if (!is_array($answer)) {
                    continue;
                }
                if (isset($answer['method'], $answer['id'])) {
                    // The server asks something of us: ping is the one a client must answer.
                    $this->write(['jsonrpc' => '2.0', 'id' => $answer['id']] + ($answer['method'] === 'ping'
                        ? ['result' => new \stdClass()]
                        : ['error' => ['code' => -32601, 'message' => 'Not supported']]));
                    continue;
                }
                if (($answer['id'] ?? null) === $message['id'] && (isset($answer['result']) || isset($answer['error']))) {
                    return $answer;
                }
            }

            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new McpException('The MCP server did not answer ' . ($message['method'] ?? '') . ' within ' . $timeout . ' seconds.');
            }
            $read   = [$this->pipes[1]];
            $none   = null;
            $ready  = @stream_select($read, $none, $none, (int) $left, (int) (($left - (int) $left) * 1_000_000));
            if ($ready === false) {
                throw new McpException('The MCP server\'s output could not be read.');
            }
            if ($ready > 0) {
                $chunk = fread($this->pipes[1], 65536);
                if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                    throw new McpException('The MCP server stopped: ' . implode(' ', $this->command));
                }
                $this->buffer .= $chunk;
            }
        }
    }

    public function notify(array $message): void
    {
        $this->write($message);
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        // Its input closed is the server's sign to go; one that does not is stopped.
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        [$this->process, $this->pipes, $this->buffer] = [null, [], ''];
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param array<string, mixed> $message */
    private function write(array $message): void
    {
        $this->start();
        $line = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (@fwrite($this->pipes[0], $line) === false) {
            throw new McpException('The MCP server stopped: ' . implode(' ', $this->command));
        }
        fflush($this->pipes[0]);
    }

    private function start(): void
    {
        if ($this->process !== null) {
            return;
        }
        if ($this->command === []) {
            throw new McpException('An MCP server needs a command to run.');
        }

        $env     = $this->env === [] ? null : array_merge(getenv(), $this->env);
        $process = @proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, $this->cwd, $env);
        if (!is_resource($process)) {
            throw new McpException('The MCP server could not be started: ' . implode(' ', $this->command));
        }
        stream_set_blocking($pipes[1], false);
        [$this->process, $this->pipes] = [$process, $pipes];
    }
}
