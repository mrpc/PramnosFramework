<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Http\Client;

/**
 * Skills published as folders in GitHub repositories — `<path>/SKILL.md` with its `references/` and
 * `evals/` — read through GitHub's API at one commit, so what is taken is exactly what was read.
 *
 * Only text a skill is made of is taken: `SKILL.md`, `references/*.md|*.txt` and `evals/*.json`.
 * Scripts and everything else a published skill carries are listed as left out and never written,
 * let alone run. A token in `GITHUB_TOKEN` raises the API's limit; none is needed for a public repo.
 */
class SkillCatalog
{
    /** What may be taken, relative to the skill's folder. */
    public const TAKEN = '#^(SKILL\.md|references/[^/]+\.(md|txt)|evals/[^/]+\.json)$#';

    /** The largest file taken, in bytes. */
    public const FILE_MAX = 262144;

    /** The most files one skill may bring. */
    public const FILES_MAX = 200;

    /** The catalogues searched when the application names none. */
    public const DEFAULT_CATALOGUES = ['coreyhaines31/marketingskills', 'anthropics/knowledge-work-plugins'];

    /**
     * A source as `skill:add` takes it — `owner/repo/path/to/skill[@ref]`.
     *
     * @return array{owner: string, repo: string, path: string, ref: string}
     * @throws \InvalidArgumentException For one that is not
     */
    public static function parse(string $source): array
    {
        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/([A-Za-z0-9_./-]+?)/?(?:@([A-Za-z0-9_./-]+))?$#', trim($source), $m) !== 1 || str_contains($m[3], '..')) {
            throw new \InvalidArgumentException('A skill source is owner/repo/path/to/skill, with @commit or @branch if wanted: ' . $source);
        }

        return ['owner' => $m[1], 'repo' => $m[2], 'path' => trim($m[3], '/'), 'ref' => $m[4] ?? 'HEAD'];
    }

    /** The commit a ref names. */
    public function commit(string $owner, string $repo, string $ref = 'HEAD'): string
    {
        $sha = (string) ($this->api('/repos/' . $owner . '/' . $repo . '/commits/' . rawurlencode($ref))['sha'] ?? '');
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \RuntimeException('GitHub did not name a commit for ' . $owner . '/' . $repo . '@' . $ref . '.');
        }

        return $sha;
    }

    /** The last commit that touched a path, on the default branch. */
    public function lastCommitFor(string $owner, string $repo, string $path): string
    {
        $list = $this->api('/repos/' . $owner . '/' . $repo . '/commits?per_page=1&path=' . rawurlencode($path));

        return (string) ($list[0]['sha'] ?? '');
    }

    /** The repository's licence, as its SPDX id; '' when GitHub names none. */
    public function licence(string $owner, string $repo): string
    {
        try {
            return (string) ($this->api('/repos/' . $owner . '/' . $repo . '/license')['license']['spdx_id'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Every file path in a repository at a commit.
     *
     * @return list<string>
     */
    public function tree(string $owner, string $repo, string $commit): array
    {
        $tree = $this->api('/repos/' . $owner . '/' . $repo . '/git/trees/' . $commit . '?recursive=1');

        return array_values(array_map(
            static fn (array $item): string => (string) $item['path'],
            array_filter((array) ($tree['tree'] ?? []), static fn ($item): bool => is_array($item) && ($item['type'] ?? '') === 'blob')
        ));
    }

    /**
     * A skill's folder at a commit: the files taken, by path relative to the folder, and those left out.
     *
     * @return array{files: array<string, string>, left_out: list<string>}
     */
    public function fetch(string $owner, string $repo, string $path, string $commit): array
    {
        $prefix = $path . '/';
        $files  = [];
        $left   = [];
        foreach ($this->tree($owner, $repo, $commit) as $file) {
            if (!str_starts_with($file, $prefix)) {
                continue;
            }
            $relative = substr($file, strlen($prefix));
            if (preg_match(self::TAKEN, $relative) !== 1 || count($files) >= self::FILES_MAX) {
                $left[] = $relative;
                continue;
            }
            $body = $this->raw($owner, $repo, $commit, $file);
            if (strlen($body) > self::FILE_MAX) {
                $left[] = $relative . ' (over ' . self::FILE_MAX . ' bytes)';
                continue;
            }
            $files[$relative] = $body;
        }
        if (!isset($files['SKILL.md'])) {
            throw new \RuntimeException('There is no SKILL.md at ' . $owner . '/' . $repo . '/' . $path . '.');
        }
        ksort($files);

        return ['files' => $files, 'left_out' => $left];
    }

    /**
     * The skills of a catalogue whose folder path holds a word, as sources `skill:add` takes.
     *
     * @return list<string>
     */
    public function search(string $catalogue, string $word): array
    {
        [$owner, $repo] = array_pad(explode('/', $catalogue, 2), 2, '');
        $found = [];
        foreach ($this->tree($owner, $repo, $this->commit($owner, $repo)) as $file) {
            if (str_ends_with($file, '/SKILL.md') && ($word === '' || stripos(dirname($file), $word) !== false)) {
                $found[] = $owner . '/' . $repo . '/' . dirname($file);
            }
        }

        return $found;
    }

    /** The bytes of one file at a commit. */
    protected function raw(string $owner, string $repo, string $commit, string $file): string
    {
        $response = $this->client()->make('GET', 'https://raw.githubusercontent.com/' . $owner . '/' . $repo . '/' . $commit . '/' . implode('/', array_map('rawurlencode', explode('/', $file))))->send();
        if (!$response->successful()) {
            throw new \RuntimeException('GitHub answered ' . $response->status() . ' for ' . $file . '.');
        }

        return $response->body();
    }

    /**
     * One call to GitHub's API, decoded.
     *
     * @return array<mixed>
     */
    protected function api(string $path): array
    {
        $request = $this->client()->make('GET', 'https://api.github.com' . $path)
            ->header('Accept', 'application/vnd.github+json')->header('User-Agent', 'pramnos-skills');
        $token = getenv('GITHUB_TOKEN');
        if (is_string($token) && $token !== '') {
            $request = $request->header('Authorization', 'Bearer ' . $token);
        }
        $response = $request->send();
        if (!$response->successful()) {
            throw new \RuntimeException('GitHub answered ' . $response->status() . ' for ' . $path . '.');
        }
        $data = json_decode($response->body(), true);

        return is_array($data) ? $data : [];
    }

    /** The HTTP client. */
    protected function client(): Client
    {
        return new Client();
    }
}
