<?php

namespace Pramnos\Cache\Adapter;

/**
 * File-based cache adapter
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
class FileAdapter extends AbstractAdapter
{
    /**
     * Cache directory path
     * @var string
     */
    protected $cacheDir = '';

    

    /**
     * @param string $cacheDir Cache directory path
     * @param string $prefix Prefix for all cache keys
     */
    public function __construct($cacheDir = '', $prefix = '')
    {
        parent::__construct($prefix);

        if ($cacheDir == '' && defined('CACHE_PATH')) {
            $this->cacheDir = CACHE_PATH;
        } else {
            $this->cacheDir = $cacheDir;
        }
    }

    /**
     * Connect to the filesystem
     * @return boolean Success of the connection
     */
    public function connect()
    {
        if ($this->cacheDir == '') {
            return false;
        }

        if (!file_exists($this->cacheDir)) {
            try {
                mkdir($this->cacheDir, 0755, true);
            } catch (\Exception $ex) {
                \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            }
        }

        return is_dir($this->cacheDir) && is_writable($this->cacheDir);
    }

    /**
     * @inheritDoc
     */
    public function getCategories($prefix = '')
    {
        if (!$this->caching) {
            return [];
        }
        
        $path = $this->cacheDir;
        
        if ($prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($prefix);
        } else if ($this->prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($this->prefix);
        }
        
        if (!is_dir($path)) {
            return [];
        }
        
        try {
            $directories = glob($path . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            $categories = [];
            
            foreach ($directories as $dir) {
                $categories[] = basename($dir);
            }
            
            return $categories;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return [];
        }
    }

    /**
     * Create cache dir if it doesn't exist
     */
    private function _createCacheDir()
    {
        try {
            mkdir($this->cacheDir);
        }
        catch (\Exception $ex) {
            $this->caching=false;
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    /**
     * Get the full path to a cache file
     * @param string $key Cache key
     * @param boolean $createDir Whether to create the directory if it doesn't exist
     * @return string|boolean Full path or false on failure
     */
    protected function getFilePath($key, $createDir = true)
    {
        $parts = explode('_', $key);
        $prefix = '';
        $category = '';

        if ($this->prefix != '') {
            $prefix = $this->sanitizeName($this->prefix);
        }

        if (count($parts) > 1) {
            $category = $parts[0];
        }

        $path = $this->cacheDir;

        if ($prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $prefix;

            if (!file_exists($path) && $createDir) {
                try {
                    mkdir($path, 0755, true);
                } catch (\Exception $ex) {
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                    return false;
                }
            }
        }

        if ($category != '') {
            $path .= DIRECTORY_SEPARATOR . $category;

            if (!file_exists($path) && $createDir) {
                try {
                    mkdir($path, 0755, true);
                } catch (\Exception $ex) {
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                    return false;
                }
            }
        }

        return $path . DIRECTORY_SEPARATOR . $key;
    }

    /**
     * @inheritDoc
     */
    public function load($key, $timeout = 3600)
    {
        if (!$this->caching) {
            return false;
        }

        $filePath = $this->getFilePath($key, false);
        if (!$filePath || !file_exists($filePath)) {
            return false;
        }

        try {
            // Check if file is expired
            if ($timeout > 0 && filemtime($filePath) < (time() - $timeout)) {
                $this->delete($key);
                return false;
            }

            $filedata = file_get_contents($filePath);
            if (!$filedata) {
                return false;
            }

            $entry = unserialize($filedata);
            if (!isset($entry['data'])) {
                return false;
            }

            return $entry['data'];
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function save($key, $data, $timeout = 3600)
    {
        if (!$this->caching) {
            return false;
        }

        $filePath = $this->getFilePath($key);
        if (!$filePath) {
            return false;
        }

        try {
            $entry = [
                'data' => $data,
                'time' => time(),
                'timeout' => $timeout
            ];

            $serialized = serialize($entry);
            file_put_contents($filePath, $serialized);

            return true;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete($key)
    {
        if (!$this->caching) {
            return false;
        }

        $filePath = $this->getFilePath($key, false);
        if (!$filePath || !file_exists($filePath)) {
            return true; // Already deleted
        }

        try {
            unlink($filePath);
            $this->cleanEmptyDirectories(dirname($filePath));
            return true;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Remove empty directories
     * @param string $dir Directory path
     */
    protected function cleanEmptyDirectories($dir)
    {
        if ($dir == $this->cacheDir) {
            return;
        }

        if (is_dir($dir)) {
            $files = scandir($dir);
            if (count($files) <= 2) { // Only . and ..
                try {
                    rmdir($dir);
                    $this->cleanEmptyDirectories(dirname($dir));
                } catch (\Exception $ex) {
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function clear($category = '')
    {
        $path = $this->cacheDir;

        if ($this->prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($this->prefix);
        }

        if ($category != '') {
            $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($category);
        }

        if (is_dir($path)) {
            $files = $this->listDirectoryFiles($path);
            foreach ($files as $file) {
                unlink($file);
            }
            $this->cleanEmptyDirectories($path);
        }
        $this->cleanup();
    }

    /**
     * @inheritDoc
     */
    protected function cleanup()
    {
        $files = $this->listDirectoryFiles($this->cacheDir);
        foreach ($files as $file) {
            if ($this->checkIfFileIsExpired($file)) {
                unlink($file);
            }
        }
        $this->cleanEmptyDirectories($this->cacheDir);
    }

    private function checkIfFileIsExpired($file)
    {
        if (!is_file($file)) {
            return false;
        }

        $contents = file_get_contents($file);
        if (!\Pramnos\General\Helpers::checkUnserialize($contents)) {
            return false;
        }

        try {
            $details = unserialize($contents);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }

        return filemtime($file) < (time() - $details['timeout']);
    }

    private function listDirectoryFiles($path)
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function getStats()
    {
        $stats = [
            'method' => 'file',
            'categories' => 0,
            'items' => 0
        ];

        if (!$this->caching) {
            return $stats;
        }

        try {
            $path = $this->cacheDir;

            if ($this->prefix != '') {
                $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($this->prefix);
            }

            if (!is_dir($path)) {
                return $stats;
            }

            $categories = $this->listDirectoryFiles($path, true);
            $stats['categories'] = count($categories);

            $files = $this->listDirectoryFiles($path);
            $stats['items'] = count($files);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }

        return $stats;
    }

    /**
     * @inheritDoc
     */
    public function categoryHash($category, $prefix = '', $reset = false)
    {
        // For file system, we just return the category name
        return $category;
    }
}
