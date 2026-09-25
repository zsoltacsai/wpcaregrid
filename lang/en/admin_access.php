<?php

return [

    'levels' => [
        'maintenance' => 'Maintenance access',
        'full_admin' => 'Temporary full admin',
    ],

    'statuses' => [
        'pending' => 'Opening',
        'authorized' => 'Starting',
        'active' => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
        'failed' => 'Failed',
    ],

    'refusals' => [
        'NOT_AUTHORIZED' => 'You are not allowed to open this website\'s admin.',
        'FULL_ADMIN_REQUIRES_CUSTOMER' => 'Full admin access needs the customer\'s own confirmation.',
        'SUBSCRIPTION_INACTIVE' => 'Admin access needs an active subscription.',
        'NOT_CONNECTED' => 'The website must be connected to WP CareGrid.',
        'CONNECTOR_OFFLINE' => 'The website is not reporting in right now. Check that it is online and try again.',
        'CAPABILITY_MISSING' => 'Update the WP CareGrid Connector on your website to use admin access.',
        'LEVEL_NOT_ALLOWED' => 'Your plan does not include this access level.',
        'CONFIRMATION_REQUIRED' => 'Full admin access needs your explicit confirmation.',
        'TOO_MANY_REQUESTS' => 'Admin access was opened too often recently. Please wait a while.',
        'default' => 'Admin access is not possible right now.',
    ],

    'account' => [
        'panel' => 'WordPress admin',
        'lead' => 'Open your WordPress admin without sharing a password. Access is temporary and can be revoked at any time.',
        'none' => 'No active admin session',
        'active' => 'Admin access active',
        'opening' => 'Waiting for the website',
        'expires_in' => 'Expires in :minutes min',
        'expires_soon' => 'Expires in less than a minute',
        'maintenance_title' => 'Maintenance access',
        'maintenance_text' => 'Updates and troubleshooting. No user management, no code editing, no plugin uploads. :minutes minutes.',
        'full_title' => 'Temporary full admin',
        'full_text' => 'Full administrator rights for :minutes minutes. Only when maintenance access is not enough.',
        'open' => 'Open Admin',
        'enable_full' => 'Enable temporary full admin',
        'revoke' => 'Revoke access',
        'revoked' => 'Access revoked. The WordPress session ends at the website\'s next check-in, at the latest when it expires.',
        'no_password' => 'No WordPress password is stored or shared: the connector creates a short, separate session.',
    ],

    'confirm' => [
        'title' => 'Temporary full admin',
        'question' => 'Grant temporary full administrator access for :minutes minutes?',
        'warning' => 'Full administrator access can change everything on the website, including users and settings. Use maintenance access unless you need more.',
        'points' => [
            'Access ends automatically after :minutes minutes.',
            'You can revoke it at any time from this page.',
            'Every step is recorded in the website\'s activity log.',
        ],
        'checkbox' => 'I authorize temporary full administrator access to :host.',
        'submit' => 'Grant full admin for :minutes minutes',
        'cancel' => 'Cancel',
        'not_possible' => 'Full admin access is not possible now',
    ],

    'opening' => [
        'title' => 'Opening WordPress admin…',
        'text' => 'You are being signed in to :host. This takes a moment.',
        'continue' => 'Continue to WordPress admin',
        'noscript' => 'Press the button to continue.',
    ],

    'events' => [
        'requested' => 'Admin access requested',
        'authorized' => 'Admin access authorized',
        'started' => 'Admin session started',
        'expired' => 'Admin access expired',
        'revoked' => 'Admin access revoked',
        'failed' => 'Admin access failed',
    ],

];
