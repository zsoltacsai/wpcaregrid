<?php

namespace App\Compatibility;

use App\Backups\BackupType;
use App\Connector\Capability as ConnectorCapability;
use App\Connector\Presence;
use App\Models\Backup;
use App\Models\Connector;
use App\Models\Website;

/** Server-owned, per-operation policy. Connector facts are evidence, not authority. */
final class CapabilityEvaluator
{
    /** @return array<string, array{status: string, reasons: list<string>}> */
    public function evaluate(Website $website, ?Connector $connector, ?array $facts, bool $scannerConfigured = true, ?Backup $restoreBackup = null, string $restoreScope = 'full'): array
    {
        $facts ??= [];
        $connection = $this->connection($connector);
        $versionRead = $this->connectorVersion($connector, false);
        $versionWrite = $this->connectorVersion($connector, true);
        $monitoring = $this->combine($connection, $versionRead, $this->connectorCapability($connector, ConnectorCapability::Inventory));
        $healthCapability = $this->connectorCapability($connector, ConnectorCapability::Health);
        $monitoring = $this->combine($monitoring, $healthCapability);

        $layout = $this->layout($facts);
        $filesystem = $this->mutationFilesystem($facts);
        $tempFilesystem = $this->tempFilesystem($facts);
        $database = $this->database($facts);
        $runtime = $this->runtimeVersions($website);
        $disk = $this->backupDisk($facts);
        $update = $this->combine($connection, $versionWrite, $runtime, $this->connectorCapability($connector, ConnectorCapability::Updates), $layout, $filesystem, $tempFilesystem);
        $fileBackup = $this->combine($connection, $versionWrite, $runtime, $layout, $this->connectorCapability($connector, ConnectorCapability::BackupFiles), $this->readableLayout($facts), $tempFilesystem, $disk);
        $databaseBackup = $this->combine($connection, $versionWrite, $runtime, $layout, $this->connectorCapability($connector, ConnectorCapability::BackupDatabase), $database, $tempFilesystem, $disk);
        $backup = $this->anyUsable([$fileBackup, $databaseBackup]);
        $backup['scopes'] = ['files' => $fileBackup, 'database' => $databaseBackup];
        $restoreSource = $this->backupEvidence($website, $facts, $restoreBackup, $restoreScope);
        // Authorization, subscription, locks and active workflow conflicts are checked independently at dispatch.
        $restoreCapability = ConnectorCapability::tryFrom('restore_'.$restoreScope) ?? ConnectorCapability::RestoreFull;
        $restoreFs = $restoreScope === 'database' ? $tempFilesystem : $filesystem;
        $restoreDb = in_array($restoreScope, ['database', 'full'], true) ? $database : $this->result(CapabilityStatus::Pass);
        $restore = $this->combine($connection, $versionWrite, $runtime, $layout, $restoreFs, $restoreDb, $tempFilesystem, $this->connectorCapability($connector, $restoreCapability), $restoreSource);
        $scan = $this->combine($connection, $versionRead, $runtime, $layout, $this->connectorCapability($connector, ConnectorCapability::SecurityScan), $this->readableLayout($facts));
        if (! $scannerConfigured) {
            $scan = $this->combine($scan, $this->result(CapabilityStatus::Block, ReasonCode::ScannerUnavailable));
        }
        $remediation = $this->combine($connection, $versionWrite, $runtime, $layout, $filesystem, $tempFilesystem, $this->connectorCapability($connector, ConnectorCapability::SecurityRemediate));
        // Admin access relies on a supported single-site installation because the
        // temporary access flow must be scoped and cleaned up inside that layout.
        $admin = $this->combine($connection, $versionRead, $runtime, $layout, $this->connectorCapability($connector, ConnectorCapability::AdminAccess));

        return [
            Capability::ConnectionReady->value => $connection,
            Capability::MonitoringReady->value => $monitoring,
            Capability::UpdateReady->value => $update,
            Capability::BackupReady->value => $backup,
            Capability::RestoreReady->value => $restore,
            Capability::SecurityScanReady->value => $scan,
            Capability::SecurityRemediationReady->value => $remediation,
            Capability::AdminAccessReady->value => $admin,
        ];
    }

    private function connection(?Connector $connector): array
    {
        if (! $connector?->isActive()) {
            return $this->result(CapabilityStatus::Block, ReasonCode::ConnectorOffline);
        }

        return $connector->presence() === Presence::Connected
            ? $this->result(CapabilityStatus::Pass)
            : $this->result(CapabilityStatus::Unknown, ReasonCode::ConnectorOffline);
    }

