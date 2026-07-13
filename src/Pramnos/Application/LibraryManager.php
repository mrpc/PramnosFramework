<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * Resolves and installs front-end vendor libraries from the framework's
 * bundled asset catalog (`scaffolding/assets.json`) into a project's
 * `www/assets/vendor/` tree.
 *
 * This is the shared engine behind both `pramnos init` (initial scaffold) and
 * `pramnos libraries:sync` (top-up an existing project without re-scaffolding).
 * It performs no project mutation beyond writing asset files — registration of
 * libraries in the application bootstrap is the caller's responsibility.
 */
class LibraryManager
{
    /** Absolute path to the framework's bundled scaffolding/ directory. */
    private string $scaffoldingDir;

    /** @var array<string, mixed>|null Lazily loaded asset catalog. */
    private ?array $catalog = null;

    public function __construct(?string $scaffoldingDir = null)
    {
        $this->scaffoldingDir = $scaffoldingDir
            ?? ScaffoldingHelper::resolveScaffoldingDir();
    }

    /**
     * Load (and cache) the asset catalog from scaffolding/assets.json.
     *
     * @return array{libraries: array<string, array<string, mixed>>}
     */
    public function catalog(): array
    {
        if ($this->catalog === null) {
            $file = $this->scaffoldingDir . DIRECTORY_SEPARATOR . 'assets.json';
            $this->catalog = file_exists($file)
                ? (json_decode((string) file_get_contents($file), true) ?: ['libraries' => []])
                : ['libraries' => []];
        }
        return $this->catalog;
    }

    /**
     * Return the catalog definition for a single library, or null if unknown.
     *
     * @return array<string, mixed>|null
     */
    public function libraryDef(string $key): ?array
    {
        return $this->catalog()['libraries'][$key] ?? null;
    }

    /**
     * Return all library keys available in the catalog.
     *
     * @return list<string>
     */
    public function availableKeys(): array
    {
        return array_keys($this->catalog()['libraries']);
    }

    /**
     * Return the keys of libraries flagged `"mandatory": true` in the catalog.
     *
     * This is the single source of truth for mandatory libraries: both
     * `pramnos init` and `pramnos libraries:sync` derive their always-included
     * set from here, so a library becomes mandatory by editing assets.json
     * alone — no code change in two places.
     *
     * @return list<string>
     */
    public function mandatoryKeys(): array
    {
        return static::mandatoryFromCatalog($this->catalog());
    }

