<?php

namespace WPCareGrid\Connector\Security\Database;

use WPCareGrid\Connector\AdminAccess\Accounts;
use WPCareGrid\Connector\Security\Scan\ReportedFinding;

/**
 * Read-only look at data where injected code hides. Fixed, prepared SELECT
 * queries only; nothing is changed, and no value leaves the site: findings
 * name the option, post ID, user or cron hook and the indicator, never the
 * content. Kept conservative: plugins legitimately store scripts and
 * base64 in options, so these are low or medium confidence.
 */
final class DatabaseInspector
{
    private const MAX_REPORTED = 25;

    /** Transients, caches and session data change constantly and are not inspected. */
    private const SKIPPED_OPTIONS = ['\\_transient\\_%', '\\_site\\_transient\\_%', '%\\_session\\_%'];

    /** @return list<ReportedFinding> */
    public function options(): array
    {
        global $wpdb;
        $findings = [];

        $indicators = [
            'DB_OPTION_PHP_CODE' => ['PHP code stored in an option', 'medium', 'low', ['%<?php%']],
            'DB_OPTION_SCRIPT_EVAL' => ['Script with runtime code evaluation in an option', 'medium', 'medium', ['%<script%', '%eval(%']],
            'DB_OPTION_SCRIPT_CHARCODES' => ['Script built from character codes in an option', 'medium', 'medium', ['%<script%', '%fromCharCode%']],
            'DB_OPTION_EXTERNAL_SCRIPT' => ['External script loaded from an option', 'low', 'low', ['%<script%src=%http%']],
        ];

        foreach ($indicators as $code => [$title, $severity, $confidence, $likes]) {
            $where = implode(' AND ', array_fill(0, count($likes), 'option_value LIKE %s'));
            $skip = implode(' AND ', array_fill(0, count(self::SKIPPED_OPTIONS), 'option_name NOT LIKE %s'));
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE {$where} AND {$skip} ORDER BY option_name LIMIT %d",
                ...[...$likes, ...self::SKIPPED_OPTIONS, self::MAX_REPORTED]
            ));

            foreach ((array) $names as $name) {
                $safe = substr((string) preg_replace('/[^A-Za-z0-9 ._:\/@-]/', '_', (string) $name), 0, 150);
                $findings[] = new ReportedFinding('suspicious_database', $code, $severity, $confidence, $title, 'option:'.$safe, null, ['option_name' => $safe]);
            }
        }

        // siteurl and home pointing to different hosts: a common redirect hijack.
        $siteurl = (string) wp_parse_url((string) get_option('siteurl'), PHP_URL_HOST);
        $home = (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST);
        if ($siteurl !== '' && $home !== '' && strcasecmp($siteurl, $home) !== 0 && strcasecmp(preg_replace('/^www\./i', '', $siteurl), preg_replace('/^www\./i', '', $home)) !== 0) {
            $findings[] = new ReportedFinding('suspicious_database', 'DB_SITEURL_MISMATCH', 'medium', 'medium', 'Site address and WordPress address point to different hosts', 'option:siteurl', null, ['option_name' => 'siteurl']);
        }

        return $findings;
    }

    /** Published content with scripts that evaluate or assemble code (injected spam or skimmers). */
    public function posts(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_content LIKE %s AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) ORDER BY ID LIMIT %d",
            'publish', '%<script%', '%eval(%', '%fromCharCode%', '%atob(%', self::MAX_REPORTED
        ));

        if (! $ids) {
            return [];
        }

        $ids = array_map('intval', $ids);

        return [new ReportedFinding('suspicious_database', 'DB_POST_OBFUSCATED_SCRIPT', 'medium', 'low', 'Published content contains obfuscated scripts', 'posts', null, ['post_ids' => array_slice($ids, 0, 20)])];
    }

    /**
     * Administrators: reported so WP CareGrid can compare them with the
     * previous scan. Login, ID, registration date and roles only: no e-mail
     * addresses, passwords or password hashes.
     *
     * @return array{admins: list<array>, findings: list<ReportedFinding>}
     */
    public function administrators(): array
    {
        $admins = [];
        $findings = [];

        foreach (get_users(['role__in' => ['administrator'], 'fields' => 'all', 'number' => 500, 'orderby' => 'ID']) as $user) {
            $admins[] = [
                'id' => (int) $user->ID,
                'login' => substr((string) $user->user_login, 0, 60),
                'registered' => $user->user_registered && $user->user_registered !== '0000-00-00 00:00:00' ? gmdate('c', strtotime($user->user_registered.' UTC')) : null,
                'roles' => array_values(array_map('strval', (array) $user->roles)),
                'managed' => (bool) get_user_meta($user->ID, Accounts::META, true),
            ];
        }

        // Full administrator capabilities without the administrator role: a hidden admin.
        foreach (get_users(['role__not_in' => ['administrator'], 'capability' => 'manage_options', 'fields' => 'all', 'number' => 50]) as $user) {
            if (get_user_meta($user->ID, Accounts::META, true)) {
                continue;
            }
            $findings[] = new ReportedFinding('suspicious_user', 'ADMIN_CAPABILITY_WITHOUT_ROLE', 'high', 'medium', 'User has administrator capabilities without the administrator role', 'user:'.$user->ID, null, [
                'user_id' => (int) $user->ID, 'user_login' => substr((string) $user->user_login, 0, 60), 'roles' => array_values(array_map('strval', (array) $user->roles)),
            ]);
        }

        return ['admins' => $admins, 'findings' => $findings];
    }

    /**
     * Scheduled events. Callbacks are never run. A custom hook is normal; a
     * hook is reported only with an indicator: no registered callback AND a
     * random-looking name, or arguments that carry code or remote URLs.
     */
    public function cron(): array
    {
        $findings = [];
        $cron = function_exists('_get_cron_array') ? (_get_cron_array() ?: []) : [];

        foreach ($cron as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                foreach ((array) $events as $event) {
                    $indicators = [];
                    $args = wp_json_encode($event['args'] ?? []);

                    if (preg_match('/eval\(|base64_decode|gzinflate|<\?php|\$_(?:GET|POST|REQUEST)/i', (string) $args)) {
                        $indicators[] = 'code_in_arguments';
                    }
                    if (preg_match('#https?://[^"\s]+\.(?:php|txt|jpg)\b#i', (string) $args)) {
                        $indicators[] = 'remote_file_in_arguments';
                    }
                    if (! has_action($hook) && preg_match('/^[a-z0-9]{16,}$/i', (string) $hook) && ! preg_match('/[_-]/', (string) $hook)) {
                        $indicators[] = 'random_name_without_callback';
                    }

                    if ($indicators) {
                        $findings[] = new ReportedFinding('suspicious_cron', 'CRON_SUSPICIOUS_EVENT', 'medium', count($indicators) > 1 || in_array('code_in_arguments', $indicators, true) ? 'medium' : 'low', 'Suspicious scheduled task', 'cron:'.substr((string) preg_replace('/[^A-Za-z0-9 ._:\/@-]/', '_', (string) $hook), 0, 150), null, [
                            'hook' => substr((string) $hook, 0, 150),
                            'schedule' => (string) ($event['schedule'] ?? 'single'),
                            'next_run' => gmdate('c', (int) $timestamp),
                            'callbacks' => has_action($hook) ? count($GLOBALS['wp_filter'][$hook]->callbacks ?? []) : 0,
                            'indicators' => $indicators,
                        ]);
                    }

                    if (count($findings) >= self::MAX_REPORTED) {
                        return $findings;
                    }
                }
            }
        }

        return $findings;
    }
}
