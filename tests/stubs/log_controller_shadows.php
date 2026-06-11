<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

function php_sapi_name(): string
{
    if (isset($GLOBALS['mock_sapi_name'])) {
        return $GLOBALS['mock_sapi_name'];
    }
    return \php_sapi_name();
}

function defined(string $constant): bool
{
    if (isset($GLOBALS['mock_defined'][$constant])) {
        return $GLOBALS['mock_defined'][$constant];
    }
    return \defined($constant);
}

function is_dir(string $filename): bool
{
    if (isset($GLOBALS['mock_is_dir'][$filename])) {
        return $GLOBALS['mock_is_dir'][$filename];
    }
    return \is_dir($filename);
}

function file_exists(string $filename): bool
{
    if (isset($GLOBALS['mock_file_exists'][$filename])) {
        return $GLOBALS['mock_file_exists'][$filename];
    }
    return \file_exists($filename);
}

function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $options = 0)
{
    if (isset($GLOBALS['mock_json_decode_throw'])) {
        throw new \Exception('Mock JSON error');
    }
    return \json_decode($json, $associative, $depth, $options);
}

function tempnam(string $directory, string $prefix): string|false
{
    if (isset($GLOBALS['mock_tempnam_fail'])) {
        return $directory;
    }
    return \tempnam($directory, $prefix);
}

function ob_get_level(): int
{
    if (isset($GLOBALS['mock_ob_get_level'])) {
        $val = $GLOBALS['mock_ob_get_level'];
        if ($GLOBALS['mock_ob_get_level'] > 0) {
            $GLOBALS['mock_ob_get_level']--;
        }
        return $val;
    }
    return \ob_get_level();
}

function ob_end_clean(): bool
{
    if (isset($GLOBALS['mock_ob_end_clean'])) {
        return true;
    }
    return \ob_end_clean();
}

namespace Pramnos\Logs;

function class_exists(string $class, bool $autoload = true): bool
{
    if ($class === 'ZipArchive' && isset($GLOBALS['mock_ziparchive_absent'])) {
        return false;
    }
    return \class_exists($class, $autoload);
}
