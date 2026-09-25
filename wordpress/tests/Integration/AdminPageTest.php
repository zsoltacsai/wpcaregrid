<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Admin\SettingsPage;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Tests\Support\FakeHttp;
use WPCareGrid\Connector\Tests\Support\TestCase;
use WPCareGrid\Connector\Tests\Support\WPDieException;

/** Settings → WP CareGrid, driven through WordPress's admin-post hooks. */
final class AdminPageTest extends TestCase
{
    private SettingsPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = new SettingsPage($this->plugin());
        $this->page->register();
    }

    protected function tearDown(): void
    {
        remove_action('admin_menu', [$this->page, 'menu']);
        foreach (SettingsPage::ACTIONS as $key => $action) {
            remove_action("admin_post_{$action}", [$this->page, "handle_{$key}"]);
        }

        parent::tearDown();
    }

    public function test_page_is_registered_under_settings_for_administrators_only(): void
    {
        global $submenu, $_registered_pages;
        $this->admin();

        do_action('admin_menu');

        $entries = array_column($submenu['options-general.php'] ?? [], 2);
        $this->assertContains('wp-caregrid', $entries);
        $entry = array_values(array_filter($submenu['options-general.php'], fn ($item) => $item[2] === 'wp-caregrid'))[0];
        $this->assertSame('manage_options', $entry[1]);
    }

    public function test_page_shows_the_unpaired_state_and_a_pairing_form(): void
    {
        $this->admin();

        $html = $this->render();

        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringContainsString('name="pairing_code"', $html);
        $this->assertStringContainsString('value="wp_caregrid_pair"', $html);
        $this->assertMatchesRegularExpression('/name="_wpnonce" value="[a-f0-9]{10}"/', $html);
        $this->assertStringContainsString(WP_CAREGRID_VERSION, $html);
        $this->assertStringContainsString('inventory, health, updates, license_check', $html);
        $this->assertStringNotContainsString('password" ', $html, 'No password is asked for.');
    }

    public function test_page_shows_the_connection_without_the_credential(): void
    {
        $this->pair();
        $this->admin();

        $html = $this->render();

        $this->assertStringContainsString('Connected', $html);
        $this->assertStringContainsString(self::WEBSITE, $html);
        $this->assertStringContainsString('value="wp_caregrid_disconnect"', $html);
        $this->assertStringContainsString('value="wp_caregrid_sync"', $html);
        $this->assertStringNotContainsString('name="pairing_code"', $html);
        $this->assertStringNotContainsString(self::CREDENTIAL, $html);
        $this->assertStringNotContainsString(substr(self::CREDENTIAL, -8), $html, 'Not even partially.');
    }

    public function test_page_renders_nothing_for_users_without_manage_options(): void
    {
        $this->pair();

        foreach (['editor', 'author', 'subscriber'] as $role) {
            $this->user($role);
            $this->assertSame('', $this->render(), $role);
        }
    }

    public function test_pairing_through_the_admin_form(): void
    {
        $this->admin();
        $this->http->on('POST pair', $this->pair_response());
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true]]);
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => []]]);
        $this->submit('pair', ['pairing_code' => 'abcd-efgh-jk23']);

        do_action('admin_post_wp_caregrid_pair');

        $this->assertTrue(Settings::is_connected());
        $this->assertSame(admin_url('options-general.php?page=wp-caregrid'), $this->redirected_to);
        $this->assertStringNotContainsString('ABCD', $this->redirected_to, 'The code is not put in a URL.');
        $this->assertSame(['type' => 'success', 'message' => 'Connected to WP CareGrid.'], get_transient('wp_caregrid_notice_'.get_current_user_id()));
        // The first heartbeat and poll run straight away.
        $this->assertCount(1, $this->http->api_requests('POST heartbeat'));
        $this->assertCount(1, $this->http->api_requests('GET jobs'));
    }

    public function test_invalid_code_through_the_admin_form(): void
    {
        $this->admin();
        $this->http->on('POST pair', FakeHttp::error(422, 'INVALID_PAIRING_CODE', 'This pairing code is invalid or has expired.'));
        $this->submit('pair', ['pairing_code' => self::PAIRING_CODE]);

        do_action('admin_post_wp_caregrid_pair');

        $this->assertFalse(Settings::is_connected());
        $this->assertSame('error', get_transient('wp_caregrid_notice_'.get_current_user_id())['type']);
    }

    public function test_actions_require_a_valid_nonce(): void
    {
        $this->admin();

        foreach (SettingsPage::ACTIONS as $key => $action) {
            foreach ([null, 'invalid', wp_create_nonce('some_other_action')] as $nonce) {
                $_POST = $_REQUEST = array_filter(['pairing_code' => self::PAIRING_CODE, '_wpnonce' => $nonce]);

                try {
                    do_action("admin_post_{$action}");
                    $this->fail("{$key} ran without a valid nonce.");
                } catch (WPDieException $e) {
                    $this->assertSame(403, $e->getCode());
                }
            }
        }

        $this->assertSame([], $this->http->requests, 'Nothing was sent.');
    }

    public function test_actions_require_manage_options(): void
    {
        $this->pair();

        foreach (['editor', 'author', 'subscriber'] as $role) {
            $this->user($role);

            foreach (SettingsPage::ACTIONS as $key => $action) {
                // Even with a nonce that is valid for this user.
                $this->submit($key, ['pairing_code' => self::PAIRING_CODE]);

                try {
                    do_action("admin_post_{$action}");
                    $this->fail("{$role} could run {$key}.");
                } catch (WPDieException $e) {
                    $this->assertSame(403, $e->getCode());
                }
            }
        }

        $this->assertSame([], $this->http->requests);
        $this->assertTrue(Settings::is_connected(), 'Nobody disconnected the site.');
    }

    public function test_logged_out_visitors_cannot_run_actions(): void
    {
        wp_set_current_user(0);
        $this->submit('disconnect');

        $this->expectException(WPDieException::class);
        $this->expectExceptionCode(403);

        do_action('admin_post_wp_caregrid_disconnect');
    }

    public function test_disconnect_revokes_remotely_and_forgets_locally(): void
    {
        $this->pair();
        $this->admin();
        $this->http->on('POST disconnect', ['status' => 200, 'body' => ['disconnected' => true]]);
        $this->submit('disconnect');

        do_action('admin_post_wp_caregrid_disconnect');

        [$request] = $this->http->api_requests('POST disconnect');
        $this->assertSame('Bearer '.self::CREDENTIAL, $request['headers']['Authorization']);
        $this->assertSame(Settings::DISCONNECTED, Settings::get('status'));
        $this->assertNull((new CredentialStore)->get());
        $this->assertNull(Settings::get('website_uuid'));
        $this->assertFalse(wp_next_scheduled(Scheduler::POLL));
        $this->assertFalse(wp_next_scheduled(Scheduler::HEARTBEAT));
        $this->assertStringContainsString('name="pairing_code"', $this->render(), 'Reconnecting needs a new pairing.');
    }

    public function test_disconnect_forgets_the_credential_even_when_wp_caregrid_is_unreachable(): void
    {
        $this->pair();
        $this->admin();
        $this->http->on('POST disconnect', new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));
        $this->submit('disconnect');

        do_action('admin_post_wp_caregrid_disconnect');

        $this->assertSame(Settings::DISCONNECTED, Settings::get('status'));
        $this->assertNull((new CredentialStore)->get());
    }

    public function test_sync_contacts_wp_caregrid(): void
    {
        $this->pair();
        $this->admin();
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true]]);
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => []]]);
        $this->submit('sync');

        do_action('admin_post_wp_caregrid_sync');

        $this->assertCount(1, $this->http->api_requests('POST heartbeat'));
        $this->assertSame('success', get_transient('wp_caregrid_notice_'.get_current_user_id())['type']);
    }

    public function test_revoked_state_is_explained(): void
    {
        $this->pair();
        $this->admin();
        $this->http->on('POST heartbeat', FakeHttp::error(401, 'CONNECTOR_REVOKED'));
        $this->plugin()->heartbeat()->send();

        $html = $this->render();

        $this->assertStringContainsString('Disconnected by WP CareGrid', $html);
        $this->assertStringContainsString('name="pairing_code"', $html);
    }

    private function render(): string
    {
        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    }

    /** Fill in a form the way the page does, with a nonce for the current user. */
    private function submit(string $key, array $fields = []): void
    {
        $_POST = $_REQUEST = $fields + ['action' => SettingsPage::ACTIONS[$key], '_wpnonce' => wp_create_nonce(SettingsPage::ACTIONS[$key])];
    }
}
