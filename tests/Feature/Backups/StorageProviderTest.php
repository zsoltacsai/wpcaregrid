<?php

namespace Tests\Feature\Backups;

use App\Backups\Storage\BackupStorage;
use App\Backups\Storage\ChecksumMismatch;
use App\Backups\Storage\LocalStorage;
use App\Backups\Storage\ObjectKey;
use App\Backups\Storage\S3Storage;
use App\Backups\Storage\SftpStorage;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StorageNotConfigured;
use App\Backups\Storage\StorageUnavailable;
use App\Backups\Storage\Verification;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StorageServers;
use Tests\TestCase;

/**
 * The storage abstraction against real protocol servers: S3 via moto,
 * SFTP via OpenSSH. Same contract for every provider.
 */
class StorageProviderTest extends TestCase
{
    private const KEY = '01a0da4e-2cb8-7171-a360-8a68030cc999/01a0da4f-0000-7000-8000-000000000001/files.tar.gz';

    public static function providers(): array
    {
        return ['local' => ['local'], 's3' => ['s3'], 'sftp' => ['sftp']];
    }

    #[DataProvider('providers')]
    public function test_store_verify_read_and_delete(string $provider): void
    {
        $storage = $this->storage($provider);

        foreach ([1000, 12 * 1024 * 1024] as $size) { // One PUT, and multipart for S3.
            $bytes = random_bytes($size);
            $sha = hash('sha256', $bytes);
            $storage->put(self::KEY, $this->stream($bytes), $size, $sha);

            $verification = $storage->verify(self::KEY, $size, $sha);
            $this->assertTrue($verification->ok(), "{$provider} {$size}");
            $this->assertSame($size, $verification->remoteSize);
            $this->assertContains($verification->method, [Verification::NATIVE, Verification::READBACK]);

            $offset = min(4096, $size - 100);
            $this->assertSame(substr($bytes, $offset, 100), $storage->readRange(self::KEY, $offset, 100));
        }

        $storage->delete(self::KEY);
        $this->assertSame('MISSING', $storage->verify(self::KEY, 1, str_repeat('0', 64))->failure);
    }

    #[DataProvider('providers')]
    public function test_verification_detects_wrong_size_and_checksum(string $provider): void
    {
        $storage = $this->storage($provider);
        $storage->put(self::KEY, $this->stream('stored bytes'), 12, hash('sha256', 'stored bytes'));

        $this->assertSame('SIZE_MISMATCH', $storage->verify(self::KEY, 13, hash('sha256', 'stored bytes'))->failure);
        $wrong = $storage->verify(self::KEY, 12, hash('sha256', 'other bytes!'));
        $this->assertFalse($wrong->ok());
        $this->assertSame('CHECKSUM_MISMATCH', $wrong->failure);

        $storage->delete(self::KEY);
    }

