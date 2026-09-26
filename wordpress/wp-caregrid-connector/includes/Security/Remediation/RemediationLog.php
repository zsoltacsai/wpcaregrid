<?php

namespace WPCareGrid\Connector\Security\Remediation;

/**
 * Remediation UUIDs this site has already applied. JobRunner never runs a
 * job twice; this also refuses a second job carrying the same remediation
 * (a replay), whatever its job UUID.
 */
final class RemediationLog
{
    private const OPTION = 'wp_caregrid_remediations';

    private const KEEP = 500;

    public static function applied(string $uuid): bool
    {
        return in_array($uuid, (array) get_option(self::OPTION, []), true);
    }

    public static function record(string $uuid): void
    {
        $done = array_values(array_filter((array) get_option(self::OPTION, []), 'is_string'));
        $done[] = $uuid;
        update_option(self::OPTION, array_slice(array_unique($done), -self::KEEP), false);
    }
}