    /**
     * Extract mandatory library keys from an already-loaded catalog array.
     * Lets callers that hold their own catalog (e.g. the init command with a
     * custom scaffolding dir) avoid a second file read.
     *
     * @param array{libraries?: array<string, array<string, mixed>>} $catalog
     * @return list<string>
     */
    public static function mandatoryFromCatalog(array $catalog): array
    {
        $keys = [];
        foreach (($catalog['libraries'] ?? []) as $key => $def) {
            if (!empty($def['mandatory'])) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * The expected on-disk relative paths (relative to www/) for a library's
     * asset files — used to decide whether the library is already installed.
     *
     * @param array<string, mixed> $def A catalog library definition.
     * @return list<string>
     */
    public function expectedFiles(array $def): array
    {
        $localPath = (string) ($def['local_path'] ?? '');
        $bundled   = !empty($def['bundled']);
        $files     = [];

        foreach (array_merge($def['css'] ?? [], $def['js'] ?? []) as $entry) {
            // Bundled entries are already bare filenames; CDN entries are URLs.
            $filename = $bundled
                ? basename((string) $entry)
                : basename((string) parse_url((string) $entry, PHP_URL_PATH));
            if ($filename !== '') {
                $files[] = $localPath . '/' . $filename;
            }
        }

        return $files;
    }

    /**
     * Whether every asset file for a library already exists under $wwwDir.
     */
    public function isInstalled(string $key, string $wwwDir): bool
    {
        $def = $this->libraryDef($key);
        if ($def === null) {
            return false;
        }
        $files = $this->expectedFiles($def);
        if ($files === []) {
            return false;
        }
        foreach ($files as $rel) {
            if (!file_exists($wwwDir . DIRECTORY_SEPARATOR . $rel)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Install one library's assets into $wwwDir (the project's www/ directory).
     *
     * Downloads CDN libraries and copies framework-bundled libraries. Skips work
     * when already installed unless $force is true.
     *
     * @param string $key   Catalog library key (e.g. "chartjs").
     * @param string $wwwDir Absolute path to the project's www/ directory.
     * @param bool   $force  Re-fetch even when the files already exist.
     * @return array{status: string, files: list<string>} status is one of
     *         'unknown' | 'present' | 'installed' | 'failed'; files are the
     *         installed relative paths (relative to www/).
     */
    public function install(string $key, string $wwwDir, bool $force = false): array
    {
        $def = $this->libraryDef($key);
        if ($def === null) {
            return ['status' => 'unknown', 'files' => []];
        }

        if (!$force && $this->isInstalled($key, $wwwDir)) {
            return ['status' => 'present', 'files' => $this->expectedFiles($def)];
        }

        $localPath = (string) ($def['local_path'] ?? '');
        $destDir   = $wwwDir . DIRECTORY_SEPARATOR . $localPath;
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0777, true);
        }

        $bundled   = !empty($def['bundled']);
        $installed = [];
        $ok        = true;

        if ($bundled) {
            $sourceBase = $this->scaffoldingDir . DIRECTORY_SEPARATOR . ((string) ($def['source_path'] ?? ''));
            foreach (array_merge($def['css'] ?? [], $def['js'] ?? []) as $filename) {
                $src  = $sourceBase . DIRECTORY_SEPARATOR . $filename;
                $dest = $destDir . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($src) && @copy($src, $dest)) {
                    $installed[] = $localPath . '/' . $filename;
                } else {
                    $ok = false;
                }
            }
        } else {
            foreach (array_merge($def['css'] ?? [], $def['js'] ?? []) as $url) {
                $filename = basename((string) parse_url((string) $url, PHP_URL_PATH));
                $dest     = $destDir . DIRECTORY_SEPARATOR . $filename;
                if ($this->downloadFile((string) $url, $dest)) {
                    $installed[] = $localPath . '/' . $filename;
                } else {
                    $ok = false;
                }
            }
        }

        return ['status' => $ok ? 'installed' : 'failed', 'files' => $installed];
    }

    /**
     * Fetch a remote asset to $dest. In the test environment
     * (PRAMNOS_TESTING) a deterministic stub is written instead of performing
     * a real network request, mirroring the init command's behaviour.
     */
    private function downloadFile(string $url, string $dest): bool
    {
        if (defined('PRAMNOS_TESTING') && PRAMNOS_TESTING) {
            return file_put_contents($dest, "/* mocked download of $url */\n") !== false;
        }

        // @codeCoverageIgnoreStart — real network path, not exercised in tests.
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 15,
                'user_agent' => 'PramnosFramework/1.2 (+https://github.com/mrpc/PramnosFramework)',
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            return false;
        }
        return file_put_contents($dest, $data) !== false;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Build the `$doc->registerScript(...)` / `$doc->registerStyle(...)` lines
     * for a library, matching the format emitted by `pramnos init` in the
     * scaffolded Application::registerVendorLibraries().
     *
     * @param string $key Catalog library key.
     * @return list<string> PHP source lines (without indentation), or [] if unknown.
     */
    public function registrationLines(string $key): array
    {
        $def = $this->libraryDef($key);
        if ($def === null) {
            return [];
        }

        $version = (string) ($def['version'] ?? '');
        $deps    = $def['requires'] ?? [];
        $depsPhp = $deps ? "['" . implode("', '", $deps) . "']" : '[]';

        // CSS dependency handles: only those requires that themselves have CSS.
        $cssDeps = array_values(array_filter($deps, function (string $d): bool {
            return !empty($this->libraryDef($d)['css'] ?? []);
        }));
        $cssDepsPhp = $cssDeps ? "['" . implode("', '", $cssDeps) . "']" : '[]';

        $lines = [];
        foreach (($def['js'] ?? []) as $url) {
            $filename = $this->assetFilename($def, (string) $url);
            $path     = ($def['local_path'] ?? '') . '/' . $filename;
            $lines[]  = "\$doc->registerScript('$key', sURL . '$path', $depsPhp, '$version', true);";
        }
        foreach (($def['css'] ?? []) as $url) {
            $filename = $this->assetFilename($def, (string) $url);
            $path     = ($def['local_path'] ?? '') . '/' . $filename;
            $lines[]  = "\$doc->registerStyle('$key', sURL . '$path', $cssDepsPhp, '$version');";
        }
        return $lines;
    }

    /**
     * Resolve an asset filename from a catalog entry (URL for CDN libraries,
     * bare filename for bundled ones).
     *
     * @param array<string, mixed> $def
     */
    private function assetFilename(array $def, string $entry): string
    {
        return !empty($def['bundled'])
            ? basename($entry)
            : basename((string) parse_url($entry, PHP_URL_PATH));
    }

    // =========================================================================
    // Application bootstrap registration
    // =========================================================================

    /**
     * Parse a project's src/Application.php for library handles already
     * registered via registerScript()/registerStyle().
     *
     * @return list<string>
     */
    public function detectRegisteredHandles(string $appFile): array
    {
        if (!file_exists($appFile)) {
            return [];
        }
        preg_match_all(
            "/register(?:Script|Style)\(\s*'([^']+)'/",
            (string) file_get_contents($appFile),
            $m
        );
        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Insert registration lines for the given libraries into the
     * registerVendorLibraries() method of a project's src/Application.php.
     *
     * Idempotent and best-effort: libraries already registered are skipped, and
     * if the method anchor cannot be located nothing is written — the caller
     * receives the lines in `manual` to surface for hand-insertion.
     *
     * @param list<string> $keys Library keys to register.
     * @return array{registered: list<string>, manual: list<string>} registered
     *         = keys written to the file; manual = PHP lines the caller should
     *         print when auto-insert was not possible.
     */
    public function registerInBootstrap(string $appFile, array $keys): array
    {
        $already = $this->detectRegisteredHandles($appFile);
        $keys    = array_values(array_filter($keys, fn ($k) => !in_array($k, $already, true)));

        $lines      = [];
        $registered = [];
        foreach ($keys as $key) {
            $keyLines = $this->registrationLines($key);
            if ($keyLines === []) {
                continue;
            }
            foreach ($keyLines as $line) {
                $lines[] = '        ' . $line;
            }
            $registered[] = $key;
        }

        if ($lines === []) {
            return ['registered' => [], 'manual' => []];
        }

        $src    = file_exists($appFile) ? (string) file_get_contents($appFile) : '';
        $anchor = "\$doc = \\Pramnos\\Framework\\Factory::getDocument();";
        $pos    = $src !== '' ? strpos($src, $anchor) : false;

        if ($pos === false) {
            return ['registered' => [], 'manual' => $lines];
        }

        $insertAt = $pos + strlen($anchor);
        $newSrc   = substr($src, 0, $insertAt) . "\n" . implode("\n", $lines) . substr($src, $insertAt);

        if (file_put_contents($appFile, $newSrc) === false) {
            return ['registered' => [], 'manual' => $lines];
        }

        return ['registered' => $registered, 'manual' => []];
    }
}
