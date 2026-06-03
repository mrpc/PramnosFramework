<?php

declare(strict_types=1);

namespace Pramnos\Storage\Drivers {
    class FtpMockState
    {
        public static bool $connectResult = true;
        public static bool $loginResult = true;
        public static bool $pasvResult = true;
        public static int $sizeResult = 100;
        public static int $mdtmResult = 123456789;
        public static $nlistResult = ['file1.txt', 'dir1'];
        public static bool $getResult = true;
        public static bool $fgetResult = true;
        public static bool $fputResult = true;
        public static bool $deleteResult = true;
        public static bool $renameResult = true;
        public static $mkdirResult = 'dir1';
        public static bool $rmdirResult = true;
        public static bool $chdirResult = true;
    }

    function ftp_connect($host, $port, $timeout) { return FtpMockState::$connectResult ? tmpfile() : false; }
    function ftp_ssl_connect($host, $port, $timeout) { return FtpMockState::$connectResult ? tmpfile() : false; }
    function ftp_login($conn, $user, $pass) { return FtpMockState::$loginResult; }
    function ftp_pasv($conn, $pasv) { return FtpMockState::$pasvResult; }
    function ftp_close($conn) { @fclose($conn); }
    function ftp_size($conn, $path) { 
        if (basename($path) === 'dir1' || str_contains($path, 'dir1')) return -1;
        return FtpMockState::$sizeResult; 
    }
    function ftp_mdtm($conn, $path) { return FtpMockState::$mdtmResult; }
    function ftp_nlist($conn, $path) { 
        if (basename($path) === 'dir1' || str_contains($path, 'dir1')) {
            return ['file2.txt']; // Break recursion
        }
        return FtpMockState::$nlistResult; 
    }
    function ftp_get($conn, $local, $remote, $mode) { 
        file_put_contents($local, 'ftp_mock_contents');
        return FtpMockState::$getResult; 
    }
    function ftp_fget($conn, $stream, $remote, $mode) { 
        fwrite($stream, 'ftp_mock_contents');
        return FtpMockState::$fgetResult; 
    }
    function ftp_fput($conn, $remote, $stream, $mode) { return FtpMockState::$fputResult; }
    function ftp_delete($conn, $path) { return FtpMockState::$deleteResult; }
    function ftp_rename($conn, $from, $to) { return FtpMockState::$renameResult; }
    function ftp_mkdir($conn, $path) { return FtpMockState::$mkdirResult; }
    function ftp_rmdir($conn, $path) { return FtpMockState::$rmdirResult; }
    function ftp_chdir($conn, $path) { return FtpMockState::$chdirResult; }
}

namespace Tests\Unit\Pramnos\Storage {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use Pramnos\Storage\Drivers\FtpDriver;
    use Pramnos\Storage\Drivers\S3Driver;
    use Pramnos\Storage\Drivers\FtpMockState;

    #[CoversClass(FtpDriver::class)]
    #[CoversClass(S3Driver::class)]
    class StorageDriversTest extends TestCase
    {
        // =========================================================================
        // FTP Driver Tests
        // =========================================================================

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
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user', 'url' => 'http://localhost/files']);
            $this->assertSame('image/jpeg', $driver->mimeType('photo.jpg'));
            $this->assertSame('application/pdf', $driver->mimeType('doc.pdf'));
            $this->assertSame('application/octet-stream', $driver->mimeType('unknown.xyz'));
            $this->assertSame('http://localhost/files/photo.jpg', $driver->url('photo.jpg'));
        }

        public function testFtpDriverTemporaryUrlThrows(): void
        {
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('FtpDriver does not support temporary URLs');
            $driver->temporaryUrl('photo.jpg', new \DateTime('+5 minutes'));
        }

        public function testFtpDriverConnectionFailures(): void
        {
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // 1. Connection fails
            FtpMockState::$connectResult = false;
            try {
                $driver->exists('file.txt');
                $this->fail('Expected connection failure');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('FTP connection failed', $e->getMessage());
            }

            // 2. Login fails
            FtpMockState::$connectResult = true;
            FtpMockState::$loginResult = false;
            try {
                $driver->exists('file.txt');
                $this->fail('Expected login failure');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('FTP login failed', $e->getMessage());
            }

            // Reset state
            FtpMockState::$loginResult = true;
        }

        public function testFtpDriverReadOperations(): void
        {
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);
            
            // test get
            $this->assertSame('ftp_mock_contents', $driver->get('file.txt'));

