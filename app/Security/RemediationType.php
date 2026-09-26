<?php

namespace App\Security;

use App\Connector\JobType;

/**
 * The only remediations there are. Each is one fixed connector job; clean
 * files come from WordPress.org, fetched and verified by the site itself,
 * never from a URL anyone supplies.
 */
enum RemediationType: string
{
    case QuarantineFile = 'quarantine_file';
    case RestoreQuarantinedFile = 'restore_quarantined_file';
    case RepairCoreFile = 'repair_core_file';
    case RepairPluginFile = 'repair_plugin_file';
    case DisablePlugin = 'disable_plugin';

    public function jobType(): JobType
    {
        return match ($this) {
            self::QuarantineFile => JobType::SecurityQuarantine,
            self::RestoreQuarantinedFile => JobType::SecurityRestoreQuarantine,
            self::RepairCoreFile, self::RepairPluginFile => JobType::SecurityRepairFile,
            self::DisablePlugin => JobType::SecurityDisablePlugin,
        };
    }

    /** Changes the site in a way that needs a verified full backup first. Undoing a quarantine does not. */
    public function needsBackup(): bool
    {
        return $this !== self::RestoreQuarantinedFile;
    }

    public function label(): string
    {
        return __('security.remediation_types.'.$this->value);
    }
}
