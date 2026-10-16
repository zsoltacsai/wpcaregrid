<?php

use WPCareGrid\Connector\Core\Activator;
use WPCareGrid\Connector\Core\Deactivator;
use WPCareGrid\Connector\Core\Plugin;

/**
 * Plugin Name:       WP CareGrid Connector
 * Plugin URI:        https://wpcaregrid.com
 * Description:       Connects this website to WP CareGrid for maintenance: inventory, health checks, approved updates and security scans. Uses a dedicated site credential; no WordPress, hosting or database password is needed.
 * Version:           0.4.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            WP CareGrid
 * Author URI:        https://wpcaregrid.com
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-caregrid
 * Update URI:        https://wpcaregrid.com/connector
 */
defined('ABSPATH') || exit;

$release = json_decode((string) file_get_contents(__DIR__.'/release.json'), true);
define('WP_CAREGRID_VERSION', is_array($release) && is_string($release['version'] ?? null) ? $release['version'] : '0.0.0');
define('WP_CAREGRID_FILE', __FILE__);
define('WP_CAREGRID_DIR', __DIR__);

// Central API. Override in wp-config.php only for development.
if (! defined('WP_CAREGRID_API_URL')) {
    define('WP_CAREGRID_API_URL', 'https://wpcaregrid.com/api/v1/connector');
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'WPCareGrid\\Connector\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $file = __DIR__.'/includes/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
);

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

add_action('plugins_loaded', [Plugin::class, 'boot']);
