<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

/**
 * Keeps ROOT/app/keys out of the repository working tree.
 *
 * Instantiating the real `Oauth` controller runs
 * `new OAuth2ServerFactory($this)` followed by `generateKeyPair()`. With no
 * explicit paths the factory falls back to `ROOT/app/keys/private.key` and
 * `public.key`, and its constructor also persists `encryption.key` there. Under
 * PHPUnit ROOT is the framework checkout, so those side effects wrote a
 * root-owned `app/keys/` directory into the repo on every run and nothing ever
 * removed it.
 *
 * Tests that build the real controller mix this trait in: call
 * snapshotAppKeys() from setUp() and restoreAppKeys() from tearDown(). Only
 * files (and directories) that did **not** exist beforehand are removed, so
 * running the suite inside a real project never destroys its OAuth2 signing
 * keys — losing the private key would invalidate every issued token.
 */
trait PreservesAppKeys
{
    /**
     * Absolute paths that existed before the test ran, keyed by path.
     *
     * @var array<string, true>
     */
    private array $preexistingAppKeyPaths = [];

    /**
     * Record which parts of ROOT/app/keys already exist.
     *
     * Call from setUp(), before the controller under test is constructed.
     *
     * @return void
     */
    protected function snapshotAppKeys(): void
    {
        $this->preexistingAppKeyPaths = [];
        foreach ($this->appKeyPaths() as $path) {
            if (file_exists($path)) {
                $this->preexistingAppKeyPaths[$path] = true;
            }
        }
    }

    /**
     * Delete everything under ROOT/app/keys that this test created.
     *
     * Call from tearDown(). Paths present in the setUp() snapshot are left
     * untouched; the keys and app directories are only removed when they were
     * created by the test and are empty again afterwards.
     *
     * @return void
     */
    protected function restoreAppKeys(): void
    {
        foreach ($this->appKeyPaths() as $path) {
            if (!file_exists($path)) {
                continue;
            }
            if (is_dir($path)) {
                // Directories are removed even when they pre-date the test: an
                // empty app/ or app/keys carries no information, and rmdir()
                // simply fails (harmlessly) on the populated app/ directory of a
                // real project. The key files are listed first in
                // appKeyPaths(), so ours are already gone by now.
                @rmdir($path);
                continue;
            }
            if (isset($this->preexistingAppKeyPaths[$path])) {
                continue;
            }
            @unlink($path);
        }
    }

    /**
     * The paths the OAuth2 factory may create, files before their directories
     * so restoreAppKeys() can remove them in a single pass.
     *
     * @return string[]
     */
    private function appKeyPaths(): array
    {
        $appDir  = ROOT . DIRECTORY_SEPARATOR . 'app';
        $keysDir = $appDir . DIRECTORY_SEPARATOR . 'keys';

        return [
            $keysDir . DIRECTORY_SEPARATOR . 'private.key',
            $keysDir . DIRECTORY_SEPARATOR . 'public.key',
            $keysDir . DIRECTORY_SEPARATOR . 'encryption.key',
            $keysDir,
            $appDir,
        ];
    }
}
