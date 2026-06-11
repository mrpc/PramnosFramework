<?php

declare(strict_types=1);

namespace Pramnos\Storage\Drivers;

function class_exists(string $class, bool $autoload = true): bool
{
    if ($class === 'Aws\\S3\\S3Client' && isset($GLOBALS['mock_s3_absent'])) {
        return false;
    }
    return \class_exists($class, $autoload);
}

function extension_loaded(string $name): bool
{
    if ($name === 'ftp' && isset($GLOBALS['mock_ftp_absent'])) {
        return false;
    }
    return \extension_loaded($name);
}
