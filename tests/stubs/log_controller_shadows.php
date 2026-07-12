<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * Shadow function for php_sapi_name in the Pramnos\Application\Controllers namespace.
 *
 * Mocks the PHP Server API name to test CLI-specific vs Web-specific behaviors,
 * such as error rendering and output handling.
 *
 * @return string The SAPI name.
 */
function php_sapi_name(): string
{
    if (isset($GLOBALS['mock_sapi_name'])) {
        return $GLOBALS['mock_sapi_name'];
    }
    return \php_sapi_name();
}

/**
 * Shadow function for defined() in the Pramnos\Application\Controllers namespace.
 *
 * Intercepts constant definition checks (like constant 'SP') and allows tests
 * to simulate the constant being defined or undefined.
 *
 * @param string $constant The constant name.
 * @return bool True if defined (or mocked true), false otherwise.
 */
function defined(string $constant): bool
{
    if (isset($GLOBALS['mock_defined'][$constant])) {
        return $GLOBALS['mock_defined'][$constant];
    }
    return \defined($constant);
}

/**
 * Shadow function for is_dir in the Pramnos\Application\Controllers namespace.
 *
 * Intercepts is_dir directory checks to let unit tests simulate the presence
 * or absence of a directory on the virtual/real disk.
 *
 * @param string $filename Path to the directory.
 * @return bool True if directory exists (or mock exists), false otherwise.
 */
function is_dir(string $filename): bool
{
    if (isset($GLOBALS['mock_is_dir'][$filename])) {
        return $GLOBALS['mock_is_dir'][$filename];
    }
    return \is_dir($filename);
}

/**
 * Shadow function for file_exists in the Pramnos\Application\Controllers namespace.
 *
 * Intercepts file_exists checks to let unit tests mock whether a file resides on disk.
 *
 * @param string $filename Path to the file.
 * @return bool True if file exists (or mock exists), false otherwise.
 */
function file_exists(string $filename): bool
{
    if (isset($GLOBALS['mock_file_exists'][$filename])) {
        return $GLOBALS['mock_file_exists'][$filename];
    }
    return \file_exists($filename);
}

/**
 * Shadow function for json_decode in the Pramnos\Application\Controllers namespace.
 *
 * Allows mocking json_decode errors and raising exceptions to verify robust exception-handling
 * logic inside the LogController or bootstrap.
 *
 * @param string $json The json string being decoded.
 * @param bool|null $associative Associative output format flag.
 * @param int $depth Nesting depth.
 * @param int $options Decoding options.
 * @return mixed Decoded data or mock failure exception.
 * @throws \Exception When mock exception is requested.
 */
function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $options = 0)
{
    if (isset($GLOBALS['mock_json_decode_throw'])) {
        throw new \Exception('Mock JSON error');
    }
    return \json_decode($json, $associative, $depth, $options);
}

/**
 * Shadow function for tempnam in the Pramnos\Application\Controllers namespace.
 *
 * Simulates path generation failures or intercepts temporary file creation during tests.
 *
 * @param string $directory Directory to create file in.
 * @param string $prefix Prefix for temp file.
 * @return string|false Path to file, or mock failure value.
 */
function tempnam(string $directory, string $prefix): string|false
{
    if (isset($GLOBALS['mock_tempnam_fail'])) {
        return $directory;
    }
    return \tempnam($directory, $prefix);
}

/**
 * Shadow function for ob_get_level in the Pramnos\Application\Controllers namespace.
 *
 * Mock version to simulate multiple active output buffers and trace clean-up loops.
 *
 * @return int The output buffer nesting level.
 */
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

/**
 * Shadow function for ob_end_clean in the Pramnos\Application\Controllers namespace.
 *
 * Mock version to intercept and pretend success when cleansing output buffers during exception handler tests.
 *
 * @return bool True on success (or mock success), false on failure.
 */
function ob_end_clean(): bool
{
    if (isset($GLOBALS['mock_ob_end_clean'])) {
        return true;
    }
    return \ob_end_clean();
}

namespace Pramnos\Logs;

/**
 * Shadow function for class_exists in the Pramnos\Logs namespace.
 *
 * Intercepts class checks to simulate missing extensions (like ZipArchive) during log archiver tests.
 *
 * @param string $class Class name.
 * @param bool $autoload Whether to trigger autoloading.
 * @return bool True if class exists, false otherwise.
 */
function class_exists(string $class, bool $autoload = true): bool
{
    if ($class === 'ZipArchive' && isset($GLOBALS['mock_ziparchive_absent'])) {
        return false;
    }
    return \class_exists($class, $autoload);
}