    private function connectorCapability(?Connector $connector, ConnectorCapability $capability): array
    {
        if (! $connector) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::FactMissing);
        }

        return $connector->can($capability)
            ? $this->result(CapabilityStatus::Pass)
            : $this->result(CapabilityStatus::Block, ReasonCode::CapabilityMissing);
    }

    private function connectorVersion(?Connector $connector, bool $mutating): array
    {
        $version = $connector?->connector_version;
        if (! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::FactMissing);
        }

        $current = (string) config('connector.latest_version');
        if (version_compare($version, $current, '=')) {
            return $this->result(CapabilityStatus::Pass);
        }

        if (version_compare($version, $current, '>')) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::ConnectorNewerUnknown);
        }

        $minimum = (string) config($mutating ? 'compatibility.minimum_mutating_connector' : 'compatibility.minimum_read_connector');
        if (version_compare($version, $minimum, '<')) {
            return $this->result(CapabilityStatus::Block, ReasonCode::ConnectorUnsupported);
        }

        return $mutating
            ? $this->result(CapabilityStatus::Block, ReasonCode::ConnectorUpdateRecommended)
            : $this->result(CapabilityStatus::Warn, ReasonCode::ConnectorUpdateRecommended);
    }

    private function layout(array $facts): array
    {
        if (! array_key_exists('layout', $facts)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::FactMissing);
        }

        if (($facts['multisite'] ?? null) === true) {
            return $this->result(CapabilityStatus::Block, ReasonCode::MultisiteUnsupported);
        }
        if (($facts['root_symlink'] ?? null) === true) {
            return $this->result(CapabilityStatus::Block, ReasonCode::RootSymlinkUnsupported);
        }

        return match ($facts['layout']) {
            'standard' => $this->result(CapabilityStatus::Pass),
            'bedrock', 'custom' => $this->result(CapabilityStatus::Block, ReasonCode::CustomLayoutUnsupported),
            default => $this->result(CapabilityStatus::Unknown, ReasonCode::CanonicalRootUnknown),
        };
    }

    private function readableLayout(array $facts): array
    {
        if (($facts['filesystem']['read_root'] ?? null) === true && ($facts['filesystem']['read_content'] ?? null) === true) {
            return $this->result(CapabilityStatus::Pass);
        }

        return $this->result(CapabilityStatus::Unknown, ReasonCode::FilesystemReadUnavailable);
    }

    private function tempFilesystem(array $facts): array
    {
        $fs = $facts['filesystem'] ?? null;
        if (! is_array($fs)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::FactMissing);
        }
        if (($fs['write_probe'] ?? null) !== true) {
            return $this->result(($fs['write_probe'] ?? null) === false ? CapabilityStatus::Block : CapabilityStatus::Unknown, ReasonCode::FilesystemWriteUnavailable);
        }
        if (($fs['rename_probe'] ?? null) !== true) {
            return $this->result(($fs['rename_probe'] ?? null) === false ? CapabilityStatus::Block : CapabilityStatus::Unknown, ReasonCode::FilesystemRenameUnavailable);
        }

        return $this->result(CapabilityStatus::Pass);
    }

    private function mutationFilesystem(array $facts): array
    {
        $temp = $this->tempFilesystem($facts);
        if ($temp['status'] !== CapabilityStatus::Pass->value) {
            return $temp;
        }
        $fs = $facts['filesystem'] ?? [];
        if (($fs['writable_root'] ?? null) !== true || ($fs['writable_content'] ?? null) !== true) {
            return $this->result((($fs['writable_root'] ?? null) === false || ($fs['writable_content'] ?? null) === false) ? CapabilityStatus::Block : CapabilityStatus::Unknown, ReasonCode::FilesystemWriteUnavailable);
        }

        return $this->result(CapabilityStatus::Pass);
    }

    private function runtimeVersions(Website $website): array
    {
        $php = $website->php_version;
        $wp = $website->wordpress_version;
        $phpStatus = ! is_string($php) || ! preg_match('/^\d+\.\d+(?:\.\d+)?$/', $php)
            ? $this->result(CapabilityStatus::Unknown, ReasonCode::FactMissing)
            : (version_compare($php, config('compatibility.declared_minimum_php'), '<')
                ? $this->result(CapabilityStatus::Block, ReasonCode::RuntimeVersionUnsupported)
                : ($this->isTestedBranch($php, (array) config('compatibility.tested_php_branches', []))
                    ? $this->result(CapabilityStatus::Pass)
                    : $this->result(CapabilityStatus::Unknown, ReasonCode::RuntimeVersionUntested)));
        $wpStatus = ! is_string($wp) || ! preg_match('/^\d+\.\d+(?:\.\d+)?$/', $wp)
            ? $this->result(CapabilityStatus::Unknown, ReasonCode::FactMissing)
            : (version_compare($wp, config('compatibility.declared_minimum_wordpress'), '<')
                ? $this->result(CapabilityStatus::Block, ReasonCode::RuntimeVersionUnsupported)
                : ($this->isTestedBranch($wp, (array) config('compatibility.tested_wordpress_branches', []))
                    ? $this->result(CapabilityStatus::Pass)
                    : $this->result(CapabilityStatus::Unknown, ReasonCode::RuntimeVersionUntested)));

        return $this->combine($phpStatus, $wpStatus);
    }

    private function backupDisk(array $facts): array
    {
        $free = $facts['disk_free_bytes'] ?? null;
        if (! is_int($free)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::DiskSpaceUnknown);
        }

        return $free <= (int) config('compatibility.disk_reserve_bytes')
            ? $this->result(CapabilityStatus::Block, ReasonCode::DiskSpaceInsufficient)
            : $this->result(CapabilityStatus::Pass);
    }

    private function database(array $facts): array
    {
        $db = $facts['database'] ?? null;
        if (! is_array($db)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::DatabaseUnknown);
        }
        if (! in_array($db['engine'] ?? null, ['mysql', 'mariadb'], true)) {
            return $this->result(($db['engine'] ?? null) === 'unknown' ? CapabilityStatus::Unknown : CapabilityStatus::Block, ReasonCode::DatabaseUnsupported);
        }
        if (! is_string($db['version'] ?? null) || $db['version'] === '') {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::DatabaseUnknown);
        }

        $engine = $db['engine'];
        $testedBranches = (array) config('compatibility.tested_'.$engine.'_branches', []);
        if (! $this->isTestedBranch($db['version'], $testedBranches)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::DatabaseUnknown);
        }
        if (($facts['extensions']['mysqli'] ?? null) !== true) {
            return $this->result(($facts['extensions']['mysqli'] ?? null) === false ? CapabilityStatus::Block : CapabilityStatus::Unknown, ReasonCode::ExtensionMissing);
        }

        return $this->result(CapabilityStatus::Pass);
    }

    /** A version branch is only supported after its fixture has passed. */
    private function isTestedBranch(string $version, array $branches): bool
    {
        if (! preg_match('/^(\d+\.\d+)/', $version, $matches)) {
            return false;
        }

        return in_array($matches[1], $branches, true);
    }

    private function backupEvidence(Website $website, array $facts, ?Backup $selected = null, string $scope = 'full'): array
    {
        $type = BackupType::tryFrom($scope) ?? BackupType::Full;
        $backup = $selected ?? $website->backups()->usableRecoveryPoint()->whereIn('type', $type === BackupType::Full ? ['full'] : [$type->value, 'full'])->latest('verified_at')->first();
        if ($backup && $backup->website_id !== $website->id) {
            return $this->result(CapabilityStatus::Block, ReasonCode::BackupNotVerified);
        }
        if (! $backup || ! $backup->isRestorable() || ! $backup->type->canRestore($type)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::BackupNotVerified);
        }

        $required = $type->objects();
        $objects = $backup->objects()->whereIn('name', $required)->where('status', 'verified')->pluck('name')->all();
        if (array_diff($required, $objects) !== []) {
            return $this->result(CapabilityStatus::Block, ReasonCode::BackupObjectMissing);
        }

        $free = $facts['disk_free_bytes'] ?? null;
        if (! is_int($free)) {
            return $this->result(CapabilityStatus::Unknown, ReasonCode::DiskSpaceUnknown);
        }
        $compressed = max(0, (int) $backup->total_size);
        $expanded = in_array('files', $required, true) ? max(0, (int) data_get($backup->manifest, 'files.uncompressed_size', 0)) : 0;
        $database = in_array('database', $required, true) ? max(0, (int) data_get($backup->manifest, 'database.size', 0)) : 0;
        $reserve = (int) config('compatibility.disk_reserve_bytes');
        // Restore staging, expanded files, SQL staging and a conservative free-space reserve.
        if ($free < ($compressed + $expanded + $database + $reserve)) {
            return $this->result(CapabilityStatus::Block, ReasonCode::DiskSpaceInsufficient);
        }

        return $this->result(CapabilityStatus::Pass);
    }

    /** @return array{status: string, reasons: list<string>} */
    private function result(CapabilityStatus $status, ?ReasonCode $reason = null): array
    {
        return ['status' => $status->value, 'reasons' => $reason ? [$reason->value] : []];
    }

    /** A capability is available if at least one explicitly supported scope works. */
    private function anyUsable(array $results): array
    {
        $statuses = array_column($results, 'status');
        $status = match (true) {
            in_array(CapabilityStatus::Pass->value, $statuses, true) => CapabilityStatus::Pass,
            in_array(CapabilityStatus::Warn->value, $statuses, true) => CapabilityStatus::Warn,
            in_array(CapabilityStatus::Unknown->value, $statuses, true) => CapabilityStatus::Unknown,
            default => CapabilityStatus::Block,
        };

        return ['status' => $status->value, 'reasons' => array_values(array_unique(array_merge(...array_column($results, 'reasons'))))];
    }

    /** @param array<int, array{status: string, reasons: list<string>}> $results */
    private function combine(array ...$results): array
    {
        $statuses = array_column($results, 'status');
        $status = match (true) {
            in_array(CapabilityStatus::Block->value, $statuses, true) => CapabilityStatus::Block,
            in_array(CapabilityStatus::Unknown->value, $statuses, true) => CapabilityStatus::Unknown,
            in_array(CapabilityStatus::Warn->value, $statuses, true) => CapabilityStatus::Warn,
            default => CapabilityStatus::Pass,
        };

        return ['status' => $status->value, 'reasons' => array_values(array_unique(array_merge(...array_column($results, 'reasons'))))];
    }
}
