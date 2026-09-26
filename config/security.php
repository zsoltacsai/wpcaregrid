<?php

/*
|--------------------------------------------------------------------------
| Security & Malware Engine (docs/SECURITY_ENGINE.md)
|--------------------------------------------------------------------------
*/

return [

    // Server-side engine version, recorded on every scan (findings are reproducible).
    'scanner_version' => '1.0.0',

    'scan' => [
        // Seconds the site may spend on one scan part before it stops and
        // reports what it has; the next part continues where it stopped.
        'time_budget' => (int) env('SECURITY_SCAN_TIME_BUDGET', 60),

        // Parts before a scan stops as "partial" (large sites).
        'max_parts' => (int) env('SECURITY_SCAN_MAX_PARTS', 20),

        // A scan with no progress for this long fails with SCAN_TIMEOUT (security:monitor).
        'timeout_minutes' => (int) env('SECURITY_SCAN_TIMEOUT_MINUTES', 120),

        // Findings accepted per reported part.
        'max_findings_per_part' => 500,

        // A result older than this no longer counts for "healthy" (Site Health).
        'fresh_for_days' => (int) env('SECURITY_SCAN_FRESH_FOR_DAYS', 35),
    ],

    /*
    | Vulnerability data. Nothing is reported without a configured, trusted
    | source; "not configured" is shown instead, never "no vulnerabilities".
    |
    |   none       no source (default)
    |   wordfence  a Wordfence Intelligence feed file (v2 JSON), refreshed by
    |              `php artisan security:vulnerabilities:sync` when a feed
    |              URL (and key, if the feed needs one) is configured
    */
    'vulnerabilities' => [
        'source' => env('SECURITY_VULNERABILITY_SOURCE', 'none'),
        'feed_path' => env('SECURITY_VULNERABILITY_FEED_PATH', storage_path('app/private/security/vulnerabilities.json')),
        'feed_url' => env('SECURITY_VULNERABILITY_FEED_URL'),
        'feed_key' => env('SECURITY_VULNERABILITY_FEED_KEY'),
        // A feed older than this is treated as unavailable.
        'max_age_hours' => (int) env('SECURITY_VULNERABILITY_MAX_AGE_HOURS', 72),
    ],

    /*
    | Scheduled scans. Off until enabled. Frequencies are not invented here:
    | "plan" uses the plan's own security_check_frequency from
    | config/pricing.php; a fixed daily/weekly/monthly or null (never) may be
    | set per scan type once the business decides.
    */
    'schedule' => [
        'enabled' => (bool) env('SECURITY_SCHEDULE_ENABLED', false),
        'scans' => [
            'full' => 'plan',
            'vulnerability' => null,
            'integrity' => null,
            'malware' => null,
        ],
    ],

    'incidents' => [
        // Open an incident automatically for high-confidence, high/critical
        // suspicious files (detection → incident; nothing destructive).
        'auto_open' => (bool) env('SECURITY_INCIDENT_AUTO_OPEN', true),
        // ...and start a verified full backup for the investigation.
        'auto_backup' => (bool) env('SECURITY_INCIDENT_AUTO_BACKUP', true),
    ],

    /*
    | Remediation changes files on customer sites. OFF by default and must
    | stay off in production until the checklist in SECURITY_INCIDENTS.md is
    | complete. Every change needs a verified full backup first.
    */
    'remediation' => [
        'enabled' => (bool) env('SECURITY_REMEDIATION_ENABLED', false),
        // A verified full backup at most this old counts; otherwise a new one is made first.
        'backup_max_age_minutes' => (int) env('SECURITY_REMEDIATION_BACKUP_MAX_AGE', 60),
        // Deactivating a plugin can break a site (checkout, forms).
        'allow_disable_plugin' => (bool) env('SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN', false),
    ],

];
