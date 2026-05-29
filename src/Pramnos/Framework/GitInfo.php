<?php

declare(strict_types=1);

namespace Pramnos\Framework;

/**
 * Reads git repository information directly from .git/ — no exec() or shell_exec().
 *
 * The class resolves HEAD → ref → commit hash and parses the zlib-compressed
 * commit object file to extract subject, author, and timestamp.  Branch and
 * remote lists are built by scanning refs/heads/, refs/remotes/ and the
 * packed-refs file so both loose and packed refs are covered.
 *
 */
class GitInfo
{
    private readonly string $gitDir;

    /** Cached parsed commit data for the current HEAD. */
    private ?array $commitData = null;

    /**
     * @param string|null $repoRoot Absolute path to the repository root.
     *                              Defaults to the framework root (3 levels up from this file).
     */
    public function __construct(?string $repoRoot = null)
    {
        $root         = $repoRoot ?? dirname(__DIR__, 3);
        $this->gitDir = rtrim($root, '/') . '/.git';
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Returns the name of the current branch, or a detached-HEAD short hash.
     */
    public function getBranch(): string
    {
        $head = $this->readFile('HEAD');
        if ($head === null) {
            return '(unknown)';
        }
        if (str_starts_with($head, 'ref: refs/heads/')) {
            return trim(substr($head, strlen('ref: refs/heads/')));
        }
        // Detached HEAD — return short hash
        return substr(trim($head), 0, 7);
    }

    /**
     * Returns the full 40-character commit hash of HEAD, or null when
     * the repository state cannot be resolved.
     */
    public function getHash(): ?string
    {
        return $this->resolveHead();
    }

    /**
     * Returns the first 7 characters of the HEAD commit hash.
     */
    public function getShortHash(): string
    {
        $hash = $this->resolveHead();
        return $hash !== null ? substr($hash, 0, 7) : '0000000';
    }

    /**
     * Returns the first line of the HEAD commit message (the "subject").
     */
    public function getSubject(): string
    {
        return $this->commitField('subject') ?? '';
    }

    /**
     * Returns the author name (without email) of the HEAD commit.
     */
    public function getAuthor(): string
    {
        return $this->commitField('author_name') ?? '';
    }

    /**
     * Returns the author date of the HEAD commit as a Unix timestamp,
     * or null when the date cannot be determined.
     */
    public function getDate(): ?int
    {
        $ts = $this->commitField('timestamp');
        return $ts !== null ? (int) $ts : null;
    }

    /**
     * Returns all local branch names sorted alphabetically.
     *
     * @return string[]
     */
    public function getLocalBranches(): array
    {
        $branches = [];

        // Loose refs
        $dir = $this->gitDir . '/refs/heads';
        if (is_dir($dir)) {
            $branches = array_merge($branches, $this->scanRefDir($dir, 'refs/heads/'));
        }

        // Packed refs
        foreach ($this->parsePackedRefs() as $ref => $_hash) {
            if (str_starts_with($ref, 'refs/heads/')) {
                $branches[] = substr($ref, strlen('refs/heads/'));
            }
        }

        $branches = array_unique($branches);
        sort($branches);
        return $branches;
    }

    /**
     * Returns remote names parsed from .git/config.
     *
     * @return string[] Remote names, e.g. ['origin'].
     */
    public function getRemotes(): array
    {
        $config = $this->readFile('config');
        if ($config === null) {
            return [];
        }

        $remotes = [];
        foreach (explode("\n", $config) as $line) {
            if (preg_match('/^\[remote "([^"]+)"\]/', trim($line), $m)) {
                $remotes[] = $m[1];
            }
        }
        return array_unique($remotes);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Resolves HEAD to a 40-char commit hash, following symbolic refs and
     * falling back to packed-refs when loose ref files are absent.
     */
    private function resolveHead(): ?string
    {
        $head = $this->readFile('HEAD');
        if ($head === null) {
            return null;
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref: ')) {
            $refPath = substr($head, 5); // e.g. refs/heads/v1.2-dev
            return $this->resolveRef($refPath);
        }

        // Detached HEAD — already a hash
        return strlen($head) === 40 ? $head : null;
    }

    /**
     * Resolves a ref name (e.g. refs/heads/main) to its hash.
     */
    private function resolveRef(string $ref): ?string
    {
        // Try loose ref file first
        $contents = $this->readFile($ref);
        if ($contents !== null) {
            return trim($contents) ?: null;
        }

        // Fall back to packed-refs
        $packed = $this->parsePackedRefs();
        return $packed[$ref] ?? null;
    }

    /**
     * Parses .git/packed-refs into an array of ref → hash.
     *
     * @return array<string, string>
     */
    private function parsePackedRefs(): array
    {
        $contents = $this->readFile('packed-refs');
        if ($contents === null) {
            return [];
        }

        $result = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                continue;
            }
            if (preg_match('/^([0-9a-f]{40})\s+(\S+)$/', $line, $m)) {
                $result[$m[2]] = $m[1];
            }
        }
        return $result;
    }

