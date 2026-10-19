<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\StorageServers;

final class StorageServersTest extends TestCase
{
    public function test_explicit_moto_endpoint_is_limited_to_plain_http_loopback(): void
    {
        $validate = new \ReflectionMethod(StorageServers::class, 'configuredS3Endpoint');
        $original = getenv('CAREGRID_TEST_S3_ENDPOINT');

        try {
            putenv('CAREGRID_TEST_S3_ENDPOINT=http://127.0.0.1:15000');
            self::assertSame('http://127.0.0.1:15000', $validate->invoke(null));

            foreach ([
                'https://127.0.0.1:15000',
                'http://localhost:15000',
                'http://192.168.1.2:15000',
                'http://127.0.0.1:15000/path',
                'http://user:pass@127.0.0.1:15000',
                'http://127.0.0.1:99999',
            ] as $invalid) {
                putenv('CAREGRID_TEST_S3_ENDPOINT='.$invalid);
                try {
                    $validate->invoke(null);
                    self::fail('Accepted a non-loopback or ambiguous Moto endpoint.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('CAREGRID_TEST_S3_ENDPOINT must be a plain HTTP loopback endpoint with an explicit port.', $exception->getMessage());
                }
            }
        } finally {
            $original === false ? putenv('CAREGRID_TEST_S3_ENDPOINT') : putenv('CAREGRID_TEST_S3_ENDPOINT='.$original);
        }
    }

    public function test_explicit_sftp_test_server_is_limited_to_pinned_loopback_endpoint(): void
    {
        $hostMethod = new \ReflectionMethod(StorageServers::class, 'configuredSftpHost');
        $portMethod = new \ReflectionMethod(StorageServers::class, 'configuredSftpPort');
        $originalHost = getenv('CAREGRID_TEST_SFTP_HOST');
        $originalPort = getenv('CAREGRID_TEST_SFTP_PORT');

        try {
            putenv('CAREGRID_TEST_SFTP_HOST=127.0.0.1');
            putenv('CAREGRID_TEST_SFTP_PORT=2222');
            self::assertSame('127.0.0.1', $hostMethod->invoke(null));
            self::assertSame(2222, $portMethod->invoke(null));

            foreach (['localhost', '::1', '127.0.0.2', '192.168.1.2', 'example.com'] as $invalidHost) {
                putenv('CAREGRID_TEST_SFTP_HOST='.$invalidHost);
                try {
                    $hostMethod->invoke(null);
                    self::fail('Accepted a non-approved SFTP test host.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('CAREGRID_TEST_SFTP_HOST must be the IPv4 loopback address 127.0.0.1.', $exception->getMessage());
                }
            }

            putenv('CAREGRID_TEST_SFTP_HOST=127.0.0.1');
            foreach (['0', '65536', '22abc', '-1'] as $invalidPort) {
                putenv('CAREGRID_TEST_SFTP_PORT='.$invalidPort);
                try {
                    $portMethod->invoke(null);
                    self::fail('Accepted an invalid SFTP test port.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('CAREGRID_TEST_SFTP_PORT must be an integer from 1 to 65535.', $exception->getMessage());
                }
            }
        } finally {
            $originalHost === false ? putenv('CAREGRID_TEST_SFTP_HOST') : putenv('CAREGRID_TEST_SFTP_HOST='.$originalHost);
            $originalPort === false ? putenv('CAREGRID_TEST_SFTP_PORT') : putenv('CAREGRID_TEST_SFTP_PORT='.$originalPort);
        }
    }
}
