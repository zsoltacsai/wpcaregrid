<?php

namespace App\Content;

use App\Enums\Status;

/**
 * Illustrative data for the dashboard previews on the marketing site.
 *
 * Everything here is fictional demo content and is always rendered with a
 * visible "Demo data" label. It must never be presented as real customer
 * or business statistics. Domains use only names reserved for examples
 * (RFC 2606/6761: example.com/.org/.net and the .example TLD), never a
 * real customer or project site. Values are keys and numbers only; the components
 * turn them into text through lang/{locale}/dashboard.php.
 */
final class DemoDashboard
{
    /**
     * @return list<array{
     *     domain: string, platform: string, last_backup: array{day: string, time: string},
     *     updates: int, security: string, license: string, status: Status
     * }>
     */
    public static function sites(): array
    {
        return [
            ['domain' => 'store.example.com', 'platform' => 'woocommerce', 'last_backup' => ['day' => 'today', 'time' => '03:10'], 'updates' => 0, 'security' => 'ok', 'license' => 'valid', 'status' => Status::Healthy],
            ['domain' => 'company.example', 'platform' => 'woocommerce', 'last_backup' => ['day' => 'today', 'time' => '02:45'], 'updates' => 3, 'security' => 'ok', 'license' => 'expired', 'status' => Status::Attention],
            ['domain' => 'example.com', 'platform' => 'wordpress', 'last_backup' => ['day' => 'today', 'time' => '03:30'], 'updates' => 1, 'security' => 'ok', 'license' => 'none', 'status' => Status::Healthy],
            ['domain' => 'shop.example.com', 'platform' => 'woocommerce', 'last_backup' => ['day' => 'today', 'time' => '01:55'], 'updates' => 2, 'security' => 'issue', 'license' => 'valid', 'status' => Status::Critical],
            ['domain' => 'blog.example.org', 'platform' => 'wordpress', 'last_backup' => ['day' => 'yesterday', 'time' => '23:40'], 'updates' => 1, 'security' => 'ok', 'license' => 'expired', 'status' => Status::Attention],
            ['domain' => 'docs.example.net', 'platform' => 'wordpress', 'last_backup' => ['day' => 'today', 'time' => '03:05'], 'updates' => 0, 'security' => 'ok', 'license' => 'valid', 'status' => Status::Healthy],
        ];
    }

    /** Sites shown in the compact hero preview. */
    public static function heroSites(): array
    {
        return array_slice(self::sites(), 0, 4);
    }

    /**
     * The most recent update run on one demo site, step by step.
     *
     * @return array{site: string, steps: list<array{key: string, time: string, status: Status}>}
     */
    public static function latestUpdateRun(): array
    {
        return [
            'site' => 'store.example.com',
            'steps' => [
                ['key' => 'backup', 'time' => '03:10', 'status' => Status::Verified],
                ['key' => 'update', 'time' => '03:24', 'status' => Status::Success],
                ['key' => 'check', 'time' => '03:27', 'status' => Status::Success],
                ['key' => 'functional', 'time' => '03:31', 'status' => Status::Success],
            ],
        ];
    }

    /** @return list<array{key: string, value: int, tone: string, icon: string}> */
    public static function metrics(): array
    {
        return [
            ['key' => 'backups', 'value' => 42, 'tone' => 'positive', 'icon' => 'archive'],
            ['key' => 'updates', 'value' => 7, 'tone' => 'neutral', 'icon' => 'refresh'],
            ['key' => 'security', 'value' => 1, 'tone' => 'danger', 'icon' => 'shield'],
            ['key' => 'licenses', 'value' => 2, 'tone' => 'warning', 'icon' => 'key'],
        ];
    }

    /** @return list<array{name: string, site: string, type: string}> */
    public static function pendingUpdates(): array
    {
        return [
            ['name' => 'WooCommerce', 'site' => 'company.example', 'type' => 'plugin'],
            ['name' => 'Yoast SEO', 'site' => 'company.example', 'type' => 'plugin'],
            ['name' => 'Storefront', 'site' => 'shop.example.com', 'type' => 'theme'],
            ['name' => 'WordPress core', 'site' => 'example.com', 'type' => 'core'],
        ];
    }

    /** @return list<array{name: string, site: string, status: Status}> */
    public static function licenses(): array
    {
        return [
            ['name' => 'WP Rocket', 'site' => 'company.example', 'status' => Status::LicenseExpired],
            ['name' => 'ACF Pro', 'site' => 'blog.example.org', 'status' => Status::LicenseExpired],
        ];
    }

    /** The license shown in the license management example. */
    public static function expiredLicense(): array
    {
        return ['product' => 'WP Rocket', 'site' => 'company.example', 'detected' => '2026-09-24'];
    }

    /**
     * @return list<array{date: string, time: string, event: string, detail: string, detail_params: array<string, string>, site: string, status: Status}>
     */
    public static function activity(): array
    {
        return [
            ['date' => '2026-09-25', 'time' => '03:10', 'event' => 'backup', 'detail' => 'backup_stored', 'detail_params' => [], 'site' => 'store.example.com', 'status' => Status::Verified],
            ['date' => '2026-09-25', 'time' => '03:24', 'event' => 'plugin_update', 'detail' => 'plugin_updated', 'detail_params' => ['name' => 'WooCommerce'], 'site' => 'store.example.com', 'status' => Status::Success],
            ['date' => '2026-09-25', 'time' => '03:31', 'event' => 'functional_check', 'detail' => 'checkout_tested', 'detail_params' => [], 'site' => 'store.example.com', 'status' => Status::Success],
            ['date' => '2026-09-24', 'time' => '09:00', 'event' => 'license_check', 'detail' => 'license_expired', 'detail_params' => ['name' => 'WP Rocket'], 'site' => 'company.example', 'status' => Status::Warning],
        ];
    }
}
