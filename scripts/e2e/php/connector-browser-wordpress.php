<?php

declare(strict_types=1);

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Settings;

$repo = dirname(__DIR__, 3);
$site = realpath($repo.'/storage/e2e/wordpress-site');
$statePath = $repo.'/storage/e2e/connector-browser-state.json';
$mode = $argv[1] ?? '';
$localHome = 'http://127.0.0.1:8318';

if (! $site || ! is_file($site.'/wp-load.php')) {
    fwrite(STDERR, "The isolated WordPress site is required.\n");
    exit(2);
}

require $site.'/wp-load.php';
$plugin = Plugin::instance();

if ($mode === 'reset') {
    $plugin->connection()->disconnect('browser_e2e_cleanup');
    Settings::delete_all();
    delete_option('wp_caregrid_e2e_home_url');
    fwrite(STDOUT, "Reset only the isolated Connector settings and synthetic home URL.\n");
    exit(0);
}

if (! is_file($statePath)) {
    fwrite(STDERR, "The browser fixture is required for this operation.\n");
    exit(2);
}
$state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($state) || empty($state['run_id'])) {
    fwrite(STDERR, "The browser fixture marker is invalid.\n");
    exit(2);
}

if ($mode === 'set-home') {
    $url = (string) ($argv[2] ?? '');
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if (! preg_match('/^https:\/\/e2e-[a-z0-9-]+\.example\.com$/D', $url)
        || ! str_ends_with($host, '.example.com') || wp_parse_url($url, PHP_URL_PORT) !== null) {
        fwrite(STDERR, "The synthetic WordPress home must be an HTTPS reserved example.com hostname.\n");
        exit(3);
    }
    update_option('wp_caregrid_e2e_home_url', $url);
    fwrite(STDOUT, "Configured the synthetic reserved WordPress home URL.\n");
    exit(0);
}

if ($mode === 'restore-home') {
    delete_option('wp_caregrid_e2e_home_url');
    fwrite(STDOUT, "Restored the isolated WordPress home URL.\n");
    exit(0);
}

if ($mode === 'state') {
    $store = new CredentialStore;
    $report = [
        'status' => Settings::get('status'),
        'connected' => Settings::is_connected(),
        'credential_present' => $store->has(),
        'website_uuid' => Settings::get('website_uuid'),
        'last_success_at' => Settings::get('last_success_at'),
        'last_error' => Settings::get('last_error'),
        'synthetic_home_url' => get_option('wp_caregrid_e2e_home_url'),
    ];
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($mode === 'cleanup') {
    $plugin->connection()->disconnect('browser_e2e_cleanup');
    Settings::delete_all();
    delete_option('wp_caregrid_e2e_home_url');
    fwrite(STDOUT, "Reset only the isolated Connector settings and reserved home URL.\n");
    exit(0);
}

fwrite(STDERR, "Supported modes: set-home, restore-home, state, cleanup.\n");
exit(2);
