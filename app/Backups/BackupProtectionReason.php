<?php

namespace App\Backups;

enum BackupProtectionReason: string
{
    case GfsDaily = 'GFS_DAILY';
    case GfsWeekly = 'GFS_WEEKLY';
    case GfsMonthly = 'GFS_MONTHLY';
    case SafetyBackup = 'SAFETY_BACKUP';
    case LastKnownGood = 'LAST_KNOWN_GOOD';
    case ActiveRestore = 'ACTIVE_RESTORE';
    case Compensation = 'COMPENSATION';
    case UpdateDependency = 'UPDATE_DEPENDENCY';
    case SecurityRemediationDependency = 'SECURITY_REMEDIATION_DEPENDENCY';
    case IncidentHold = 'INCIDENT_HOLD';
    case OwnerExtension = 'OWNER_EXTENSION';
    case LegalHold = 'LEGAL_HOLD';
    case AccountingHold = 'ACCOUNTING_HOLD';
    case ProviderStateUnknown = 'PROVIDER_STATE_UNKNOWN';
}