            // test readStream
            $stream = $driver->readStream('file.txt');
            $this->assertIsResource($stream);
            $this->assertSame('ftp_mock_contents', stream_get_contents($stream));
            fclose($stream);
        }

        public function testFtpDriverWriteAndOperations(): void
        {
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            $this->assertTrue($driver->put('file.txt', 'new contents'));
            $this->assertTrue($driver->prepend('file.txt', 'prepended '));
            $this->assertTrue($driver->append('file.txt', ' appended'));

            $this->assertTrue($driver->delete('file.txt'));
            $this->assertTrue($driver->delete(['f1.txt', 'f2.txt']));
            $this->assertTrue($driver->move('old.txt', 'new.txt'));
            $this->assertTrue($driver->copy('src.txt', 'dst.txt'));
        }

        public function testFtpDriverMetadataAndDirectories(): void
        {
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            $this->assertTrue($driver->exists('file.txt'));
            $this->assertFalse($driver->missing('file.txt'));
            $this->assertSame(100, $driver->size('file.txt'));
            $this->assertSame(123456789, $driver->lastModified('file.txt'));

            // test directories & listing
            $this->assertContains('file1.txt', $driver->files());
            $this->assertContains('file1.txt', $driver->allFiles());
            $this->assertContains('dir1', $driver->directories());

            $this->assertTrue($driver->makeDirectory('new_dir'));
            $this->assertTrue($driver->deleteDirectory('old_dir'));
        }

        // =========================================================================
        // S3 Driver Tests
        // =========================================================================

        public function testS3DriverConstructorRequiresBucket(): void
        {
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

        public function testS3DriverOperationsWithMockClient(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            $driver = new S3Driver([
                'bucket' => 'my-bucket',
                'region' => 'us-east-1',
                'endpoint' => 'http://localhost:9000',
                'use_path_style_endpoint' => true
            ]);

            // Mock AWS S3Client using onlyMethods on __call and other standard ones
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call', 'doesObjectExist', 'getPaginator', 'getObjectUrl', 'getCommand', 'createPresignedRequest'])
                ->getMock();

            // Inject mock client
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            // Stub doesObjectExist
            $mockS3Client->method('doesObjectExist')->willReturn(true);

            // Stub getObjectUrl
            $mockS3Client->method('getObjectUrl')->willReturn('https://s3.amazonaws.com/my-bucket/test.txt');

            // Stub getPaginator early to prevent null warning during directory ops
            $mockS3Client->method('getPaginator')->willReturn([
                ['Contents' => [['Key' => 'sub_dir/file.txt']]]
            ]);

            // Stub __call for magic methods
            $mockS3Client->method('__call')->willReturnCallback(function ($method, $args) {
                if ($method === 'getObject') {
                    return [
                        'Body' => new class {
                            public function __toString() { return 's3 contents'; }
                            public function detach() { return fopen('php://temp', 'r+'); }
                        }
                    ];
                }
                if ($method === 'headObject') {
                    return [
                        'ContentLength' => 1234,
                        'ContentType' => 'text/plain',
                        'LastModified' => new \Aws\Api\DateTimeResult('@123456789')
                    ];
                }
                if ($method === 'listObjectsV2') {
                    return [
                        'CommonPrefixes' => [['Prefix' => 'sub_dir/']]
                    ];
                }
                return null;
            });

            // Assertions
            $this->assertTrue($driver->exists('test.txt'));
            $this->assertFalse($driver->missing('test.txt'));
            $this->assertSame('s3 contents', $driver->get('test.txt'));
            $this->assertSame(1234, $driver->size('test.txt'));
            $this->assertSame(123456789, $driver->lastModified('test.txt'));
            $this->assertSame('text/plain', $driver->mimeType('test.txt'));

            // Write operations
            $this->assertTrue($driver->put('test.txt', 'new contents'));
            $this->assertTrue($driver->prepend('test.txt', 'prepended '));
            $this->assertTrue($driver->append('test.txt', ' appended'));

            // Directory / copy operations
            $this->assertTrue($driver->copy('f.txt', 't.txt'));
            $this->assertTrue($driver->move('f.txt', 't.txt'));
            $this->assertTrue($driver->delete('f.txt'));
            $this->assertTrue($driver->deleteDirectory('dir'));

            // Test list keys
            $this->assertSame(['sub_dir/file.txt'], $driver->allFiles('sub_dir'));
            $this->assertSame(['sub_dir/file.txt'], $driver->files('sub_dir'));
            $this->assertSame(['sub_dir'], $driver->directories('sub_dir'));
        }

        public function testS3ClientInstantiation(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Construct S3Driver without baseUrl to trigger client() call in url()
            $driver = new S3Driver([
                'bucket' => 'my-bucket',
                'region' => 'us-east-1',
                'key' => 'key',
                'secret' => 'secret',
                'endpoint' => 'http://localhost:9000',
                'use_path_style_endpoint' => true
            ]);

            // This executes the instantiation and getObjectUrl call
            $url = $driver->url('hello.jpg');
            $this->assertStringContainsString('hello.jpg', $url);
        }

        public function testS3DriverExceptions(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            $driver = new S3Driver(['bucket' => 'my-bucket']);

            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call'])
                ->getMock();

            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $command = new \Aws\Command('test');
            $awsException = new \Aws\Exception\AwsException('S3 failure message', $command);

            $mockS3Client->method('__call')->willThrowException($awsException);

            // test get exception
            try {
                $driver->get('file.txt');
                $this->fail('Expected exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('S3 get failed', $e->getMessage());
            }

            // test readStream exception
            try {
                $driver->readStream('file.txt');
                $this->fail('Expected exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('S3 readStream failed', $e->getMessage());
            }

            // test put exception
            try {
                $driver->put('file.txt', 'data');
                $this->fail('Expected exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('S3 put failed', $e->getMessage());
            }

            // test size exception
            try {
                $driver->size('file.txt');
                $this->fail('Expected exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('S3 size failed', $e->getMessage());
            }

            // test lastModified exception
            try {
                $driver->lastModified('file.txt');
                $this->fail('Expected exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('S3 lastModified failed', $e->getMessage());
            }

            // test mimeType exception (returns false instead of throwing)
            $this->assertFalse($driver->mimeType('file.txt'));
        }
    }
}
