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

        if (! $this->stagingEnabled() || $fixture === null || $fixture->environment !== 'staging'
            || ! $fixture->isActive() || $fixture->customer_id !== $website->customer_id) {
            return false;
        }

        return match ($fixture->purpose) {
            (string) config('synthetic_fixtures.purpose') => true,
            (string) config('m14_security_fixture.purpose') => $this->m14FixtureIdentityAllowed($website, $fixture),
            default => false,
        };
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

    /** Controlled polling is distinct from M8 backup/restore execution entitlement. */
    public function controlledPollingAllowed(Website $website): bool
    {
        $fixture = $this->fixture($website);

        if ($fixture?->purpose === config('m14_security_fixture.purpose')) {
            return $this->m14FixtureIdentityAllowed($website, $fixture)
                && (bool) config('m14_security_fixture.controlled_polling_enabled')
                && $website->activeConnector?->isActive() === true
                && $website->activeConnector?->website_id === $website->id
                && is_string(config('m14_security_fixture.connector_uuid'))
                && hash_equals((string) config('m14_security_fixture.connector_uuid'), (string) $website->activeConnector?->uuid);
        }

        return $this->controlledExecutionAllowed($website);
    }

    /** M8 and M14 scans have separate config, purpose, identity and entitlement boundaries. */
    public function securityScanAllowed(Website $website, string $scanType): bool
    {
        $fixture = $this->fixture($website);
        if ($fixture?->purpose === config('m14_security_fixture.purpose')) {
            $allowedTypes = config('m14_security_fixture.allowed_scan_types');

            return is_array($allowedTypes)
                && in_array($scanType, $allowedTypes, true)
                && (bool) config('m14_security_fixture.security_scan_enabled')
                && $this->controlledPollingAllowed($website)
                && app(M14SecurityScanWindowService::class)->allowsScan($website)
                && $website->customer?->active === true
                && $website->activeConnector?->isActive() === true;
        }

        return $scanType === 'integrity'
            && $this->isEnabledFor($website)
            && (bool) config('synthetic_fixtures.security_scan_enabled')
            && is_string(config('synthetic_fixtures.site_uuid'))
            && hash_equals((string) config('synthetic_fixtures.site_uuid'), (string) $website->uuid)
            && $website->customer?->active === true;
    }

    /** Owner pairing is available only for an active, explicitly configured M8 or M14 fixture. */
    public function ownerPairingAllowed(Website $website): bool
    {
        $fixture = $this->fixture($website);

        if ($fixture?->purpose === config('m14_security_fixture.purpose')) {
            return $this->m14FixtureIdentityAllowed($website, $fixture);
        }

        return $fixture?->purpose === config('synthetic_fixtures.purpose')
            && $this->isEnabledFor($website)
            && is_string(config('synthetic_fixtures.site_uuid'))
            && hash_equals((string) config('synthetic_fixtures.site_uuid'), (string) $website->uuid);
    }

    public function jobAllowed(Website $website, JobType $type, array $payload = []): bool
    {
        if (! $this->isEnabledFor($website)) {
            return false;
        }

        if ($this->fixture($website)?->purpose === config('m14_security_fixture.purpose')) {
            if ($type === JobType::SecurityScan) {
                return $this->securityScanAllowed($website, (string) ($payload['scan_type'] ?? ''));
            }

            // M14 may poll only harmless setup jobs. It never inherits M8 backup or restore entitlement.
            return in_array($type, [JobType::GetInventory, JobType::SiteHealth, JobType::LicenseCheck], true);
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

    public function m14SecurityFixtureEnabledFor(Website $website): bool
    {
        $fixture = $this->fixture($website);

        return $fixture?->purpose === config('m14_security_fixture.purpose')
            && $this->m14FixtureIdentityAllowed($website, $fixture);
    }

    private function m14FixtureIdentityAllowed(Website $website, SyntheticSiteFixture $fixture): bool
    {
        if (! $this->stagingEnabled()
            || ! (bool) config('m14_security_fixture.fixture_enabled')
            || $fixture->purpose !== config('m14_security_fixture.purpose')
            || ! is_string(config('m14_security_fixture.site_uuid'))
            || ! is_string(config('m14_security_fixture.customer_uuid'))
            || ! is_string(config('m14_security_fixture.site_host'))
            || ! preg_match('/^m14-security-[a-z0-9](?:[a-z0-9-]{0,46}[a-z0-9])?\.staging\.wpcaregrid\.com$/D', (string) config('m14_security_fixture.site_host'))
            || ! hash_equals((string) config('m14_security_fixture.site_uuid'), (string) $website->uuid)
            || $website->url !== 'https://'.(string) config('m14_security_fixture.site_host')
            || $fixture->environment !== 'staging'
            || ! $fixture->isActive()
            || $fixture->expires_at === null || ! $fixture->expires_at->isFuture()
            || $fixture->customer_id !== $website->customer_id
            || $fixture->backup_restore_entitled) {
            return false;
        }

        $website->loadMissing(['customer', 'subscription']);
        $customer = $website->customer;

        // A paid, billed, logged-in or multi-site customer can never be relabeled as the M14 fixture.
        return $customer?->active === true
            && hash_equals((string) config('m14_security_fixture.customer_uuid'), (string) $customer->uuid)
            && $customer->user_id === null
            && $website->subscription === null
            && ! $customer->subscriptions()->exists()
            && ! $customer->billingIdentities()->exists()
            && ! $customer->checkoutIntents()->exists()
            && ! $customer->billingInvoices()->exists()
            && $customer->websites()->count() === 1;
    }
}