    #[DataProvider('providers')]
    public function test_arbitrary_destinations_are_refused(string $provider): void
    {
        $storage = $this->storage($provider);

        foreach (['../../etc/passwd', '/etc/passwd', 'backups/../../x', self::KEY.'/../../x', 'a/b/c.txt', '01a0da4e-2cb8-7171-a360-8a68030cc999/files.tar.gz'] as $key) {
            try {
                $storage->put($key, $this->stream('x'), 1, hash('sha256', 'x'));
                $this->fail("Accepted key {$key}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_object_keys(): void
    {
        $this->assertSame(self::KEY, ObjectKey::assert(self::KEY));
        $this->expectException(\InvalidArgumentException::class);
        ObjectKey::assert(str_replace('files.tar.gz', 'shell.php', self::KEY));
    }

    public function test_s3_native_checksum_is_used_when_the_service_returns_one(): void
    {
        $sha = hash('sha256', 'bytes');
        [$storage, $mock] = $this->mockedS3();
        $mock->append(new Result(['ContentLength' => 5, 'ChecksumSHA256' => base64_encode(hex2bin($sha))]));
        $mock->append(new Result(['ContentLength' => 5, 'ChecksumSHA256' => base64_encode(hash('sha256', 'other', true))]));

        $native = $storage->verify(self::KEY, 5, $sha);
        $this->assertTrue($native->ok());
        $this->assertSame(Verification::NATIVE, $native->method, 'No download needed.');

        $this->assertSame('CHECKSUM_MISMATCH', $storage->verify(self::KEY, 5, $sha)->failure);
    }

    public function test_s3_sends_the_sha256_so_the_service_rejects_corrupt_uploads(): void
    {
        [$storage, $mock] = $this->mockedS3();
        $sent = null;
        $mock->append(function (CommandInterface $command) use (&$sent) {
            $sent = $command->toArray();

            return new S3Exception('BadDigest', $command, ['code' => 'BadDigest']);
        });

        try {
            $storage->put(self::KEY, $this->stream('bytes'), 5, hash('sha256', 'bytes'));
            $this->fail('No checksum error.');
        } catch (ChecksumMismatch) {
            $this->assertSame(base64_encode(hash('sha256', 'bytes', true)), $sent['ChecksumSHA256']);
            $this->assertSame('AES256', $sent['ServerSideEncryption']);
            $this->assertSame('backups/'.self::KEY, $sent['Key']);
        }
    }

    public function test_s3_sse_c_validates_a_32_byte_hex_key_without_exposing_it(): void
    {
        $key = bin2hex(str_repeat("\x01", 32));
        $this->assertInstanceOf(S3Storage::class, new S3Storage($this->sseCConfig($key)));

        foreach ([null, str_repeat('a', 62), str_repeat('g', 64)] as $invalid) {
            try {
                new S3Storage($this->sseCConfig($invalid));
                $this->fail('Invalid SSE-C key was accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('S3 SSE-C requires a 64-character hexadecimal key (32 bytes).', $e->getMessage());
            }
        }

        try {
            new S3Storage(['encryption_mode' => 'sse-customer-key']);
            $this->fail('Unknown encryption mode was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unsupported S3 encryption mode.', $e->getMessage());
        }
    }

    public function test_s3_explicit_sse_s3_and_none_modes_are_supported(): void
    {
        [$storage, $mock] = $this->mockedS3(['encryption_mode' => 'sse-s3']);
        $sent = null;
        $mock->append(function (CommandInterface $command) use (&$sent) {
            $sent = $command->toArray();

            return new Result;
        });
        $storage->put(self::KEY, $this->stream('bytes'), 5, hash('sha256', 'bytes'));
        $this->assertSame('AES256', $sent['ServerSideEncryption']);

        [$storage, $mock] = $this->mockedS3(['encryption_mode' => 'none']);
        $mock->append(function (CommandInterface $command) {
            $this->assertArrayNotHasKey('ServerSideEncryption', $command->toArray());

            return new Result;
        });
        $storage->put(self::KEY, $this->stream('bytes'), 5, hash('sha256', 'bytes'));
    }

    public function test_s3_sse_c_is_sent_for_put_and_delete_omits_it(): void
    {
        $rawKey = str_repeat("\x01", 32);
        [$storage, $mock] = $this->mockedS3($this->sseCConfig(bin2hex($rawKey)));
        $sent = [];
        $mock->append(function (CommandInterface $command) use (&$sent) {
            $sent[] = $command->toArray();

            return new Result;
        });
        $mock->append(function (CommandInterface $command) use (&$sent) {
            $sent[] = $command->toArray();

            return new Result;
        });

        $storage->put(self::KEY, $this->stream('bytes'), 5, hash('sha256', 'bytes'));
        $storage->delete(self::KEY);

        $this->assertSseCParams($sent[0], $rawKey);
        $this->assertArrayNotHasKey('SSECustomerKey', $sent[1]);
        $this->assertArrayNotHasKey('SSECustomerKeyMD5', $sent[1]);
    }

    public function test_s3_sse_c_is_sent_for_all_multipart_commands(): void
    {
        $rawKey = str_repeat("\x02", 32);
        [$storage, $mock] = $this->mockedS3($this->sseCConfig(bin2hex($rawKey)));
        $commands = [];
        foreach ([
            ['UploadId' => 'upload-id'],
            ['ETag' => 'part-etag'],
            ['ETag' => 'complete-etag'],
        ] as $result) {
            $mock->append(function (CommandInterface $command) use (&$commands, $result) {
                $commands[] = $command->toArray();

                return new Result($result);
            });
        }

        $storage->put(self::KEY, $this->stream(str_repeat('x', 1001)), 1001, hash('sha256', str_repeat('x', 1001)));

        $this->assertCount(3, $commands);
        foreach ($commands as $command) {
            $this->assertSseCParams($command, $rawKey);
        }
    }

    public function test_s3_sse_c_is_sent_for_head_readback_and_range_get(): void
    {
        $rawKey = str_repeat("\x03", 32);
        [$storage, $mock] = $this->mockedS3($this->sseCConfig(bin2hex($rawKey)));
        $commands = [];
        $mock->append(function (CommandInterface $command) use (&$commands) {
            $commands[] = $command->toArray();

            return new Result(['ContentLength' => 5]);
        });
        $mock->append(function (CommandInterface $command) use (&$commands) {
            $commands[] = $command->toArray();

            return new Result(['Body' => Utils::streamFor('bytes')]);
        });
        $mock->append(function (CommandInterface $command) use (&$commands) {
            $commands[] = $command->toArray();

            return new Result(['Body' => Utils::streamFor('yte')]);
        });
        config(['backup.transfer.readback' => true]);

        $verification = $storage->verify(self::KEY, 5, hash('sha256', 'bytes'));
        $this->assertTrue($verification->ok());
        $this->assertSame('yte', $storage->readRange(self::KEY, 1, 3));

        $this->assertCount(3, $commands);
        foreach ($commands as $command) {
            $this->assertSseCParams($command, $rawKey);
        }
    }

    public function test_s3_sse_c_key_never_appears_in_storage_exceptions(): void
    {
        $hexKey = bin2hex(str_repeat("\x04", 32));
        [$storage, $mock] = $this->mockedS3($this->sseCConfig($hexKey));
        $mock->append(fn (CommandInterface $command) => new S3Exception('request failed '.$hexKey, $command, ['code' => 'AccessDenied']));

        try {
            $storage->put(self::KEY, $this->stream('bytes'), 5, hash('sha256', 'bytes'));
            $this->fail('No storage error.');
        } catch (StorageUnavailable $e) {
            $this->assertSame('S3 storage error: AccessDenied', $e->getMessage());
            $this->assertStringNotContainsString($hexKey, $e->getMessage());
        }
    }

    public function test_storage_errors_never_contain_credentials(): void
    {
        [$storage, $mock] = $this->mockedS3();
        $mock->append(fn (CommandInterface $command) => new S3Exception('Access denied for key AKIASECRETKEYID', $command, ['code' => 'AccessDenied']));

        try {
            $storage->put(self::KEY, $this->stream('bytes'), 5, hash('sha256', 'bytes'));
            $this->fail('No error.');
        } catch (StorageUnavailable $e) {
            $this->assertSame('S3 storage error: AccessDenied', $e->getMessage());
        }

        if (($config = StorageServers::sftp()) || StorageServers::required()) {
            $config ?? $this->missing('sshd is not available.');
            $wrongHost = new SftpStorage(['host_fingerprint' => 'SHA256:not-this-server'] + $config);
            try {
                $wrongHost->verify(self::KEY, 1, str_repeat('0', 64));
                $this->fail('Connected to an unknown host key.');
            } catch (StorageUnavailable $e) {
                $this->assertSame('SFTP host key does not match the configured fingerprint.', $e->getMessage());
            }
        }
    }

    public function test_unreachable_storage_is_unavailable(): void
    {
        $storage = new S3Storage(['endpoint' => 'http://127.0.0.1:9', 'region' => 'us-east-1', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'prefix' => '', 'use_path_style_endpoint' => true, 'server_side_encryption' => '', 'multipart_threshold' => 1000, 'part_size' => 1]);

        $this->expectException(StorageUnavailable::class);
        $storage->verify(self::KEY, 1, str_repeat('0', 64));
    }

    public function test_incomplete_configuration_is_not_configured(): void
    {
        $manager = app(StorageManager::class);
        config(['backup.storage' => 's3', 'backup.providers.s3.bucket' => null]);
        $this->assertNull($manager->defaultName());

        config(['backup.storage' => 'sftp', 'backup.providers.sftp' => ['driver' => 'sftp', 'host' => 'sftp.example', 'username' => 'u', 'root' => '/b', 'password' => 'p', 'host_fingerprint' => null]]);
        $this->assertFalse($manager->isConfigured('sftp'), 'A host key fingerprint is required.');

        $this->expectException(StorageNotConfigured::class);
        $manager->provider('sftp');
    }

    public function test_s3_readiness_rejects_invalid_encryption_settings(): void
    {
        $manager = app(StorageManager::class);
        $base = ['driver' => 's3', 'bucket' => 'caregrid', 'key' => 'k', 'secret' => 's'];

        foreach ([
            ['encryption_mode' => 'sse-c'],
            ['encryption_mode' => 'sse-c', 'sse_customer_key' => str_repeat('a', 62)],
            ['encryption_mode' => 'sse-c', 'sse_customer_key' => str_repeat('g', 64)],
            ['encryption_mode' => 'unsupported'],
        ] as $encryption) {
            app()->detectEnvironment(fn () => 'testing');
            config(['backup.providers.s3' => $base + $encryption]);
            $this->assertFalse($manager->isConfigured('s3'));
            $issue = $manager->configurationIssue('s3');
            $this->assertNotNull($issue);
            if (isset($encryption['sse_customer_key'])) {
                $this->assertStringNotContainsString($encryption['sse_customer_key'], $issue);
            }
        }

        config(['backup.providers.s3' => $base + [
            'encryption_mode' => 'sse-c',
            'sse_customer_key' => bin2hex(str_repeat("\x06", 32)),
        ]]);
        $this->assertTrue($manager->isConfigured('s3'));
        $this->assertNull($manager->configurationIssue('s3'));
    }

    public function test_production_none_requires_an_explicit_override(): void
    {
        $manager = app(StorageManager::class);
        $config = ['driver' => 's3', 'bucket' => 'caregrid', 'key' => 'k', 'secret' => 's', 'encryption_mode' => 'none'];

        app()->detectEnvironment(fn () => 'production');
        config(['backup.storage' => 's3', 'backup.providers.s3' => $config + ['allow_unencrypted' => false]]);
        $this->assertFalse($manager->isConfigured('s3'));
        $this->assertNull($manager->defaultName());
        $issue = $manager->configurationIssue('s3');
        $this->assertStringContainsString('BACKUP_S3_ALLOW_UNENCRYPTED=true', (string) $issue);

        config(['backup.providers.s3.allow_unencrypted' => true]);
        $this->assertTrue($manager->isConfigured('s3'));
        $this->assertNull($manager->configurationIssue('s3'));

        app()->detectEnvironment(fn () => 'testing');
        config(['backup.providers.s3.allow_unencrypted' => false]);
        $this->assertTrue($manager->isConfigured('s3'));
    }

    public function test_cached_s3_provider_does_not_bypass_production_encryption_readiness(): void
    {
        $manager = app(StorageManager::class);
        app()->detectEnvironment(fn () => 'production');
        config([
            'backup.providers.s3' => [
                'driver' => 's3',
                'bucket' => 'caregrid',
                'key' => 'k',
                'secret' => 's',
                'encryption_mode' => 'none',
                'allow_unencrypted' => true,
            ],
        ]);

        $this->assertInstanceOf(S3Storage::class, $manager->provider('s3'));
        config(['backup.providers.s3.allow_unencrypted' => false]);

        $this->expectException(StorageNotConfigured::class);
        $manager->provider('s3');
    }

    private function storage(string $provider): BackupStorage
    {
        return match ($provider) {
            'local' => new LocalStorage(tap(sys_get_temp_dir().'/caregrid-local-'.uniqid(), fn ($dir) => $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir)))),
            's3' => ($config = StorageServers::s3()) ? new S3Storage($config) : $this->missing('moto_server is not installed.'),
            'sftp' => ($config = StorageServers::sftp()) ? new SftpStorage($config) : $this->missing('sshd is not available.'),
        };
    }

    /** Skipped on a machine without the service; a failure where it is required (CI). */
    private function missing(string $reason): never
    {
        StorageServers::required() ? $this->fail($reason.' CAREGRID_TEST_REQUIRE_SERVICES=1 requires it.') : $this->markTestSkipped($reason);
    }

    /** @return array{0: S3Storage, 1: MockHandler} */
    private function mockedS3(array $overrides = []): array
    {
        $mock = new MockHandler;
        $storage = new S3Storage(array_replace(['endpoint' => 'https://s3.example', 'region' => 'eu-central-1', 'bucket' => 'caregrid', 'key' => 'k', 'secret' => 's', 'prefix' => 'backups', 'use_path_style_endpoint' => true, 'server_side_encryption' => 'AES256', 'multipart_threshold' => 1000, 'part_size' => 5 * 1024 * 1024], $overrides));
        $client = new S3Client(['version' => 'latest', 'region' => 'eu-central-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock]);
        (new \ReflectionProperty(S3Storage::class, 'client'))->setValue($storage, $client);

        return [$storage, $mock];
    }

    /** @return array<string, mixed> */
    private function sseCConfig(?string $hexKey): array
    {
        return ['encryption_mode' => 'sse-c', 'sse_customer_key' => $hexKey];
    }

    /** @param array<string, mixed> $params */
    private function assertSseCParams(array $params, string $rawKey): void
    {
        $this->assertSame('AES256', $params['SSECustomerAlgorithm']);
        // The AWS SDK middleware encodes both raw binary values for HTTP headers.
        $this->assertSame(base64_encode($rawKey), $params['SSECustomerKey']);
        $this->assertSame(base64_encode(md5($rawKey, true)), $params['SSECustomerKeyMD5']);
        $this->assertArrayNotHasKey('ServerSideEncryption', $params);
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
