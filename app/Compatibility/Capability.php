<?php

namespace App\Compatibility;

enum Capability: string
{
    case ConnectionReady = 'CONNECTION_READY';
    case MonitoringReady = 'MONITORING_READY';
    case UpdateReady = 'UPDATE_READY';
    case BackupReady = 'BACKUP_READY';
    case RestoreReady = 'RESTORE_READY';
    case SecurityScanReady = 'SECURITY_SCAN_READY';
    case SecurityRemediationReady = 'SECURITY_REMEDIATION_READY';
    case AdminAccessReady = 'ADMIN_ACCESS_READY';
}
