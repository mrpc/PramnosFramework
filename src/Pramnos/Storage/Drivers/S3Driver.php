<?php

declare(strict_types=1);

namespace Pramnos\Storage\Drivers;

use Pramnos\Storage\StorageInterface;

/**
 * AWS S3 (and S3-compatible) storage driver.
 *
 * Requires `aws/aws-sdk-php` (^3.0) in the application's composer.json.
 * If the SDK is absent, every method throws \RuntimeException with a clear
 * installation hint rather than a cryptic class-not-found error.
 *
 * Configuration keys:
 *   - `key`      (string) — AWS Access Key ID.
 *   - `secret`   (string) — AWS Secret Access Key.
 *   - `region`   (string) — e.g. 'eu-west-1'.
 *   - `bucket`   (string) — S3 bucket name.
 *   - `url`      (string, optional) — custom base URL for url() (CDN endpoint).
 *   - `endpoint` (string, optional) — custom endpoint for S3-compatible services (MinIO, etc.).
 *   - `use_path_style_endpoint` (bool, optional, default false) — use path-style URLs.
 *
 */
class S3Driver implements StorageInterface
{
    private string $bucket;
    private ?string $baseUrl;

    /** @var \Aws\S3\S3Client|null */
    private ?object $client = null;

    /**
     * @param array{key: string, secret: string, region: string, bucket: string, url?: string, endpoint?: string, use_path_style_endpoint?: bool} $config
     */
    public function __construct(private array $config)
    {
        $this->assertSdkAvailable();
        if (empty($config['bucket'])) {
            throw new \InvalidArgumentException('S3Driver requires a "bucket" config key.');
        }
        $this->bucket  = $config['bucket'];
        $this->baseUrl = isset($config['url']) ? rtrim((string) $config['url'], '/') : null;
    }

    // -------------------------------------------------------------------------
    // SDK bootstrapping
    // -------------------------------------------------------------------------

    private function assertSdkAvailable(): void
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \RuntimeException(
                'S3Driver requires the AWS SDK. Install it with: composer require aws/aws-sdk-php'
            );
        }
    }

    private function client(): \Aws\S3\S3Client
    {
        if ($this->client === null) {
            $args = [
                'version'     => 'latest',
                'region'      => $this->config['region'] ?? 'us-east-1',
                'credentials' => [
                    'key'    => $this->config['key']    ?? '',
                    'secret' => $this->config['secret'] ?? '',
                ],
            ];
            if (!empty($this->config['endpoint'])) {
                $args['endpoint'] = $this->config['endpoint'];
            }
            if (!empty($this->config['use_path_style_endpoint'])) {
                $args['use_path_style_endpoint'] = true;
            }
            $this->client = new \Aws\S3\S3Client($args);
        }
        return $this->client;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function get(string $path): string
    {
        try {
            $result = $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ]);
            return (string) $result['Body'];
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 get failed [{$path}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            $result = $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ]);
            return $result['Body']->detach();
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 readStream failed [{$path}]: " . $e->getMessage(), 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    public function put(string $path, $contents, array $options = []): bool
    {
        try {
            $args = array_merge([
                'Bucket' => $this->bucket,
                'Key'    => $path,
                'Body'   => $contents,
            ], $options);
            $this->client()->putObject($args);
            return true;
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 put failed [{$path}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function prepend(string $path, string $data): bool
    {
        $existing = $this->exists($path) ? $this->get($path) : '';
        return $this->put($path, $data . $existing);
    }

    public function append(string $path, string $data): bool
    {
        $existing = $this->exists($path) ? $this->get($path) : '';
        return $this->put($path, $existing . $data);
    }

    // -------------------------------------------------------------------------
    // Existence & metadata
    // -------------------------------------------------------------------------

    public function exists(string $path): bool
    {
        return $this->client()->doesObjectExist($this->bucket, $path);
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function size(string $path): int
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ]);
            return (int) $result['ContentLength'];
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 size failed [{$path}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function lastModified(string $path): int
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ]);
            return $result['LastModified']->getTimestamp();
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 lastModified failed [{$path}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function mimeType(string $path): string|false
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ]);
            return $result['ContentType'] ?? false;
        } catch (\Aws\Exception\AwsException $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    public function delete(string|array $paths): bool
    {
        $keys = array_map(fn($p) => ['Key' => $p], (array) $paths);
        try {
            $this->client()->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $keys],
            ]);
            return true;
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException('S3 delete failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function move(string $from, string $to): bool
    {
        $this->copy($from, $to);
        $this->delete($from);
        return true;
    }

    public function copy(string $from, string $to): bool
    {
        try {
            $this->client()->copyObject([
                'Bucket'     => $this->bucket,
                'Key'        => $to,
                'CopySource' => urlencode($this->bucket . '/' . $from),
            ]);
            return true;
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 copy failed [{$from} → {$to}]: " . $e->getMessage(), 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Directories (S3 uses key prefixes, not real directories)
    // -------------------------------------------------------------------------

    public function files(string $directory = ''): array
    {
        return $this->listKeys($directory, false);
    }

    public function allFiles(string $directory = ''): array
    {
        return $this->listKeys($directory, true);
    }

    /** @return string[] */
    private function listKeys(string $prefix, bool $recursive): array
    {
        $prefix = $prefix === '' ? '' : rtrim($prefix, '/') . '/';
        $args   = [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ];
        if (!$recursive) {
            $args['Delimiter'] = '/';
        }
        try {
            $paginator = $this->client()->getPaginator('ListObjectsV2', $args);
            $keys = [];
            foreach ($paginator as $page) {
                foreach ($page['Contents'] ?? [] as $obj) {
                    $keys[] = $obj['Key'];
                }
            }
            return $keys;
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException('S3 list failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function directories(string $directory = ''): array
    {
        $prefix = $directory === '' ? '' : rtrim($directory, '/') . '/';
        try {
            $result = $this->client()->listObjectsV2([
                'Bucket'    => $this->bucket,
                'Prefix'    => $prefix,
                'Delimiter' => '/',
            ]);
            return array_map(
                fn($p) => rtrim($p['Prefix'], '/'),
                $result['CommonPrefixes'] ?? []
            );
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException('S3 directories failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function makeDirectory(string $path): bool
    {
        // S3 has no real directories; create an empty key with trailing slash
        return $this->put(rtrim($path, '/') . '/', '');
    }

    public function deleteDirectory(string $path): bool
    {
        $keys = $this->allFiles($path);
        if (empty($keys)) {
            return true;
        }
        return $this->delete($keys);
    }

    // -------------------------------------------------------------------------
    // URLs
    // -------------------------------------------------------------------------

    public function url(string $path): string
    {
        if ($this->baseUrl !== null) {
            return $this->baseUrl . '/' . ltrim($path, '/');
        }
        return $this->client()->getObjectUrl($this->bucket, $path);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        try {
            $cmd = $this->client()->getCommand('GetObject', array_merge([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ], $options));
            $now     = new \DateTimeImmutable();
            $seconds = max(1, $expiration->getTimestamp() - $now->getTimestamp());
            $request = $this->client()->createPresignedRequest($cmd, "+{$seconds} seconds");
            return (string) $request->getUri();
        } catch (\Aws\Exception\AwsException $e) {
            throw new \RuntimeException("S3 temporaryUrl failed [{$path}]: " . $e->getMessage(), 0, $e);
        }
    }
}
