<?php

namespace App\Operator;

/**
 * Operator wording for refusal codes from the services (backups, restores,
 * updates, admin access). Each code belongs to a kind, so the console says
 * what sort of problem it is (permission, site offline, connector outdated…)
 * in plain words, never internals.
 */
final class Refusal
{
    public const KINDS = [
        'permission' => ['NOT_AUTHORIZED', 'FULL_ADMIN_REQUIRES_CUSTOMER', 'LEVEL_NOT_ALLOWED', 'MANUAL_NOT_ALLOWED', 'NO_BACKUP_PLAN', 'RESTORE_NOT_AVAILABLE', 'SUBSCRIPTION_INACTIVE', 'MAJOR_CORE_UPDATE_NOT_ALLOWED'],
        'offline' => ['NOT_CONNECTED', 'CONNECTOR_OFFLINE'],
        'outdated' => ['CAPABILITY_MISSING', 'CONNECTOR_OUTDATED', 'SAFETY_BACKUP_UNAVAILABLE'],
        'backup' => ['BACKUP_NOT_RESTORABLE', 'BACKUP_NOT_FOUND', 'RESTORE_TYPE_MISMATCH', 'STORAGE_NOT_CONFIGURED'],
        'busy' => ['BACKUP_IN_PROGRESS', 'RESTORE_IN_PROGRESS', 'UPDATE_IN_PROGRESS', 'TOO_SOON', 'ALREADY_SCHEDULED', 'TOO_MANY_REQUESTS'],
        'input' => ['CONFIRMATION_MISMATCH', 'CONFIRMATION_REQUIRED', 'NO_UPDATE_AVAILABLE', 'NO_INVENTORY', 'INVALID_UPDATE'],
    ];

    public static function kind(string $code): string
    {
        foreach (self::KINDS as $kind => $codes) {
            if (in_array($code, $codes, true)) {
                return $kind;
            }
        }

        return 'unknown';
    }

    public static function message(string $code): string
    {
        $key = 'operator.refusals.'.$code;

        return __($key) !== $key ? __($key) : __('operator.refusals.default');
    }

    /** @return array{kind: string, message: string, code: string} For the session flash shown by the layout. */
    public static function flash(string $code): array
    {
        return ['kind' => self::kind($code), 'message' => self::message($code), 'code' => $code];
    }
}
