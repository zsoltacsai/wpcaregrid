<?php

declare(strict_types=1);

use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Jobs\ControlledPollCommand;

// This entrypoint is a local CLI process only. It never registers an HTTP route.
$standalone = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

if (! defined('ABSPATH')) {
    $wpLoad = dirname(__DIR__, 4).'/wp-load.php';
    if (! is_file($wpLoad) || ! is_readable($wpLoad)) {
        fwrite(STDERR, "WordPress bootstrap is unavailable for this Connector install.\n");
        if ($standalone) {
            exit(2);
        }

        return false;
    }

    require_once $wpLoad;
}

if (! class_exists(Plugin::class)) {
    fwrite(STDERR, "WP CareGrid Connector is not active in this WordPress site.\n");
    if ($standalone) {
        exit(2);
    }

    return false;
}

try {
    $summary = (new ControlledPollCommand(Plugin::instance()))->runOnce();
} catch (RuntimeException) {
    fwrite(STDERR, "Controlled polling is not authorized for this paired synthetic site.\n");
    if ($standalone) {
        exit(3);
    }

    return false;
}

if ($standalone) {
    fwrite(STDOUT, sprintf("Single controlled poll complete; jobs executed: %d; results accepted: %d.\n", $summary['executed'], $summary['delivered']));
    exit(0);
}

return $summary;
