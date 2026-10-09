<?php

declare(strict_types=1);

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Inventory\InventoryCollector;
use WPCareGrid\Connector\Licenses\LicenseDetector;
use WPCareGrid\Connector\Security\Logger;

$site = realpath(dirname(__DIR__, 3).'/storage/e2e/wordpress-site');
$statePath = dirname(__DIR__, 3).'/storage/e2e/connector-e2e-state.json';
if (! $site || ! is_file($site.'/wp-load.php') || ! is_file($statePath)) {
    fwrite(STDERR, "The isolated WordPress site and E2E state are required.\n");
    exit(2);
}

require $site.'/wp-load.php';
$state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
$plugin = Plugin::instance();
$mode = $argv[1] ?? 'pair-heartbeat';

if ($mode === 'reset') {
    $plugin->connection()->disconnect('admin');
    Settings::delete_all();
    fwrite(STDOUT, "Isolated WordPress connector options reset.\n");
    exit(0);
}

if ($mode === 'cleanup-fixtures') {
    $pluginDirs = ['e2e-active', 'e2e-current', 'e2e-stale'];
    foreach ($pluginDirs as $slug) {
        $directory = WP_PLUGIN_DIR.'/'.$slug;
        $marker = $directory.'/.wp-caregrid-e2e-fixture';
        if (is_file($marker) && hash_equals((string) $state['run_id'], (string) file_get_contents($marker))) {
            deactivate_plugins($slug.'/'.$slug.'.php', true);
            $real = realpath($directory);
            if ($real && dirname($real) === realpath(WP_PLUGIN_DIR) && basename($real) === $slug) {
                $remove = static function (string $path) use (&$remove): void {
                    if (is_dir($path) && ! is_link($path)) {
                        foreach (scandir($path) as $item) {
                            if ($item !== '.' && $item !== '..') {
                                $remove($path.'/'.$item);
                            }
                        }
                        rmdir($path);
                    } elseif (file_exists($path) || is_link($path)) {
                        unlink($path);
                    }
                };
                $remove($real);
            }
        }
    }
    $theme = WP_CONTENT_DIR.'/themes/e2e-active-theme';
    $themeMarker = $theme.'/.wp-caregrid-e2e-fixture';
    if (is_file($themeMarker) && hash_equals((string) $state['run_id'], (string) file_get_contents($themeMarker))) {
        switch_theme('twentytwentyfive');
        $real = realpath($theme);
        if ($real && dirname($real) === realpath(WP_CONTENT_DIR.'/themes') && basename($real) === 'e2e-active-theme') {
            $remove = static function (string $path) use (&$remove): void {
                if (is_dir($path) && ! is_link($path)) {
                    foreach (scandir($path) as $item) {
                        if ($item !== '.' && $item !== '..') {
                            $remove($path.'/'.$item);
                        }
                    }
                    rmdir($path);
                } elseif (file_exists($path) || is_link($path)) {
                    unlink($path);
                }
            };
            $remove($real);
        }
    }
    foreach (['update_plugins', 'update_themes', 'update_core'] as $transient) {
        delete_site_transient($transient);
    }
    wp_clean_plugins_cache(false);
    wp_clean_themes_cache(false);
    fwrite(STDOUT, "Only this run's marked plugin and theme fixtures were removed.\n");
    exit(0);
}

if ($mode === 'create-fixtures') {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $plugins = [
        'e2e-active' => ['E2E Active Fixture', '1.0.0', true],
        'e2e-current' => ['E2E Current Fixture', '2.0.0', false],
        'e2e-stale' => ['E2E Stale Fixture', '0.9.0', false],
    ];
    foreach ($plugins as $slug => [$name, $version, $active]) {
        $directory = WP_PLUGIN_DIR.'/'.$slug;
        if (file_exists($directory) && (! is_file($directory.'/.wp-caregrid-e2e-fixture')
            || file_get_contents($directory.'/.wp-caregrid-e2e-fixture') !== $state['run_id'])) {
            fwrite(STDERR, "Refusing to overwrite an unmarked local plugin fixture.\n");
            exit(2);
        }
        wp_mkdir_p($directory);
        file_put_contents($directory.'/.wp-caregrid-e2e-fixture', $state['run_id']);
        $source = "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n";
        file_put_contents($directory.'/'.$slug.'.php', $source);
        if ($active) {
            $activated = activate_plugin($slug.'/'.$slug.'.php');
            if (is_wp_error($activated)) {
                fwrite(STDERR, "Could not activate the marked local E2E plugin fixture.\n");
                exit(1);
            }
        }
    }

    $theme = WP_CONTENT_DIR.'/themes/e2e-active-theme';
    if (file_exists($theme) && (! is_file($theme.'/.wp-caregrid-e2e-fixture')
        || file_get_contents($theme.'/.wp-caregrid-e2e-fixture') !== $state['run_id'])) {
        fwrite(STDERR, "Refusing to overwrite an unmarked local theme fixture.\n");
        exit(2);
    }
    wp_mkdir_p($theme);
    file_put_contents($theme.'/.wp-caregrid-e2e-fixture', $state['run_id']);
    file_put_contents($theme.'/style.css', "/*\nTheme Name: E2E Active Theme\nVersion: 3.0.0\n*/\n");
    file_put_contents($theme.'/index.php', "<?php\n");
    switch_theme('e2e-active-theme');
    wp_clean_plugins_cache(false);
    wp_clean_themes_cache(false);
    fwrite(STDOUT, "Marked synthetic plugins and active theme installed locally.\n");
    exit(0);
}

