<?php

namespace WPCareGrid\Connector\Auth;

/** A random ID for this installation, created once. Not a secret. */
final class InstallationId
{
    private const OPTION = 'wp_caregrid_installation_id';

    public static function get(): string
    {
        $id = get_option(self::OPTION);

        if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/', $id)) {
            $id = wp_generate_uuid4();
            update_option(self::OPTION, $id, false);
        }

        return $id;
    }
}
