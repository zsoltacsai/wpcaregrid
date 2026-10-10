<?php

namespace App\Console\Commands;

use App\Connector\ConnectorPackageArtifact;
use Illuminate\Console\Command;
use Throwable;

/** Builds or verifies the immutable private Connector package for this release. */
class PackageConnector extends Command
{
    protected $signature = 'connector:package';

    protected $description = 'Build or verify the immutable WP CareGrid Connector package';

    public function handle(ConnectorPackageArtifact $artifact): int
    {
        try {
            $package = $artifact->buildOrVerify();
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $revision = $package['source_revision'] ?? 'not available';
        $this->info("Connector {$package['version']} package verified; source {$revision}; source SHA-256 {$package['source_sha256']}; ZIP SHA-256 {$package['package_sha256']}");

        return self::SUCCESS;
    }
}