if (str_starts_with($mode, 'updates-')) {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    require_once ABSPATH.'wp-admin/includes/theme.php';
    $scenario = substr($mode, strlen('updates-'));
    $pluginChecked = [];
    foreach (get_plugins() as $file => $data) {
        $pluginChecked[$file] = (string) ($data['Version'] ?? '');
    }
    $themesChecked = [];
    foreach (wp_get_themes() as $slug => $theme) {
        $themesChecked[$slug] = (string) $theme->get('Version');
    }

    if ($scenario === 'offer') {
        $pluginFile = 'e2e-active/e2e-active.php';
        $themeSlug = 'e2e-active-theme';
        $pluginChecked['e2e-stale/e2e-stale.php'] = '0.8.0'; // Stale: installed version changed after the last check.
        $pluginUpdates = (object) [
            'last_checked' => time(),
            'checked' => $pluginChecked,
            'response' => [$pluginFile => (object) ['slug' => 'e2e-active', 'plugin' => $pluginFile, 'new_version' => '1.1.0', 'url' => 'https://updates.example.invalid/e2e-active', 'package' => 'https://updates.example.invalid/e2e-active.zip']],
            'no_update' => [],
            'translations' => [],
        ];
        $themeUpdates = (object) [
            'last_checked' => time(),
            'checked' => $themesChecked,
            'response' => [$themeSlug => ['new_version' => '3.1.0', 'url' => 'https://themes.example.invalid/e2e-theme', 'package' => 'https://themes.example.invalid/e2e-theme.zip']],
            'no_update' => [],
        ];
        $coreUpdates = (object) ['updates' => [(object) ['response' => 'upgrade', 'current' => '7.1.3']], 'version_checked' => get_bloginfo('version'), 'last_checked' => time()];
    } elseif ($scenario === 'current') {
        $pluginUpdates = (object) ['last_checked' => time(), 'checked' => $pluginChecked, 'response' => [], 'no_update' => [], 'translations' => []];
        $themeUpdates = (object) ['last_checked' => time(), 'checked' => $themesChecked, 'response' => [], 'no_update' => []];
        $coreUpdates = (object) ['updates' => [(object) ['response' => 'latest', 'current' => get_bloginfo('version')]], 'version_checked' => get_bloginfo('version'), 'last_checked' => time()];
    } elseif ($scenario === 'unknown') {
        $pluginUpdates = (object) ['last_checked' => time(), 'checked' => [], 'response' => [], 'no_update' => [], 'translations' => []];
        $themeUpdates = (object) ['last_checked' => time(), 'checked' => [], 'response' => [], 'no_update' => []];
        $coreUpdates = (object) ['updates' => [], 'version_checked' => get_bloginfo('version'), 'last_checked' => time()];
    } else {
        fwrite(STDERR, "Supported deterministic update fixtures: offer, current, unknown.\n");
        exit(2);
    }

    set_site_transient('update_plugins', $pluginUpdates);
    set_site_transient('update_themes', $themeUpdates);
    set_site_transient('update_core', $coreUpdates);
    fwrite(STDOUT, "Synthetic update metadata '$scenario' installed without contacting update providers.\n");
    exit(0);
}

