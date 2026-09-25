<?php

namespace WPCareGrid\Connector\AdminAccess;

use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\Handlers\CreateAdminSessionHandler;
use WPCareGrid\Connector\Security\Logger;

/**
 * wp-login.php?action=wp_caregrid_access — where the customer's browser
 * arrives from the WP CareGrid dashboard with a one-time token in a POST
 * body. The token proves nothing by itself: the connector asks WP CareGrid
 * (authenticated with its own credential) to consume it and receives the
 * CREATE_ADMIN_SESSION job for exactly one request, level and expiry.
 *
 * Failed attempts are rate limited per IP. Error pages say what happened
 * without internals.
 */
final class Landing
{
    public const ACTION = 'wp_caregrid_access';

    private const MAX_FAILURES = 10;

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('login_form_'.self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        nocache_headers();
        if (! headers_sent()) {
            header('Referrer-Policy: no-referrer');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::refuse(__('Open WordPress admin from your WP CareGrid dashboard.', 'wp-caregrid'), 405);
        }

        $key = 'wp_caregrid_access_failures_'.md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ((int) get_transient($key) >= self::MAX_FAILURES) {
            self::refuse(__('Too many attempts. Please wait a few minutes and try again from WP CareGrid.', 'wp-caregrid'), 429);
        }

        $uuid = isset($_POST['request']) ? sanitize_text_field(wp_unslash((string) $_POST['request'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';

        if (! preg_match('/^[0-9a-f-]{36}$/', $uuid) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->failed($key, 'INVALID_REQUEST');
            self::refuse(__('This access link is not valid. Open WordPress admin again from WP CareGrid.', 'wp-caregrid'), 400);
        }

        if (! Settings::is_connected()) {
            self::refuse(__('This website is not connected to WP CareGrid.', 'wp-caregrid'), 503);
        }

        $response = $this->plugin->api()->post('admin-access/claim', ['request_uuid' => $uuid, 'token' => $token]);

        if ($response->is_revoked()) {
            $this->plugin->connection()->handle_revocation();
            self::refuse(__('This website is not connected to WP CareGrid.', 'wp-caregrid'), 503);
        }

        if (! $response->ok()) {
            if ($response->status === 403) {
                $this->failed($key, (string) $response->error_code);
            }
            self::refuse(self::message((string) ($response->error_code ?? 'NETWORK_ERROR')), $response->is_temporary() ? 503 : 403);
        }

        $job = $response->data['job'] ?? null;
        if (! is_array($job) || ($job['type'] ?? null) !== 'CREATE_ADMIN_SESSION' || ($job['payload']['request_uuid'] ?? null) !== $uuid) {
            $this->failed($key, 'UNEXPECTED_RESPONSE');
            self::refuse(self::message('ACCESS_INVALID'), 403);
        }

        // The whitelisted job, through the normal runner: validation, capability, never twice.
        CreateAdminSessionHandler::$browser = true;
        try {
            $executed = $this->plugin->runner()->process($job);
        } finally {
            CreateAdminSessionHandler::$browser = false;
        }

        $result = $this->plugin->jobs()->find((string) $job['uuid'])['result'] ?? null;
        $this->plugin->runner()->flush_outbox();

        // A job this site already ran is never run again, and never reported as a new session.
        if (! $executed && ($result['status'] ?? null) === 'success') {
            $this->failed($key, 'REPLAYED');
            self::refuse(self::message('ACCESS_ALREADY_USED'), 403);
        }

        if (($result['status'] ?? null) !== 'success') {
            self::refuse(self::message((string) ($result['result_code'] ?? 'ACCESS_FAILED')), 403);
        }

        wp_safe_redirect(admin_url());
        self::terminate();
    }

    public static function message(string $code): string
    {
        return match ($code) {
            'ACCESS_EXPIRED' => __('This access link has expired. Open WordPress admin again from WP CareGrid.', 'wp-caregrid'),
            'ACCESS_ALREADY_USED' => __('This access link has already been used. Open WordPress admin again from WP CareGrid.', 'wp-caregrid'),
            'ACCESS_REVOKED' => __('This access was revoked.', 'wp-caregrid'),
            'SUBSCRIPTION_INACTIVE' => __('The WP CareGrid subscription for this website is not active.', 'wp-caregrid'),
            'ACCESS_NOT_ALLOWED', 'CAPABILITY_UNAVAILABLE' => __('This access is not allowed for this website.', 'wp-caregrid'),
            'WRONG_WEBSITE' => __('This access link belongs to a different website.', 'wp-caregrid'),
            'ACCOUNT_CONFLICT' => __('WP CareGrid cannot sign in: a user with its account name already exists on this website.', 'wp-caregrid'),
            'NETWORK_ERROR', 'RATE_LIMITED' => __('WP CareGrid could not be reached. Please try again in a moment.', 'wp-caregrid'),
            default => __('This access link is not valid. Open WordPress admin again from WP CareGrid.', 'wp-caregrid'),
        };
    }

    /** A plain error page; never a stack trace or an internal detail. */
    public static function refuse(string $message, int $status): void
    {
        wp_die(esc_html($message), esc_html__('WP CareGrid access', 'wp-caregrid'), ['response' => $status, 'back_link' => false]);
    }

    private function failed(string $key, string $code): void
    {
        set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
        Logger::warning('admin_access.refused', ['code' => $code]);
    }

    private static function terminate(): void
    {
        if (! defined('WP_CAREGRID_TESTING')) {
            exit;
        }
    }
}
