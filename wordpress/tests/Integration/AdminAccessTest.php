<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Admin\SettingsPage;
use WPCareGrid\Connector\AdminAccess\Accounts;
use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Tests\Support\FakeHttp;
use WPCareGrid\Connector\Tests\Support\TestCase;
use WPCareGrid\Connector\Tests\Support\WPDieException;

/**
 * Admin access in real WordPress: roles, users, sessions (real session
 * tokens, validated auth cookies), expiry, revocation and every refusal.
 */
final class AdminAccessTest extends TestCase
{
    private const TOKEN = '29df881a559de2edae17f5bbd0e93517f98d3e295f59d52864f795516b5ca42a';

    private array $cookies = [];

    private int $cleared = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pair();

        // Real sessions and cookies are created; only the Set-Cookie headers are suppressed.
        add_filter('send_auth_cookies', '__return_false');
        add_action('set_auth_cookie', [$this, 'capture_auth_cookie'], 10, 6);
        add_action('clear_auth_cookie', [$this, 'count_clear']);
        $this->http->on('POST jobs/*/result', ['status' => 202, 'body' => []]);
        $this->http->on('POST admin-access/*/ended', ['status' => 200, 'body' => ['ok' => true]]);
        delete_transient('wp_caregrid_access_failures_'.md5('127.0.0.1'));
    }

    protected function tearDown(): void
    {
        AdminAccess::end_all('test', false);
        remove_filter('send_auth_cookies', '__return_false');
        remove_action('set_auth_cookie', [$this, 'capture_auth_cookie'], 10);
        remove_action('clear_auth_cookie', [$this, 'count_clear']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function capture_auth_cookie($cookie, $expire, $expiration, $user_id, $scheme, $token): void
    {
        $this->cookies[] = compact('cookie', 'expiration', 'user_id', 'token', 'scheme');
    }

    public function count_clear(): void
    {
        $this->cleared++;
    }

    /* ----- Role and accounts ----- */

    public function test_maintenance_role_has_exactly_the_listed_capabilities(): void
    {
        Accounts::ensure_role();
        $this->assertSame(Accounts::CAPABILITIES, array_keys(array_filter(get_role(Accounts::ROLE)->capabilities)));

        // A tampered role is reset.
        get_role(Accounts::ROLE)->add_cap('edit_users');
        Accounts::ensure_role();
        $this->assertArrayNotHasKey('edit_users', get_role(Accounts::ROLE)->capabilities);
    }

    public function test_maintenance_session_is_a_real_restricted_wordpress_session(): void
    {
        $this->open('maintenance', minutes: 30);

        $user = wp_get_current_user();
        $this->assertSame('wp-caregrid-maintenance', $user->user_login);
        $this->assertSame([Accounts::ROLE], $user->roles);
        $this->assertSame(admin_url(), $this->redirected_to);

        // A genuine WordPress session: the cookie validates and the session token exists.
        $cookie = end($this->cookies);
        $this->assertSame($user->ID, wp_validate_auth_cookie($cookie['cookie'], $cookie['scheme']));
        $this->assertEqualsWithDelta(time() + 30 * MINUTE_IN_SECONDS, $cookie['expiration'], 5, 'Short-lived.');
        $session = \WP_Session_Tokens::get_instance($user->ID)->get($cookie['token']);
        $this->assertNotNull($session);
        $this->assertArrayHasKey('wp_caregrid_request', $session);

        foreach (['update_plugins', 'update_themes', 'update_core', 'activate_plugins', 'view_site_health_checks'] as $allowed) {
            $this->assertTrue(current_user_can($allowed), $allowed);
        }
        foreach (['edit_users', 'create_users', 'delete_users', 'promote_users', 'list_users', 'install_plugins', 'install_themes', 'delete_plugins', 'edit_plugins', 'edit_themes', 'edit_files', 'manage_options', 'unfiltered_html', 'switch_themes', 'edit_theme_options', 'import', 'export'] as $denied) {
            $this->assertFalse(current_user_can($denied), $denied);
        }
        // No way up from maintenance: it cannot change roles, not even its own.
        $this->assertFalse(current_user_can('promote_user', $user->ID));
        $this->assertFalse(current_user_can('edit_user', 1));

        // The result reported to WP CareGrid.
        $result = $this->http->api_requests('POST jobs/*/result')[0]['body'];
        $this->assertSame('ADMIN_SESSION_STARTED', $result['result_code']);
        $this->assertSame(['access_level', 'session_expires_at'], array_keys($result['data']));
    }

    public function test_full_admin_is_administrator_and_capped_locally(): void
    {
        // Even if the server asked for two hours, the site allows at most 30 minutes.
        $this->open('full_admin', minutes: 120);

        $user = wp_get_current_user();
        $this->assertSame('wp-caregrid-admin', $user->user_login);
        $this->assertSame(['administrator'], $user->roles);
        $this->assertEqualsWithDelta(time() + 30 * MINUTE_IN_SECONDS, AdminAccess::state()['full_admin']['expires_at'], 5);
    }

    public function test_session_is_regenerated_and_one_per_level(): void
    {
        $this->open('maintenance');
        $first = end($this->cookies);
        $user_id = $first['user_id'];

        $this->open('maintenance', uuid: wp_generate_uuid4());
        $second = end($this->cookies);

        $this->assertNotSame($first['token'], $second['token'], 'A new session token each time.');
        $this->assertFalse(wp_validate_auth_cookie($first['cookie'], $first['scheme']), 'The previous session is destroyed.');
        $this->assertSame($user_id, wp_validate_auth_cookie($second['cookie'], $second['scheme']));
        $this->assertCount(1, \WP_Session_Tokens::get_instance($user_id)->get_all());
        $this->assertGreaterThanOrEqual(2, $this->cleared, 'Existing auth cookies are cleared before signing in (fixation).');
    }

    public function test_existing_users_are_never_touched(): void
    {
        $admin = $this->user('administrator');
        $hash = $admin->user_pass;
        wp_set_current_user(0);

        $this->open('full_admin');
        AdminAccess::end_all('test', false);

        $after = get_user_by('id', $admin->ID);
        $this->assertSame($hash, $after->user_pass);
        $this->assertSame(['administrator'], $after->roles);
    }

    public function test_an_unmanaged_account_with_the_same_name_is_not_taken_over(): void
    {
        require_once ABSPATH.'wp-admin/includes/user.php';
        if ($managed = Accounts::find('maintenance')) {
            wp_delete_user($managed->ID);
        }
        $existing = wp_insert_user(['user_login' => 'wp-caregrid-maintenance', 'user_pass' => 'owner-password-123', 'user_email' => 'owner@site.example', 'role' => 'editor']);
        $hash = get_user_by('id', $existing)->user_pass;

        $this->expectRefusal(fn () => $this->open('maintenance'), 'already exists');

        $user = get_user_by('id', $existing);
        $this->assertSame($hash, $user->user_pass);
        $this->assertSame(['editor'], $user->roles);
        $this->assertSame('ACCOUNT_CONFLICT', $this->http->api_requests('POST jobs/*/result')[0]['body']['result_code']);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($existing);
    }

    public function test_managed_accounts_cannot_use_passwords(): void
    {
        $this->open('maintenance');
        $user = wp_get_current_user();
        wp_set_password('known-password-for-test', $user->ID);

        $this->assertInstanceOf(\WP_Error::class, wp_authenticate('wp-caregrid-maintenance', 'known-password-for-test'));
        $this->assertFalse(apply_filters('allow_password_reset', true, $user->ID));
        $this->assertFalse(wp_is_application_passwords_available_for_user($user));
    }

    /* ----- Expiry and revocation ----- */

    public function test_expired_session_signs_the_user_out_and_removes_the_role(): void
    {
        $this->open('maintenance');
        $user_id = get_current_user_id();
        $this->assertNotFalse(wp_next_scheduled(AdminAccess::CRON, ['maintenance']), 'Expiry is scheduled, not left to a visit.');

        $state = AdminAccess::state();
        $state['maintenance']['expires_at'] = time() - 1;
        update_option('wp_caregrid_admin_access', $state, false);

        $this->expectRefusal(fn () => AdminAccess::guard(), 'has ended');
        $this->assertSame([], get_user_by('id', $user_id)->roles);
        $this->assertSame([], \WP_Session_Tokens::get_instance($user_id)->get_all());
        $this->assertSame(0, get_current_user_id());
        $this->assertSame('expired', $this->http->api_requests('POST admin-access/*/ended')[0]['body']['reason']);
    }

    public function test_cron_ends_the_session_without_any_visit(): void
    {
        $this->open('maintenance');
        $user_id = get_current_user_id();

        do_action(AdminAccess::CRON, 'maintenance');

        $this->assertSame([], AdminAccess::state());
        $this->assertSame([], get_user_by('id', $user_id)->roles);
    }

    public function test_session_not_listed_by_wp_caregrid_is_ended_at_the_next_poll(): void
    {
        $this->open('maintenance', uuid: $uuid = wp_generate_uuid4());
        $user_id = get_current_user_id();

        // Still listed: kept.
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [], 'admin_access' => [['request_uuid' => $uuid, 'access_level' => 'maintenance', 'session_expires_at' => gmdate('c', time() + 600)]]]]);
        $this->plugin()->runner()->poll();
        $this->assertArrayHasKey('maintenance', AdminAccess::state());

        // Revoked in WP CareGrid: no longer listed.
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [], 'admin_access' => []]]);
        $this->plugin()->runner()->poll();
        $this->assertSame([], AdminAccess::state());
        $this->assertSame([], \WP_Session_Tokens::get_instance($user_id)->get_all());
    }

    public function test_heartbeat_also_reconciles(): void
    {
        $this->open('maintenance');
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true, 'admin_access' => []]]);

        $this->plugin()->heartbeat()->send();

        $this->assertSame([], AdminAccess::state());
    }

    public function test_revoke_from_the_wordpress_settings_page(): void
    {
        $this->open('maintenance');
        $page = new SettingsPage($this->plugin());
        $page->register();
        wp_set_current_user(0);

        // Not without the capability, not without a nonce.
        $this->user('editor');
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce('wp_caregrid_revoke_access')];
        $this->expectRefusal(fn () => do_action('admin_post_wp_caregrid_revoke_access'), '', 403);
        $this->admin();
        $_POST = $_REQUEST = ['_wpnonce' => 'forged'];
        $this->expectRefusal(fn () => do_action('admin_post_wp_caregrid_revoke_access'), '', 403);
        $this->assertArrayHasKey('maintenance', AdminAccess::state());

        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce('wp_caregrid_revoke_access')];
        do_action('admin_post_wp_caregrid_revoke_access');

        $this->assertSame([], AdminAccess::state());
        $this->assertSame('revoked_on_site', $this->http->api_requests('POST admin-access/*/ended')[0]['body']['reason']);
        foreach (SettingsPage::ACTIONS as $key => $action) {
            remove_action("admin_post_{$action}", [$page, "handle_{$key}"]);
        }
        remove_action('admin_menu', [$page, 'menu']);
    }

    public function test_disconnect_and_revocation_end_access(): void
    {
        $this->open('maintenance');
        $this->http->on('POST disconnect', ['status' => 200, 'body' => ['ok' => true]]);
        $this->plugin()->connection()->disconnect('admin');
        $this->assertSame([], AdminAccess::state());

        $this->pair();
        $this->open('full_admin');
        $this->http->on('GET jobs', FakeHttp::error(401, 'CONNECTOR_REVOKED'));
        $this->plugin()->runner()->poll();
        $this->assertSame([], AdminAccess::state());
        $this->assertSame([], Accounts::find('full_admin')->roles);
    }

    public function test_guard_signs_out_when_the_site_is_no_longer_connected(): void
    {
        $this->open('maintenance');
        Settings::update(['status' => Settings::DISCONNECTED]);

        $this->expectRefusal(fn () => AdminAccess::guard(), 'has ended');
        $this->assertSame([], Accounts::find('maintenance')->roles);
    }

    /* ----- Refusals ----- */

    public function test_only_post_reaches_the_landing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->expectRefusal(fn () => do_action('login_form_wp_caregrid_access'), 'from your WP CareGrid dashboard', 405);
        $this->assertSame([], $this->http->api_requests('POST admin-access/claim'));
    }

    public function test_server_refusals_are_shown_plainly_and_create_no_session(): void
    {
        foreach ([
            'ACCESS_ALREADY_USED' => 'already been used',
            'ACCESS_EXPIRED' => 'has expired',
            'ACCESS_REVOKED' => 'was revoked',
            'ACCESS_INVALID' => 'not valid',
            'SUBSCRIPTION_INACTIVE' => 'not active',
        ] as $code => $text) {
            $this->http->on('POST admin-access/claim', FakeHttp::error(403, $code, 'Refused.'));
            $this->expectRefusal(fn () => $this->land(wp_generate_uuid4()), $text);
        }

        $this->assertSame([], AdminAccess::state());
        $this->assertSame([], Accounts::find('maintenance')?->roles ?? []);
    }

    public function test_network_failure(): void
    {
        $this->http->on('POST admin-access/claim', new \WP_Error('http_request_failed', 'cURL error 28'));
        $this->expectRefusal(fn () => $this->land(wp_generate_uuid4()), 'could not be reached', 503);
    }

    public function test_malformed_requests_and_repeated_failures_are_limited(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        for ($i = 0; $i < 10; $i++) {
            $_POST = ['request' => '../../etc', 'token' => 'x'];
            $this->expectRefusal(fn () => do_action('login_form_wp_caregrid_access'), 'not valid', 400);
        }

        $this->http->on('POST admin-access/claim', ['status' => 200, 'body' => []]);
        $this->expectRefusal(fn () => $this->land(wp_generate_uuid4()), 'Too many attempts', 429);
        $this->assertSame([], $this->http->api_requests('POST admin-access/claim'), 'Blocked before contacting WP CareGrid.');
    }

    public function test_the_session_is_for_this_website_request_and_level_only(): void
    {
        $uuid = wp_generate_uuid4();

        // A job for another website.
        $this->http->on('POST admin-access/claim', $this->claim_response($uuid, 'maintenance', website: '11111111-2222-4333-8444-555555555555'));
        $this->expectRefusal(fn () => $this->land($uuid), 'different website');

        // A job for another request than the one the browser brought.
        $this->http->on('POST admin-access/claim', $this->claim_response(wp_generate_uuid4(), 'maintenance'));
        $this->expectRefusal(fn () => $this->land($uuid), 'not valid');

        // An unknown access level.
        $other = wp_generate_uuid4();
        $this->http->on('POST admin-access/claim', $this->claim_response($other, 'super_admin'));
        $this->expectRefusal(fn () => $this->land($other), 'not valid');

        $this->assertSame([], AdminAccess::state());
    }

    public function test_a_replayed_job_does_not_create_a_second_session(): void
    {
        $uuid = wp_generate_uuid4();
        $response = $this->claim_response($uuid, 'maintenance');
        $this->http->on('POST admin-access/claim', $response);
        $this->land($uuid);
        $sessions = count($this->cookies);

        // The same job again (a compromised or confused server): never executed twice.
        $this->expectRefusal(fn () => $this->land($uuid), 'already been used');
        $this->assertCount($sessions, $this->cookies);
    }

    public function test_create_admin_session_is_refused_outside_the_browser_request(): void
    {
        $job = $this->job('CREATE_ADMIN_SESSION', ['request_uuid' => wp_generate_uuid4(), 'access_level' => 'full_admin', 'website_uuid' => self::WEBSITE, 'session_expires_at' => gmdate('c', time() + 600)]);
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $this->assertSame('ADMIN_SESSION_NEEDS_BROWSER', $this->posted_result($job['uuid'])['result_code']);
        $this->assertSame([], AdminAccess::state());
        $this->assertSame([], Accounts::find('full_admin')?->roles ?? []);
    }

    public function test_no_token_or_credential_is_stored_or_logged(): void
    {
        $this->open('maintenance');

        global $wpdb;
        foreach ([$wpdb->options => 'option_value', $wpdb->usermeta => 'meta_value'] as $table => $column) {
            $this->assertEquals(0, $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", '%'.$wpdb->esc_like(self::TOKEN).'%')), $table);
        }
        $log = wp_json_encode(Logger::entries());
        $this->assertStringNotContainsString(self::TOKEN, $log);
        $this->assertStringNotContainsString(self::CREDENTIAL, $log);

        // The token only ever went to WP CareGrid's claim endpoint, with the connector's own credential.
        foreach ($this->http->requests as $request) {
            $sent = wp_json_encode($request['body']).$request['raw'];
            if (str_contains($sent, self::TOKEN)) {
                $this->assertSame('admin-access/claim', $request['path']);
                $this->assertSame('Bearer '.self::CREDENTIAL, $request['headers']['Authorization']);
            }
        }
    }

    /* ----- helpers ----- */

    private function open(string $level, int $minutes = 30, ?string $uuid = null): void
    {
        $uuid ??= wp_generate_uuid4();
        $this->http->on('POST admin-access/claim', $this->claim_response($uuid, $level, $minutes));
        $this->land($uuid);
    }

    private function land(string $uuid): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['request' => $uuid, 'token' => self::TOKEN];
        do_action('login_form_wp_caregrid_access');
    }

    private function claim_response(string $uuid, string $level, int $minutes = 30, ?string $website = null): array
    {
        return ['status' => 200, 'body' => ['job' => [
            'uuid' => wp_generate_uuid4(), 'type' => 'CREATE_ADMIN_SESSION', 'created_at' => gmdate('c'), 'attempt' => 1,
            'payload' => ['request_uuid' => $uuid, 'access_level' => $level, 'website_uuid' => $website ?? self::WEBSITE, 'session_expires_at' => gmdate('c', time() + $minutes * MINUTE_IN_SECONDS)],
        ]]];
    }

    private function expectRefusal(callable $action, string $text, ?int $status = null): void
    {
        try {
            $action();
            $this->fail('Expected a refusal.');
        } catch (WPDieException $e) {
            $this->assertStringContainsString($text, $e->getMessage());
            if ($status !== null) {
                $this->assertSame($status, $e->getCode());
            }
        }
    }
}