if ($mode === 'poll') {
    $summary = $plugin->runner()->poll();
    fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($mode === 'heartbeat') {
    $ok = $plugin->heartbeat()->send();
    fwrite(STDOUT, json_encode(['heartbeat_ok' => $ok, 'credential_preserved' => (new CredentialStore)->has()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit($ok ? 0 : 1);
}

if ($mode === 'health-probes') {
    $credential = (new CredentialStore)->get();
    $website = (string) Settings::get('website_uuid');
    $payload = $plugin->heartbeat()->payload();
    $badHealth = $payload;
    $badHealth['health'] = ['status' => 'impossibly_healthy', 'findings' => []];
    $invalid = $plugin->api()->post('heartbeat', $badHealth);
    $partial = $payload;
    $partial['health'] = ['status' => 'unknown', 'findings' => [['id' => 'partial_fixture', 'status' => 'unknown']]];
    $partialResponse = $plugin->api()->post('heartbeat', $partial);

    $url = rtrim(WP_CAREGRID_API_URL, '/').'/heartbeat';
    $request = static function (array $headers, array $body) use ($url): int {
        $response = wp_remote_request($url, [
            'method' => 'POST',
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'] + $headers,
            'body' => wp_json_encode($body),
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
        ]);

        return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    };
    $checks = [
        'malformed_health_rejected' => $invalid->status === 422,
        'partial_health_accepted' => $partialResponse->status === 200,
        'unauthenticated_health_rejected' => $request(['X-CareGrid-Website' => $website], $payload) === 401,
        'cross_tenant_health_rejected' => $request([
            'Authorization' => 'Bearer '.$credential,
            'X-CareGrid-Website' => '00000000-0000-4000-8000-000000000000',
        ], $payload) === 403,
        'credential_still_present' => (new CredentialStore)->has(),
    ];
    fwrite(STDOUT, json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(in_array(false, $checks, true) ? 1 : 0);
}

if ($mode === 'inventory-validation') {
    $inventory = (new InventoryCollector(new LicenseDetector))->collect();
    if (empty($inventory['plugins'])) {
        fwrite(STDERR, "The isolated WordPress fixture returned no plugins to validate.\n");
        exit(1);
    }
    $inventory['plugins'][0]['file'] = '../../wp-config.php';
    $response = $plugin->api()->post('inventory', $inventory);
    $checks = ['unsafe_inventory_rejected' => $response->status === 422, 'credential_preserved' => (new CredentialStore)->has()];
    fwrite(STDOUT, json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(in_array(false, $checks, true) ? 1 : 0);
}

if ($mode === 'malformed-update') {
    $updates = get_site_transient('update_plugins');
    $updates->response['e2e-active/e2e-active.php'] = (object) [
        'slug' => 'e2e-active',
        'new_version' => ['not', 'a', 'version'],
        'url' => 'https://updates.example.invalid/e2e-active',
    ];
    set_site_transient('update_plugins', $updates);
    $inventory = (new InventoryCollector(new LicenseDetector))->collect();
    $response = $plugin->api()->post('inventory', $inventory);
    fwrite(STDOUT, json_encode(['malformed_update_metadata_rejected' => $response->status === 422, 'response_status' => $response->status], JSON_THROW_ON_ERROR).PHP_EOL);
    exit($response->status === 422 ? 0 : 1);
}

if ($mode === 'remove-fixture-plugin') {
    deactivate_plugins('e2e-active/e2e-active.php', true);
    $directory = WP_PLUGIN_DIR.'/e2e-active';
    if (is_file($directory.'/.wp-caregrid-e2e-fixture')
        && hash_equals((string) $state['run_id'], (string) file_get_contents($directory.'/.wp-caregrid-e2e-fixture'))) {
        foreach (['e2e-active.php', '.wp-caregrid-e2e-fixture'] as $file) {
            @unlink($directory.'/'.$file);
        }
        @rmdir($directory);
    }
    wp_clean_plugins_cache(false);
    fwrite(STDOUT, "Marked active plugin fixture removed from the synthetic site.\n");
    exit(0);
}

if (in_array($mode, ['failure-unavailable', 'failure-response', 'failure-timeout', 'failure-recovery'], true)) {
    $before = (new CredentialStore)->get();
    $ok = $plugin->heartbeat()->send();
    $after = (new CredentialStore)->get();
    $entries = wp_json_encode(Logger::entries());
    $logClear = is_string($before) && ! str_contains($entries, $before) && ! str_contains($entries, (string) ($state['pairing_code'] ?? ''));
    $expected = $mode === 'failure-recovery' ? $ok : ! $ok;
    $checks = [
        'expected_heartbeat_result' => $expected,
        'credential_preserved' => is_string($before) && hash_equals($before, (string) $after),
        'logs_redacted' => $logClear,
    ];
    fwrite(STDOUT, json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(in_array(false, $checks, true) ? 1 : 0);
}

if ($mode === 'url-guard') {
    $checks = [
        'local_http_allowed' => Client::is_allowed_url('http://127.0.0.1:8317/api/v1/connector'),
        'remote_http_refused' => ! Client::is_allowed_url('http://example.invalid/api'),
        'https_remote_allowed_without_request' => Client::is_allowed_url('https://example.invalid/api'),
    ];
    fwrite(STDOUT, json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(in_array(false, $checks, true) ? 1 : 0);
}

if ($mode === 'auth-probes') {
    $credential = (new CredentialStore)->get();
    $website = (string) Settings::get('website_uuid');
    $url = rtrim(WP_CAREGRID_API_URL, '/').'/jobs';
    $request = static function (array $headers) use ($url): int {
        $response = wp_remote_request($url, [
            'method' => 'GET',
            'headers' => ['Accept' => 'application/json'] + $headers,
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
        ]);

        return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    };
    $checks = [
        'missing_credential_rejected' => $request(['X-CareGrid-Website' => $website]) === 401,
        'invalid_credential_rejected' => $request([
            'Authorization' => 'Bearer cgc_'.str_repeat('A', 48),
            'X-CareGrid-Website' => $website,
        ]) === 401,
        'wrong_tenant_scope_rejected' => $request([
            'Authorization' => 'Bearer '.$credential,
            'X-CareGrid-Website' => '00000000-0000-4000-8000-000000000000',
        ]) === 403,
    ];
    fwrite(STDOUT, json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(in_array(false, $checks, true) ? 1 : 0);
}

if ($mode === 'revocation') {
    $credential = (new CredentialStore)->get();
    if (! is_string($credential)) {
        fwrite(STDERR, "No isolated test credential is present.\n");
        exit(2);
    }

    $disconnect = $plugin->api()->post('disconnect');
    $refused = $plugin->api()->get('jobs');
    $plugin->heartbeat()->send(); // Handles CONNECTOR_REVOKED and clears local credential.
    $checks = [
        'disconnect_succeeded' => $disconnect->status === 200,
        'revoked_credential_refused' => $refused->status === 401,
        'plugin_cleared_revoked_credential' => ! (new CredentialStore)->has(),
    ];
    fwrite(STDOUT, json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(in_array(false, $checks, true) ? 1 : 0);
}

if ($mode !== 'pair-heartbeat' || ! is_string($state['pairing_code'] ?? null)) {
    fwrite(STDERR, "Unsupported mode or missing local pairing state.\n");
    exit(2);
}

// Exercise the plugin's production pairing and heartbeat services from a
// separate PHP process; the plugin's API client crosses HTTP to local Laravel.
$result = $plugin->pairing()->pair($state['pairing_code']);
if (! $result['ok']) {
    fwrite(STDERR, 'Plugin pairing was refused (code: '.($result['code'] ?? 'unknown').").\n");
    exit(1);
}

$credential = (new CredentialStore)->get();
if (! is_string($credential) || ! preg_match('/^cgc_[A-Za-z0-9]{48}$/', $credential)) {
    fwrite(STDERR, "Plugin did not persist a valid credential.\n");
    exit(1);
}
$ciphertext = (string) get_option('wp_caregrid_credential');
$jsonState = get_option(Settings::OPTION, []);
$encrypted = $ciphertext !== ''
    && ! str_contains($ciphertext, $credential)
    && ! str_contains(base64_decode($ciphertext, true) ?: '', $credential)
    && ! str_contains(wp_json_encode($jsonState), $credential);
if (! $encrypted) {
    fwrite(STDERR, "Credential at-rest encryption check failed.\n");
    exit(1);
}

$state['credential_hash'] = hash('sha256', $credential);
file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
chmod($statePath, 0600);

$heartbeat = $plugin->heartbeat()->send();
$entries = wp_json_encode(Logger::entries());
$logClear = ! str_contains($entries, $credential) && ! str_contains($entries, $state['pairing_code']);
$serverLog = dirname(__DIR__, 3).'/storage/logs/laravel.log';
$serverLogClear = ! is_file($serverLog) || (! str_contains((string) file_get_contents($serverLog), $credential)
    && ! str_contains((string) file_get_contents($serverLog), $state['pairing_code']));
if (! $heartbeat || ! $logClear || ! $serverLogClear) {
    fwrite(STDERR, "Authenticated heartbeat or log-redaction check failed.\n");
    exit(1);
}

fwrite(STDOUT, "Plugin HTTP pairing succeeded; encrypted credential stored; authenticated heartbeat succeeded; logs redacted.\n");
