<?php

declare(strict_types=1);

namespace Pramnos\Webhook;

/**
 * Git webhook handler.
 *
 * Receives POST requests from GitHub or Bitbucket, verifies the HMAC
 * signature, extracts the pushed branch, and executes the configured
 * command sequence for that branch.
 *
 * ## Quickstart
 *
 * ```php
 * // www/webhook.php
 * $handler = new \Pramnos\Webhook\WebhookHandler(
 *     secret:     $_ENV['WEBHOOK_SECRET'],
 *     repoDir:    ROOT,
 *     logChannel: 'webhook',
 * );
 * $handler->onBranch('main',    ['git fetch --all', 'git reset --hard origin/main']);
 * $handler->onBranch('develop', ['git fetch --all', 'git reset --hard origin/develop']);
 * $handler->handle();
 * ```
 *
 * ## Security
 *
 * The `secret` constructor argument **must** be set.  If the secret is empty the
 * handler throws an `\InvalidArgumentException` so a misconfigured deploy script
 * fails loudly rather than silently accepting unsigned payloads.
 *
 * Signature verification uses `hash_equals()` (timing-safe comparison) against:
 *   - `X-Hub-Signature-256` — GitHub, SHA-256 (preferred)
 *   - `X-Hub-Signature`     — Bitbucket / GitHub SHA-1 legacy fallback
 *
 * ## Supported events
 *
 * | Event | GitHub header value | Action |
 * |---|---|---|
 * | push | `push` | Execute configured branch commands |
 * | release (published) | `release` | Execute `main` branch commands if mapped |
 * | workflow_run (completed success) | `workflow_run` | Execute head branch commands if mapped |
 * | Other events | * | Respond 204 No Content (silently ignored) |
 *
 */
class WebhookHandler
{
    /** Branch-to-commands map. Key = branch name, value = ordered command list. */
    private array $branchMap = [];

    /**
     * @param string $secret     HMAC secret configured in the webhook provider.
     *                           Must not be empty — constructor throws if it is.
     * @param string $repoDir    Working directory for command execution.
     *                           Defaults to cwd().
     * @param string $logChannel Log channel name for Pramnos\Logs (empty = no logging).
     * @param int    $timeout    Per-command execution timeout in seconds.
     */
    public function __construct(
        private readonly string $secret,
        private string $repoDir = '',
        private readonly string $logChannel = 'webhook',
        private readonly int $timeout = 120,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException(
                'WebhookHandler: secret must not be empty — set WEBHOOK_SECRET in .env'
            );
        }

