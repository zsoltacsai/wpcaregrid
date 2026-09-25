<?php

/*
|--------------------------------------------------------------------------
| Dashboard interface text (English)
|--------------------------------------------------------------------------
|
| Used by the dashboard preview components on the marketing site, and meant
| to be reused by the authenticated dashboard.
|
*/

return [

    'demo_label' => 'Demo data',
    'example_label' => 'Example',

    'preview' => [
        'compact_title' => 'Sites overview',
        'compact_caption' => 'Example of the WP CareGrid dashboard, showing demo data.',
        'full_title' => 'CareGrid dashboard',
        'full_caption' => 'Example of the WP CareGrid dashboard overview, showing demo data.',
    ],

    'nav' => [
        'overview' => 'Overview',
        'sites' => 'Sites',
        'backups' => 'Backups',
        'updates' => 'Updates',
        'security' => 'Security',
        'licenses' => 'Licenses',
        'activity' => 'Activity log',
    ],

    'sites_heading' => 'Sites',
    'sites_count' => '{1} :count site|[2,*] :count sites',

    'columns' => [
        'site' => 'Site',
        'last_backup' => 'Last backup',
        'updates' => 'Updates',
        'security' => 'Security',
        'license' => 'License',
        'status' => 'Status',
    ],

    'platforms' => [
        'wordpress' => 'WordPress',
        'woocommerce' => 'WooCommerce',
    ],

    'days' => [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
    ],

    'backup_at' => 'Backup :when',

    'states' => [
        'updates' => [
            'none' => 'Up to date',
            'pending' => ':count pending',
        ],
        'security' => [
            'ok' => 'No issues',
            'issue' => 'Issue found',
        ],
        'license' => [
            'valid' => 'Valid',
            'expired' => 'Expired',
            'none' => 'No licenses',
        ],
    ],

    'latest_run' => 'Latest update run',
    'run_steps' => [
        'backup' => 'Backup',
        'update' => 'Update',
        'check' => 'Check',
        'functional' => 'Functional test',
    ],

    'pending_updates' => 'Pending updates',
    'shown_count' => ':count shown',
    'licenses' => 'Licenses',
    'expired_count' => ':count expired',
    'update_types' => [
        'plugin' => 'Plugin',
        'theme' => 'Theme',
        'core' => 'Core',
    ],

    'metrics' => [
        'backups' => ['label' => 'Successful backups', 'detail' => 'Last 7 days'],
        'updates' => ['label' => 'Updates pending', 'detail' => 'Across 4 sites'],
        'security' => ['label' => 'Security issue', 'detail' => 'shop.example.com'],
        'licenses' => ['label' => 'Expired licenses', 'detail' => 'Customer action required'],
    ],

    'license_notice' => [
        'type' => 'Plugin license',
        'website' => 'Website',
        'detected' => 'Detected',
        'status' => 'License expired',
        'action_title' => 'Customer action required.',
        'action_text' => 'Renew the license to keep receiving updates.',
    ],

    'activity' => [
        'events' => [
            'backup' => 'Backup',
            'plugin_update' => 'Plugin update',
            'functional_check' => 'Functional check',
            'license_check' => 'License check',
        ],
        'details' => [
            'backup_stored' => 'Files and database stored remotely',
            'plugin_updated' => ':name updated',
            'checkout_tested' => 'Cart, checkout and order email tested',
            'license_expired' => ':name license expired',
        ],
    ],

];
