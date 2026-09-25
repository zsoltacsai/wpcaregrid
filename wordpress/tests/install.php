<?php

/**
 * Installs a fresh WordPress site into WP_CAREGRID_TEST_ROOT and activates
 * the connector, like an administrator would. Runs in its own process
 * (WordPress cannot be installed and then used in the same request).
 */
require __DIR__.'/wp-tests-config.php';

define('WP_INSTALLING', true);

require ABSPATH.'wp-settings.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

$result = wp_install('CareGrid Test Site', 'admin', 'admin@site.example', false, '', wp_generate_password(32));

if (is_wp_error($result) || empty($result['user_id'])) {
    fwrite(STDERR, "WordPress install failed.\n");
    exit(1);
}

update_option('home', 'https://site.example');
update_option('siteurl', 'https://site.example');
update_option('permalink_structure', '/%postname%/');

// Activation runs the plugin's real activation hook.
$activated = activate_plugin('wp-caregrid-connector/wp-caregrid-connector.php');

if (is_wp_error($activated)) {
    fwrite(STDERR, 'Plugin activation failed: '.$activated->get_error_message()."\n");
    exit(1);
}

echo 'WordPress '.get_bloginfo('version').' installed, connector active.'.PHP_EOL;
