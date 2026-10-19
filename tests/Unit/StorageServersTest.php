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
}
