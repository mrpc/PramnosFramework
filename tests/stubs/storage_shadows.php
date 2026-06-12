<?php

declare(strict_types=1);

namespace Pramnos\Storage\Drivers;

/**
 * Shadow function for class_exists in the Pramnos\Storage\Drivers namespace.
 *
 * Used during unit/characterization testing of storage drivers. If the global variable
 * $GLOBALS['mock_s3_absent'] is set, this function simulates the absence of the AWS S3 SDK
 * by returning false when searching for the S3Client class. Otherwise, it delegates to
 * PHP's built-in class_exists function.
 *
 * @param string $class The class name to check.
 * @param bool $autoload Whether to trigger autoloading.
 * @return bool True if the class exists, false otherwise.
 */
function class_exists(string $class, bool $autoload = true): bool
{
    if ($class === 'Aws\\S3\\S3Client' && isset($GLOBALS['mock_s3_absent'])) {
        return false;
    }
    return \class_exists($class, $autoload);
}

/**
 * Shadow function for extension_loaded in the Pramnos\Storage\Drivers namespace.
 *
 * Used to test fallback behaviour or error handling when the FTP extension is not loaded
 * in the current PHP environment. If the global variable $GLOBALS['mock_ftp_absent'] is set,
 * this function will return false when checking for the 'ftp' extension. Otherwise, it
 * delegates to PHP's built-in extension_loaded function.
 *
 * @param string $name The extension name.
 * @return bool True if the extension is loaded, false otherwise.
 */
function extension_loaded(string $name): bool
{
    if ($name === 'ftp' && isset($GLOBALS['mock_ftp_absent'])) {
        return false;
    }
    return \extension_loaded($name);
}
