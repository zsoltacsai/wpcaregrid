<?php

use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Settings;

/**
 * Removing the plugin: tell WP CareGrid (best effort) and delete every local
 * trace, including the encrypted credential.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__.'/wp-caregrid-connector.php';

Plugin::instance()->connection()->disconnect('uninstalled');
Settings::delete_all();
