<?php

namespace App\Platform\DisasterRecovery;

interface PlatformBackupS3BucketProber
{
    /** @param array<string, mixed> $configuration */
    public function headBucket(array $configuration): void;
}
