<?php

namespace App\Content;

use App\Enums\Status;

/**
 * Illustrative data for the dashboard previews on the marketing site.
 *
 * Everything here is fictional demo content and is always rendered with a
 * visible "Demo data" label. It must never be presented as real customer
 * or business statistics.
 */
final class DemoDashboard
{
    /** @return list<array{domain: string, platform: string, plan: string, last_backup: string, updates: int, note: string, status: Status}> */
    public static function sites(): array
    {
        return [
            ['domain' => 'fndeco.com', 'platform' => 'WooCommerce', 'plan' => 'Business', 'last_backup' => 'Today 03:10', 'updates' => 0, 'note' => 'All checks passed', 'status' => Status::Healthy],
            ['domain' => 'petplaza.hu', 'platform' => 'WooCommerce', 'plan' => 'Secure', 'last_backup' => 'Today 02:45', 'updates' => 3, 'note' => '3 updates pending', 'status' => Status::Attention],
            ['domain' => 'example.com', 'platform' => 'WordPress', 'plan' => 'Basic', 'last_backup' => 'Today 03:30', 'updates' => 1, 'note' => 'Update scheduled', 'status' => Status::Healthy],
            ['domain' => 'shop.example.com', 'platform' => 'WooCommerce', 'plan' => 'Business', 'last_backup' => 'Today 01:55', 'updates' => 2, 'note' => 'Security issue found', 'status' => Status::Critical],
            ['domain' => 'blog.example.org', 'platform' => 'WordPress', 'plan' => 'Basic', 'last_backup' => 'Yesterday 23:40', 'updates' => 1, 'note' => 'License expired', 'status' => Status::Attention],
            ['domain' => 'docs.example.net', 'platform' => 'WordPress', 'plan' => 'Business', 'last_backup' => 'Today 03:05', 'updates' => 0, 'note' => 'All checks passed', 'status' => Status::Healthy],
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
     * @return array{site: string, steps: list<array{label: string, time: string, status: Status}>}
     */
    public static function latestUpdateRun(): array
    {
        return [
            'site' => 'fndeco.com',
            'steps' => [
                ['label' => 'Backup', 'time' => '03:10', 'status' => Status::Verified],
                ['label' => 'Update', 'time' => '03:24', 'status' => Status::Success],
                ['label' => 'Check', 'time' => '03:27', 'status' => Status::Success],
                ['label' => 'Functional test', 'time' => '03:31', 'status' => Status::Success],
            ],
        ];
    }

    /** @return list<array{value: string, label: string, detail: string, tone: string, icon: string}> */
    public static function metrics(): array
    {
        return [
            ['value' => '42', 'label' => 'Successful backups', 'detail' => 'Last 7 days', 'tone' => 'positive', 'icon' => 'archive'],
            ['value' => '7', 'label' => 'Updates pending', 'detail' => 'Across 4 sites', 'tone' => 'neutral', 'icon' => 'refresh'],
            ['value' => '1', 'label' => 'Security issue', 'detail' => 'shop.example.com', 'tone' => 'danger', 'icon' => 'shield'],
            ['value' => '2', 'label' => 'Expired licenses', 'detail' => 'Customer action required', 'tone' => 'warning', 'icon' => 'key'],
        ];
    }

    /** @return list<array{name: string, site: string, type: string}> */
    public static function pendingUpdates(): array
    {
        return [
            ['name' => 'WooCommerce', 'site' => 'petplaza.hu', 'type' => 'Plugin'],
            ['name' => 'Yoast SEO', 'site' => 'petplaza.hu', 'type' => 'Plugin'],
            ['name' => 'Storefront', 'site' => 'shop.example.com', 'type' => 'Theme'],
            ['name' => 'WordPress core', 'site' => 'example.com', 'type' => 'Core'],
        ];
    }

    /** @return list<array{name: string, site: string, status: Status}> */
    public static function licenses(): array
    {
        return [
            ['name' => 'WP Rocket', 'site' => 'petplaza.hu', 'status' => Status::LicenseExpired],
            ['name' => 'ACF Pro', 'site' => 'blog.example.org', 'status' => Status::LicenseExpired],
        ];
    }

    /** @return list<array{date: string, time: string, event: string, detail: string, site: string, status: Status}> */
    public static function activity(): array
    {
        return [
            ['date' => '2026-09-25', 'time' => '03:10', 'event' => 'Backup', 'detail' => 'Files and database stored remotely', 'site' => 'fndeco.com', 'status' => Status::Verified],
            ['date' => '2026-09-25', 'time' => '03:24', 'event' => 'Plugin update', 'detail' => 'WooCommerce updated', 'site' => 'fndeco.com', 'status' => Status::Success],
            ['date' => '2026-09-25', 'time' => '03:31', 'event' => 'Functional check', 'detail' => 'Cart, checkout and order email tested', 'site' => 'fndeco.com', 'status' => Status::Success],
            ['date' => '2026-09-24', 'time' => '09:00', 'event' => 'License check', 'detail' => 'WP Rocket license expired', 'site' => 'petplaza.hu', 'status' => Status::Warning],
        ];
    }
}
