<?php

/*
| Security & Malware Engine wording, shared by the operator console and the
| customer area. Customer texts explain what to do; they never show rules,
| evidence or file contents.
*/
return [

    'scan_types' => [
        'vulnerability' => 'Vulnerability scan',
        'integrity' => 'Integrity scan',
        'malware' => 'Malware scan',
        'full' => 'Full scan',
    ],

    'scan_statuses' => [
        'queued' => 'Queued',
        'running' => 'Running',
        'completed' => 'Completed',
        'partial' => 'Partial',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'coverage' => [
        'completed' => 'Completed',
        'partial' => 'Partial',
        'unavailable' => 'Not available',
        'failed' => 'Failed',
    ],

    'scanners' => [
        'vulnerability' => 'Vulnerabilities',
        'integrity' => 'Integrity',
        'malware' => 'Malware indicators',
    ],

    'unavailable_reasons' => [
        'VULNERABILITY_SOURCE_NOT_CONFIGURED' => 'Vulnerability scanner not configured.',
        'NO_INVENTORY' => 'The site has not reported its plugins and themes yet.',
    ],

    'finding_types' => [
        'vulnerability' => 'Vulnerability',
        'integrity' => 'Integrity',
        'suspicious_file' => 'Suspicious file',
        'suspicious_database' => 'Suspicious database entry',
        'suspicious_user' => 'Suspicious user',
        'suspicious_cron' => 'Suspicious scheduled task',
        'configuration' => 'Configuration',
    ],

    'finding_statuses' => [
        'open' => 'Open',
        'confirmed' => 'Confirmed',
        'quarantined' => 'Quarantined',
        'remediated' => 'Remediated',
        'false_positive' => 'False positive',
        'ignored' => 'Ignored',
        'resolved' => 'Resolved',
    ],

    'severities' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
        'unrated' => 'Not rated',
    ],

    'confidences' => [
        'low' => 'Low confidence',
        'medium' => 'Medium confidence',
        'high' => 'High confidence',
        'confirmed' => 'Confirmed',
    ],

    'incident_statuses' => [
        'open' => 'Open',
        'investigating' => 'Investigating',
        'containment' => 'Containment',
        'remediation' => 'Remediation',
        'verification' => 'Verification',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],

    'remediation_types' => [
        'quarantine_file' => 'Quarantine file',
        'restore_quarantined_file' => 'Undo quarantine',
        'repair_core_file' => 'Restore clean WordPress file',
        'repair_plugin_file' => 'Restore clean plugin file',
        'disable_plugin' => 'Deactivate plugin',
    ],

    'remediation_statuses' => [
        'awaiting_backup' => 'Waiting for backup',
        'queued' => 'Queued',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'blocked' => 'Blocked: backup required',
        'cancelled' => 'Cancelled',
    ],

    'states' => [
        'healthy' => 'Healthy',
        'attention' => 'Attention',
        'critical' => 'Critical',
        'unknown' => 'Unknown',
    ],

    // Customer area: what it means and what happens next. No rule names, paths or evidence.
    'customer' => [
        'title' => 'Security',
        'last_scan' => 'Last scan',
        'never' => 'Not scanned yet',
        'open_findings' => 'Open findings',
        'states' => [
            'healthy' => 'No open security findings from the latest scan.',
            'attention' => 'We found something that needs a look. Our team reviews every finding.',
            'critical' => 'We found a serious security problem. Our team is on it and will contact you.',
            'unknown' => 'The security state is not known right now: the latest scan did not complete or is too old.',
        ],
        'not_scanned' => 'Security scan is not configured yet.',
        'issues' => '{1} :count issue requires attention.|[2,*] :count issues require attention.',
        'types' => [
            'vulnerability' => 'Known vulnerabilities in installed software. Updating usually fixes them.',
            'integrity' => 'Files that differ from their official versions.',
            'suspicious_file' => 'Files with signs of malicious code, under review by our team.',
            'suspicious_database' => 'Database entries with signs of injected code, under review by our team.',
            'suspicious_user' => 'Administrator accounts to double-check.',
            'suspicious_cron' => 'Scheduled tasks to double-check.',
            'configuration' => 'Settings that weaken security.',
        ],
        'vulnerability_item' => ':name :version: update to :patched',
        'vulnerability_item_unpatched' => ':name :version: no fixed version yet',
        'not_configured' => 'Vulnerability checks are not set up yet, so they are not included.',
        'disclaimer' => 'Scanning finds known problems and warning signs. It cannot prove a website is completely secure.',
    ],

];