        if ($this->repoDir === '') {
            $this->repoDir = getcwd() ?: '/';
        }
    }

    /**
     * Register a command sequence for a branch.
     *
     * ```php
     * $handler->onBranch('main', [
     *     'git fetch --all',
     *     'git reset --hard origin/main',
     *     'composer install --no-dev --optimize-autoloader',
     *     'php pramnos migrate',
     * ]);
     * ```
     *
     * Calling `onBranch()` twice for the same branch replaces the previous mapping.
     *
     * @param string   $branch   Exact branch name (e.g. 'main', 'develop', 'staging').
     * @param string[] $commands Shell commands to execute in order.
     * @return static  Fluent interface.
     */
    public function onBranch(string $branch, array $commands): static
    {
        $this->branchMap[$branch] = $commands;
        return $this;
    }

    /**
     * Process an incoming webhook request and exit.
     *
     * Reads `php://input` (or accepts a pre-parsed payload for testing),
     * verifies the HMAC signature, determines the event type and branch,
     * and executes the registered commands.
     *
     * Exits with the appropriate HTTP response:
     *   200  — commands executed; body = `{status, branch, commands_run, output}`
     *   204  — event is not a push/release/workflow_run; no body
     *   403  — signature invalid or missing
     *   500  — command execution error
     *
     * @param string|null      $rawBody   Raw request body; defaults to `php://input`.
     * @param array|null       $headers   Request headers; defaults to `getallheaders()`.
     */
    public function handle(?string $rawBody = null, ?array $headers = null): never
    {
        $body    = $rawBody  ?? (string) file_get_contents('php://input');
        $headers = $headers  ?? $this->getRequestHeaders();

        // Normalise header names to lowercase for provider-agnostic matching
        $headers = array_change_key_case($headers, CASE_LOWER);

        // ── 1. Verify HMAC ────────────────────────────────────────────────────
        if (!$this->verifySignature($body, $headers)) {
            $this->respond(403, ['status' => 'forbidden', 'error' => 'Invalid or missing signature']);
        }

        // ── 2. Decode payload ─────────────────────────────────────────────────
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $this->respond(400, ['status' => 'error', 'error' => 'Invalid JSON payload']);
        }

        // ── 3. Detect event ───────────────────────────────────────────────────
        $event = $this->detectEvent($payload, $headers);

        if ($event === 'ignored') {
            $this->respond(204, []);
        }

        // ── 4. Extract branch ─────────────────────────────────────────────────
        $branch = $this->detectBranch($payload, $event);

        if ($branch === null || !isset($this->branchMap[$branch])) {
            // Unknown / unmapped branch — silently ignore
            $this->respond(204, []);
        }

        // ── 5. Execute commands ───────────────────────────────────────────────
        $start   = microtime(true);
        $results = $this->executeCommands($this->branchMap[$branch]);
        $elapsed = round((microtime(true) - $start) * 1000);

        $failed = array_filter($results, fn($r) => $r['exit_code'] !== 0);

        $this->log('info', "Webhook deploy: branch={$branch} commands={$results} elapsed={$elapsed}ms");

        if (!empty($failed)) {
            $this->log('error', "Webhook deploy failed on branch={$branch}: " . json_encode($failed));
            $this->respond(500, [
                'status'       => 'error',
                'branch'       => $branch,
                'commands_run' => count($results),
                'failed'       => array_values($failed),
            ]);
        }

        $this->respond(200, [
            'status'       => 'ok',
            'branch'       => $branch,
            'commands_run' => count($results),
            'elapsed_ms'   => $elapsed,
        ]);
    }

    // =========================================================================
    // Public accessors (for testing / inspection)
    // =========================================================================

    /**
     * Returns the registered branch-to-commands map.
     *
     * @return array<string, string[]>
     */
    public function getBranchMap(): array
    {
        return $this->branchMap;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Verifies the HMAC signature from the incoming request headers.
     *
     * Checks `x-hub-signature-256` (SHA-256, preferred) first, then falls back
     * to `x-hub-signature` (SHA-1, Bitbucket / legacy GitHub).
     *
     * @param string $body    Raw request body.
     * @param array  $headers Lowercase-keyed headers.
     */
    private function verifySignature(string $body, array $headers): bool
    {
        // SHA-256 (GitHub modern)
        if (isset($headers['x-hub-signature-256'])) {
            $expected = 'sha256=' . hash_hmac('sha256', $body, $this->secret);
            return hash_equals($expected, $headers['x-hub-signature-256']);
        }

        // SHA-1 (Bitbucket, GitHub legacy)
        if (isset($headers['x-hub-signature'])) {
            $expected = 'sha1=' . hash_hmac('sha1', $body, $this->secret);
            return hash_equals($expected, $headers['x-hub-signature']);
        }

        return false;
    }

    /**
     * Determines the event type from the payload and headers.
     *
     * Returns one of: 'push', 'release', 'workflow_run', 'ignored'.
     *
     * @param array $payload  Decoded JSON payload.
     * @param array $headers  Lowercase-keyed headers.
     */
    private function detectEvent(array $payload, array $headers): string
    {
        $githubEvent = $headers['x-github-event'] ?? '';

        // GitHub push
        if ($githubEvent === 'push') {
            return 'push';
        }

        // GitHub release (published only)
        if ($githubEvent === 'release') {
            return ($payload['action'] ?? '') === 'published' ? 'release' : 'ignored';
        }

        // GitHub Actions workflow_run (completed + success only)
        if ($githubEvent === 'workflow_run') {
            $run = $payload['workflow_run'] ?? [];
            return ($run['status'] ?? '') === 'completed' && ($run['conclusion'] ?? '') === 'success'
                ? 'workflow_run'
                : 'ignored';
        }

        // Bitbucket push — no event header, but payload has 'push' key
        if (isset($payload['push']['changes'])) {
            return 'push';
        }

        return 'ignored';
    }

    /**
     * Extracts the branch name from the payload for a given event type.
     *
     * Returns null when the branch cannot be determined (e.g. tag push).
     *
     * @param array  $payload Decoded JSON payload.
     * @param string $event   Event type from detectEvent().
     */
    private function detectBranch(array $payload, string $event): ?string
    {
        switch ($event) {
            case 'push':
                // GitHub: ref = refs/heads/<branch>
                if (isset($payload['ref'])) {
                    $ref = $payload['ref'];
                    if (str_starts_with($ref, 'refs/heads/')) {
                        return substr($ref, strlen('refs/heads/'));
                    }
                    // Tag push — ignore
                    return null;
                }
                // Bitbucket: push.changes[0].new.name
                $changes = $payload['push']['changes'][0] ?? [];
                return $changes['new']['name'] ?? null;

            case 'release':
                // Use target_commitish as proxy for the release branch
                return $payload['release']['target_commitish'] ?? 'main';

            case 'workflow_run':
                return $payload['workflow_run']['head_branch'] ?? null;

            default:
                return null;
        }
    }

    /**
     * Executes an ordered list of shell commands in the repo directory.
     *
     * Each command runs in isolation. Execution stops on the first non-zero
     * exit code (fail-fast).
     *
     * @param string[] $commands Shell commands to run.
     * @return array<int, array{command: string, exit_code: int, output: string}>
     */
    private function executeCommands(array $commands): array
    {
        $results = [];

        foreach ($commands as $cmd) {
            $output   = [];
            $exitCode = 0;

            // proc_open for timeout support
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes, $this->repoDir, null, [
                'bypass_shell' => false,
            ]);

            if (!is_resource($proc)) {
                $results[] = ['command' => $cmd, 'exit_code' => 1, 'output' => 'proc_open failed'];
                break;
            }

            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($proc);
            $combined = trim(($stdout ?: '') . ($stderr ? "\nSTDERR: {$stderr}" : ''));

            $results[] = ['command' => $cmd, 'exit_code' => $exitCode, 'output' => $combined];

            // Fail-fast: stop on first error
            if ($exitCode !== 0) {
                break;
            }
        }

        return $results;
    }

    /**
     * Sends a JSON response and exits.
     *
     * @param int   $code HTTP status code.
     * @param array $data Response body (encoded as JSON; empty array = no body for 204).
     */
    private function respond(int $code, array $data): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, no-store');

        if ($code !== 204 && !empty($data)) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        exit;
    }

    /**
     * Writes a structured log entry via Pramnos\Logs if logChannel is set.
     */
    private function log(string $level, string $message): void
    {
        if ($this->logChannel === '') {
            return;
        }
        try {
            \Pramnos\Logs\Logs::getInstance()->write($message, $level, $this->logChannel);
        } catch (\Throwable) {
            // Logging is best-effort — never break the webhook response
        }
    }

    /**
     * Returns the current request headers, normalised to lowercase keys.
     *
     * Falls back to `$_SERVER` scanning when `getallheaders()` is unavailable.
     */
    private function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
}
