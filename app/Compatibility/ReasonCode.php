<?php

namespace App\Compatibility;

enum ReasonCode: string
{
    case ConnectorOffline = 'CONNECTOR_OFFLINE';
    case ConnectorUnsupported = 'CONNECTOR_UNSUPPORTED';
    case ConnectorUpdateRecommended = 'CONNECTOR_UPDATE_RECOMMENDED';
    case ConnectorNewerUnknown = 'CONNECTOR_NEWER_UNKNOWN';
    case RuntimeVersionUnsupported = 'RUNTIME_VERSION_UNSUPPORTED';
    case RuntimeVersionUntested = 'RUNTIME_VERSION_UNTESTED';
    case RuntimeVersionEol = 'RUNTIME_VERSION_EOL';
    case ExtensionMissing = 'PHP_EXTENSION_MISSING';
    case CapabilityMissing = 'CONNECTOR_CAPABILITY_MISSING';
    case FactMissing = 'FACT_MISSING';
    case FactStale = 'FACT_STALE';
    case FactMalformed = 'FACT_MALFORMED';
    case MultisiteUnsupported = 'MULTISITE_UNSUPPORTED';
    case CustomLayoutUnsupported = 'CUSTOM_LAYOUT_UNSUPPORTED';
    case CanonicalRootUnknown = 'CANONICAL_ROOT_UNKNOWN';
    case RootSymlinkUnsupported = 'ROOT_SYMLINK_UNRESOLVED';
    case FilesystemReadUnavailable = 'FILESYSTEM_READ_UNAVAILABLE';
    case FilesystemWriteUnavailable = 'FILESYSTEM_WRITE_UNAVAILABLE';
    case FilesystemRenameUnavailable = 'FILESYSTEM_RENAME_UNAVAILABLE';
    case DiskSpaceInsufficient = 'DISK_SPACE_INSUFFICIENT';
    case DiskSpaceUnknown = 'DISK_SPACE_UNKNOWN';
    case DatabaseUnsupported = 'DB_ENGINE_UNSUPPORTED';
    case DatabaseUnknown = 'DB_VERSION_UNKNOWN';
    case BackupNotVerified = 'BACKUP_NOT_VERIFIED';
    case BackupObjectMissing = 'BACKUP_OBJECT_MISSING';
    case RestoreConflict = 'RESTORE_CONFLICT';
    case ScannerUnavailable = 'SCANNER_UNAVAILABLE';
    case ScannerNotRun = 'SCANNER_NOT_RUN';
    case SafetyGateClosed = 'SAFETY_GATE_CLOSED';
}
