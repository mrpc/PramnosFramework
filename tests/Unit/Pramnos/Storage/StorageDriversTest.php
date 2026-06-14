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

        /**
         * When the 'ssl' config key is true, connection() must call
         * ftp_ssl_connect() instead of ftp_connect() (line 65).
         */
        public function testFtpDriverSslConnect(): void
        {
            // Arrange — ssl=true drives the ftp_ssl_connect() branch
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user', 'ssl' => true]);

            // Act — any method that triggers connection() exercises the SSL branch
            $this->assertTrue($driver->exists('file.txt'),
                'SSL connection must succeed and return a truthy result');
        }

        /**
         * ensureRemoteDirectory() must create missing path segments when
         * ftp_chdir() returns false (lines 109-114, 118).
         *
         * A path with a directory component ('subdir/file.txt') causes the
         * foreach body to execute; setting chdirResult=false triggers mkdir.
         */
        public function testFtpDriverEnsureRemoteDirectoryCreatesSegments(): void
        {
            // Arrange — make ftp_chdir fail so mkdir is called for the segment
            FtpMockState::$chdirResult = false;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act — put() with a subdirectory path triggers ensureRemoteDirectory
            $result = $driver->put('subdir/file.txt', 'contents');

            // Assert — ftp_fput eventually called after directory creation
            $this->assertTrue($result,
                'put() must succeed even when ensureRemoteDirectory creates segments');

            // Cleanup
            FtpMockState::$chdirResult = true;
        }

        /**
         * get() must throw RuntimeException when ftp_get() fails (lines 133-134).
         */
        public function testFtpDriverGetThrowsWhenFtpGetFails(): void
        {
            // Arrange — make ftp_get() return false
            FtpMockState::$getResult = false;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/FTP get failed/');

            try {
                $driver->get('file.txt');
            } finally {
                FtpMockState::$getResult = true;
            }
        }

        /**
         * readStream() must throw RuntimeException when ftp_fget() fails (lines 149-150).
         */
        public function testFtpDriverReadStreamThrowsWhenFtpFgetFails(): void
        {
            // Arrange — make ftp_fget() return false
            FtpMockState::$fgetResult = false;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/FTP readStream failed/');

            try {
                $driver->readStream('file.txt');
            } finally {
                FtpMockState::$fgetResult = true;
            }
        }

        /**
         * put() must accept a PHP resource directly and call ftp_fput() without
         * creating a temp file (line 166).
         */
        public function testFtpDriverPutWithResourceContents(): void
        {
            // Arrange — pass a resource directly; triggers the is_resource() branch
            $stream = tmpfile();
            fwrite($stream, 'streamed contents');
            rewind($stream);
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act
            $result = $driver->put('resource_file.txt', $stream);

            // Assert
            fclose($stream);
            $this->assertTrue($result,
                'put() must return true when uploading a resource stream');
        }

        /**
         * size() must throw RuntimeException when ftp_size() returns a negative
         * value (line 210) — indicating the file does not exist on the server.
         */
        public function testFtpDriverSizeThrowsWhenFtpSizeReturnsNegative(): void
        {
            // Arrange — ftp_size() returns -1 for all paths
            FtpMockState::$sizeResult = -1;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/FTP size failed/');

            try {
                $driver->size('missing.txt');
            } finally {
                FtpMockState::$sizeResult = 100;
            }
        }

        /**
         * lastModified() must throw RuntimeException when ftp_mdtm() returns a
         * negative value (line 219) — indicating the file does not exist.
         */
        public function testFtpDriverLastModifiedThrowsWhenFtpMdtmReturnsNegative(): void
        {
            // Arrange — ftp_mdtm() returns -1
            FtpMockState::$mdtmResult = -1;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/FTP lastModified failed/');

            try {
                $driver->lastModified('missing.txt');
            } finally {
                FtpMockState::$mdtmResult = 123456789;
            }
        }

        /**
         * delete() must return false when ftp_delete() fails for at least one
         * path in the list (line 247).
         */
        public function testFtpDriverDeleteReturnsFalseOnPartialFailure(): void
        {
            // Arrange — ftp_delete() always fails
            FtpMockState::$deleteResult = false;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act
            $result = $driver->delete(['a.txt', 'b.txt']);

            // Assert — $all was set to false at line 247
            $this->assertFalse($result,
                'delete() must return false when any ftp_delete() call fails');

            // Cleanup
            FtpMockState::$deleteResult = true;
        }

        /**
         * files() must return an empty array when ftp_nlist() returns false (line 275),
         * indicating the directory does not exist or cannot be listed.
         */
        public function testFtpDriverFilesReturnsEmptyArrayWhenNlistFails(): void
        {
            // Arrange — ftp_nlist() returns false
            FtpMockState::$nlistResult = false;
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act
            $result = $driver->files();

            // Assert — early return with empty array at line 275
            $this->assertSame([], $result,
                'files() must return [] when ftp_nlist() returns false');

            // Cleanup
            FtpMockState::$nlistResult = ['file1.txt', 'dir1'];
        }

        /**
         * url() must throw RuntimeException when no 'url' config key is set
         * (lines 331-333).
         */
        public function testFtpDriverUrlThrowsWhenNoBaseUrlConfigured(): void
        {
            // Arrange — driver constructed without a 'url' config key;
            // $this->baseUrl remains null
            $driver = new FtpDriver(['host' => 'localhost', 'username' => 'user']);

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/no "url" config key set/');
            $driver->url('photo.jpg');
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

        /**
         * delete() must re-throw an AwsException as RuntimeException so callers
         * always get a consistent exception type regardless of the SDK version.
         * Line 210: the throw inside the catch block.
         */
        public function testS3DriverDeleteThrowsRuntimeExceptionOnAwsError(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange — mock client that throws on deleteObjects (via __call)
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $mockS3Client->method('__call')
                ->willThrowException(new \Aws\Exception\AwsException('delete failed', new \Aws\Command('test')));

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S3 delete failed');
            $driver->delete('file.txt');
        }

        /**
         * copy() must re-throw an AwsException as RuntimeException.
         * Line 231: the throw inside the copy() catch block.
         */
        public function testS3DriverCopyThrowsRuntimeExceptionOnAwsError(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $mockS3Client->method('__call')
                ->willThrowException(new \Aws\Exception\AwsException('copy failed', new \Aws\Command('test')));

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S3 copy failed');
            $driver->copy('src.txt', 'dst.txt');
        }

        /**
         * allFiles() (via listKeys()) must re-throw an AwsException when the
         * paginator call fails. Line 270: the throw inside listKeys() catch block.
         */
        public function testS3DriverAllFilesThrowsRuntimeExceptionOnAwsError(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange — getPaginator throws AwsException
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getPaginator'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $mockS3Client->method('getPaginator')
                ->willThrowException(new \Aws\Exception\AwsException('list failed', new \Aws\Command('test')));

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S3 list failed');
            $driver->allFiles('some-prefix');
        }

        /**
         * directories() must re-throw an AwsException as RuntimeException.
         * Line 288: the throw inside the directories() catch block.
         */
        public function testS3DriverDirectoriesThrowsRuntimeExceptionOnAwsError(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange — __call throws so that listObjectsV2 fails
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $mockS3Client->method('__call')
                ->willThrowException(new \Aws\Exception\AwsException('list failed', new \Aws\Command('test')));

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S3 directories failed');
            $driver->directories('some-dir');
        }

        /**
         * temporaryUrl() must re-throw an AwsException as RuntimeException.
         * Line 331: the throw inside the temporaryUrl() catch block.
         */
        public function testS3DriverTemporaryUrlThrowsRuntimeExceptionOnAwsError(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange — getCommand throws AwsException
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getCommand'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $mockS3Client->method('getCommand')
                ->willThrowException(new \Aws\Exception\AwsException('presign failed', new \Aws\Command('test')));

            // Act + Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S3 temporaryUrl failed');
            $driver->temporaryUrl('file.txt', new \DateTimeImmutable('+1 hour'));
        }

        /**
         * makeDirectory() must delegate to put() with a trailing-slash key.
         * S3 has no real directories — an empty key ending in '/' is the convention.
         * Line 295: the single return statement in makeDirectory().
         */
        public function testS3DriverMakeDirectoryDelegatesToPut(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange — mock client that accepts the putObject call (via __call)
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            $mockS3Client->method('__call')->willReturn(null);

            // Act
            $result = $driver->makeDirectory('my-dir');

            // Assert — must return true (delegated to put() which returns true on success)
            $this->assertTrue($result,
                'makeDirectory() must return true on a successful S3 put()');
        }

        /**
         * deleteDirectory() must return true immediately when allFiles() finds no
         * objects under the given prefix. Lines 301-302 are only reached when the
         * empty check is triggered.
         */
        public function testS3DriverDeleteDirectoryReturnsTrueWhenNoFilesFound(): void
        {
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $this->markTestSkipped('AWS SDK not installed');
            }

            // Arrange — paginator returns a page with no Contents entries
            $driver = new S3Driver(['bucket' => 'my-bucket']);
            $mockS3Client = $this->getMockBuilder(\Aws\S3\S3Client::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getPaginator'])
                ->getMock();
            $prop = new \ReflectionProperty($driver, 'client');
            $prop->setValue($driver, $mockS3Client);

            // Return a single page with no objects → allFiles() returns []
            $mockS3Client->method('getPaginator')->willReturn([['Contents' => []]]);

            // Act
            $result = $driver->deleteDirectory('empty-dir');

            // Assert — no files found → early return true without calling delete()
            $this->assertTrue($result,
                'deleteDirectory() must return true immediately when the directory is empty');
        }
    }
}