    /**
     * Returns a named field from the parsed HEAD commit object.
     *
     * @param 'subject'|'author_name'|'timestamp' $field
     */
    private function commitField(string $field): ?string
    {
        if ($this->commitData === null) {
            $this->commitData = $this->parseCommit() ?? [];
        }
        return $this->commitData[$field] ?? null;
    }

    /**
     * Reads and parses the commit object for HEAD.
     *
     * Returns an array with keys: subject, author_name, timestamp.
     * Returns null when the object cannot be found or decompressed.
     *
     * @return array{subject: string, author_name: string, timestamp: string}|null
     */
    private function parseCommit(): ?array
    {
        $hash = $this->resolveHead();
        if ($hash === null || strlen($hash) < 40) {
            return null;
        }

        $objPath = sprintf('%s/objects/%s/%s', $this->gitDir, substr($hash, 0, 2), substr($hash, 2));
        if (!is_file($objPath)) {
            return null;
        }

        $raw       = file_get_contents($objPath);
        $decompressed = @gzuncompress($raw);
        if ($decompressed === false) {
            return null;
        }

        // Format: "commit SIZE\0<headers>\n\n<message>"
        $nul = strpos($decompressed, "\0");
        if ($nul === false) {
            return null;
        }
        $body = substr($decompressed, $nul + 1);

        $parts = explode("\n\n", $body, 2);
        $headers = $parts[0] ?? '';
        $message = isset($parts[1]) ? trim($parts[1]) : '';
        $subject = explode("\n", $message)[0];

        $authorName  = '';
        $timestamp   = '';

        foreach (explode("\n", $headers) as $line) {
            // author NAME <email> TIMESTAMP TIMEZONE
            if (str_starts_with($line, 'author ')) {
                $rest = substr($line, 7);
                // Extract timestamp: last two tokens are TIMESTAMP TIMEZONE
                if (preg_match('/^(.*?)\s+<[^>]*>\s+(\d+)\s+[+-]\d{4}$/', $rest, $m)) {
                    $authorName = $m[1];
                    $timestamp  = $m[2];
                }
            }
        }

        return [
            'subject'     => $subject,
            'author_name' => $authorName,
            'timestamp'   => $timestamp,
        ];
    }

    /**
     * Recursively scans a ref directory and returns branch/tag names relative
     * to the given prefix.
     *
     * @return string[]
     */
    private function scanRefDir(string $dir, string $stripPrefix): array
    {
        $result = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    // Build ref name from relative path
                    $rel  = str_replace('\\', '/', substr($file->getPathname(), strlen($this->gitDir) + 1));
                    $name = substr($rel, strlen($stripPrefix));
                    $result[] = $name;
                }
            }
        } catch (\Exception) {
            // Directory not readable — silently skip
        }
        return $result;
    }

    /**
     * Reads a file inside the .git directory and returns its contents,
     * or null when the file does not exist or is not readable.
     */
    private function readFile(string $relativePath): ?string
    {
        $path     = $this->gitDir . '/' . $relativePath;
        $contents = is_file($path) ? @file_get_contents($path) : false;
        return $contents !== false ? $contents : null;
    }
}
