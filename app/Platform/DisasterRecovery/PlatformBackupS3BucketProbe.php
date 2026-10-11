<?php

namespace App\Platform\DisasterRecovery;

use Aws\S3\S3Client;

/** Minimal, read-only probe for the configured platform backup bucket. */
final class PlatformBackupS3BucketProbe implements PlatformBackupS3BucketProber
{
    /** @param array<string, mixed> $configuration */
    public function headBucket(array $configuration): void
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $configuration['region'],
            'endpoint' => $configuration['endpoint'],
            'use_path_style_endpoint' => $configuration['use_path_style_endpoint'],
            'credentials' => ['key' => $configuration['key'], 'secret' => $configuration['secret']],
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ]);
        $client->headBucket(['Bucket' => $configuration['bucket']]);
    }
}
