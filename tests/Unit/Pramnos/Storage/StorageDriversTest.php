<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Storage\Drivers\FtpDriver;
use Pramnos\Storage\Drivers\S3Driver;

#[CoversClass(FtpDriver::class)]
#[CoversClass(S3Driver::class)]
class StorageDriversTest extends TestCase
{
    public function testFtpDriverConstructorRequiresConfig(): void
    {
        if (!extension_loaded('ftp')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('FtpDriver requires the PHP ftp extension');
            new FtpDriver(['host' => '127.0.0.1', 'username' => 'test']);
            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FtpDriver requires "host" and "username" config keys');
        new FtpDriver([]);
    }

    public function testFtpDriverMimeType(): void
    {
        if (!extension_loaded('ftp')) {
            $this->markTestSkipped('FTP extension not loaded');
        }

        $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user', 'url' => 'http://localhost/files']);
        $this->assertSame('image/jpeg', $driver->mimeType('photo.jpg'));
        $this->assertSame('application/pdf', $driver->mimeType('doc.pdf'));
        $this->assertSame('application/octet-stream', $driver->mimeType('unknown.xyz'));
        $this->assertSame('http://localhost/files/photo.jpg', $driver->url('photo.jpg'));
    }

    public function testFtpDriverTemporaryUrlThrows(): void
    {
        if (!extension_loaded('ftp')) {
            $this->markTestSkipped('FTP extension not loaded');
        }

        $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FtpDriver does not support temporary URLs');
        $driver->temporaryUrl('photo.jpg', new \DateTime('+5 minutes'));
    }

    public function testS3DriverConstructorRequiresBucket(): void
    {
        // When Aws SDK class is missing or present, let's see:
        if (!class_exists(\Aws\S3\S3Client::class)) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S3Driver requires the AWS SDK');
            new S3Driver(['bucket' => 'my-bucket']);
            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3Driver requires a "bucket" config key');
        new S3Driver(['key' => '123']);
    }

    public function testS3DriverUrlGeneration(): void
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            $this->markTestSkipped('AWS SDK not installed');
        }

        $driver = new S3Driver(['bucket' => 'my-bucket', 'region' => 'us-east-1', 'url' => 'https://cdn.my-bucket.com']);
        $this->assertSame('https://cdn.my-bucket.com/hello.jpg', $driver->url('hello.jpg'));
    }
}
