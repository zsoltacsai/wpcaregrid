<?php

namespace App\SyntheticFixtures;

use App\Connector\JobType;
use App\Models\SyntheticSiteFixture;
use App\Models\Website;

final class SyntheticFixturePolicy
{
    private const ALLOWED_JOBS = [
        JobType::GetInventory,
        JobType::SiteHealth,
        JobType::LicenseCheck,
        JobType::BackupFiles,
        JobType::BackupDatabase,
        JobType::BackupFull,
        JobType::Restore,
    ];

    public function fixture(Website $website): ?SyntheticSiteFixture
    {
        return $website->syntheticFixture;
    }

    public function isEnabledFor(Website $website): bool
    {
        $fixture = $this->fixture($website);

        return $this->stagingEnabled()
            && $fixture !== null
            && $fixture->purpose === config('synthetic_fixtures.purpose')
            && $fixture->environment === 'staging'
            && $fixture->isActive()
            && $fixture->customer_id === $website->customer_id;
    }

    public function backupRestoreAllowed(Website $website): bool
    {
        return $this->isEnabledFor($website)
            && (bool) $this->fixture($website)?->backup_restore_entitled;
    }

    public function controlledExecutionAllowed(Website $website): bool
    {
        return $this->backupRestoreAllowed($website)
            && (bool) config('synthetic_fixtures.controlled_execution_enabled')
            && is_string(config('synthetic_fixtures.site_uuid'))
            && hash_equals((string) config('synthetic_fixtures.site_uuid'), (string) $website->uuid);
    }

    /** Only the exact active M8 fixture may run an explicitly enabled Integrity scan. */
    public function securityScanAllowed(Website $website, string $scanType): bool
    {
        return $scanType === 'integrity'
            && $this->isEnabledFor($website)
            && (bool) config('synthetic_fixtures.security_scan_enabled')
            && is_string(config('synthetic_fixtures.site_uuid'))
            && hash_equals((string) config('synthetic_fixtures.site_uuid'), (string) $website->uuid)
            && $website->customer?->active === true;
    }

    /** Owner pairing is available only for the active, explicitly configured M8 fixture. */
    public function ownerPairingAllowed(Website $website): bool
    {
        return $this->isEnabledFor($website)
            && is_string(config('synthetic_fixtures.site_uuid'))
            && hash_equals((string) config('synthetic_fixtures.site_uuid'), (string) $website->uuid);
    }

    public function jobAllowed(Website $website, JobType $type, array $payload = []): bool
    {
        if (! $this->isEnabledFor($website)) {
            return false;
        }

        if ($type === JobType::SecurityScan) {
            return $this->securityScanAllowed($website, (string) ($payload['scan_type'] ?? ''));
        }

        if (! in_array($type, self::ALLOWED_JOBS, true)) {
            return false;
        }

        // Pairing's initial health, inventory, and license checks are safe
        // for normal polling; all backup and restore jobs require controlled
        // execution for the exact configured synthetic site.
        if (in_array($type, [JobType::GetInventory, JobType::SiteHealth, JobType::LicenseCheck], true)) {
            return true;
        }

        if (! $this->controlledExecutionAllowed($website)) {
            return false;
        }

        if ($type->isBackup()) {
            return $this->backupRestoreAllowed($website);
        }

        if ($type === JobType::Restore) {
            return $this->controlledExecutionAllowed($website)
                && (bool) config('synthetic_fixtures.restore_enabled');
        }

        return true;
    }

    public function stagingEnabled(): bool
    {
        return app(StagingIdentity::class)->allowsCurrentDeployment();
    }
}
