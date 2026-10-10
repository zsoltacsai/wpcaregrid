<?php

namespace WPCareGrid\Connector\Core;

/** Site-bound opt-in for a separately launched WP-CLI poll process. */
final class ControlledExecution
{
    public static function locallyBound(): bool
    {
        if (! defined('WP_CAREGRID_CONTROLLED_EXECUTION') || WP_CAREGRID_CONTROLLED_EXECUTION !== true
            || ! defined('WP_CAREGRID_CONTROLLED_SITE_UUID') || ! is_string(WP_CAREGRID_CONTROLLED_SITE_UUID)) {
            return false;
        }

        $expected = strtolower(WP_CAREGRID_CONTROLLED_SITE_UUID);

        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $expected)
            && Settings::is_connected()
            && hash_equals($expected, strtolower((string) Settings::get('website_uuid')));
    }

    public static function authorized(): bool
    {
        return self::locallyBound()
            && Settings::get('synthetic_site') === true
            && Settings::get('controlled_execution') === true;
    }
}
