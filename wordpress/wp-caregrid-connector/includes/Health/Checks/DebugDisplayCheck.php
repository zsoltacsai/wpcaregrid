<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

/** Errors shown to visitors on a production site. */
final class DebugDisplayCheck implements Check
{
    public function run(): Finding
    {
        $displaying = defined('WP_DEBUG') && WP_DEBUG && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);

        return $displaying && wp_get_environment_type() === 'production'
            ? new Finding('debug_display', Finding::WARNING, 'Error display', 'PHP errors are shown to visitors.')
            : new Finding('debug_display', Finding::PASS, 'Error display', 'Errors are not shown to visitors.');
    }
}
