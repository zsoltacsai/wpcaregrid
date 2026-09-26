<?php

namespace App\Operator;

/**
 * What an operator may do. Every permission is granted explicitly per
 * operator; being an operator grants nothing by itself. Each one is also a
 * gate, "operator.{value}", checked on every operator route.
 *
 * Full WordPress administrator access (AdminAccessFull) is separate from
 * maintenance access and is checked again inside AdminAccessService.
 */
enum Permission: string
{
    case WebsitesView = 'websites.view';
    case WebsitesManage = 'websites.manage';
    case BackupsView = 'backups.view';
    case BackupsRun = 'backups.run';
    case BackupsRestore = 'backups.restore';
    case UpdatesView = 'updates.view';
    case UpdatesRun = 'updates.run';
    case SecurityView = 'security.view';
    case SecurityScan = 'security.scan';
    // Finding decisions (confirm, false positive, ignore) and incidents.
    case SecurityManage = 'security.manage';
    // Quarantine, repair and plugin deactivation (behind a verified backup and the remediation switch).
    case SecurityRemediate = 'security.remediate';
    case AdminAccessRequest = 'admin_access.request';
    case AdminAccessFull = 'admin_access.full';
    case LicensesView = 'licenses.view';
    case AuditView = 'audit.view';

    public function gate(): string
    {
        return 'operator.'.$this->value;
    }

    public function label(): string
    {
        return __('operator.permissions.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A sensible set for a day-to-day operator: everything except full
     * admin and changing files on sites (remediation).
     *
     * @return list<self>
     */
    public static function standard(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => ! in_array($p, [self::AdminAccessFull, self::SecurityRemediate], true)));
    }
}
