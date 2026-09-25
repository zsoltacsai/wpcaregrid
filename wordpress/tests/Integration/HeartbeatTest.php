<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Tests\Support\FakeHttp;
use WPCareGrid\Connector\Tests\Support\TestCase;

final class HeartbeatTest extends TestCase
{
    public function test_heartbeat_is_authenticated_and_reports_versions_and_health_only(): void
    {
        $this->pair();
        $this->plugin()->health()->run();
        $this->http->requests = [];
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true, 'heartbeat_interval' => 1200, 'poll_interval' => 600, 'latest_connector_version' => '0.2.0']]);

        $this->assertTrue($this->plugin()->heartbeat()->send());

        [$request] = $this->http->api_requests('POST heartbeat');
        $this->assertSame('Bearer '.self::CREDENTIAL, $request['headers']['Authorization']);
        $this->assertSame(self::WEBSITE, $request['headers']['X-CareGrid-Website']);
        $this->assertSame(
            ['connector_version', 'wp_version', 'php_version', 'site_url', 'woocommerce', 'last_successful_poll_at', 'capabilities', 'health', 'sent_at', 'admin_access_url'],
            array_keys($request['body'])
        );
        $this->assertSame(['installed', 'active', 'version'], array_keys($request['body']['woocommerce']));
        $this->assertSame(['status', 'findings'], array_keys($request['body']['health']));

        $this->assertNotNull(Settings::get('last_heartbeat_at'));
        $this->assertSame(1200, Settings::heartbeat_interval());
        $this->assertSame(600, Settings::poll_interval());
        $this->assertSame('0.2.0', Settings::get('latest_version'));
    }

    public function test_heartbeat_sends_no_content_users_or_secrets(): void
    {
        $this->pair();
        wp_insert_post(['post_title' => 'Private plan', 'post_content' => 'TOP-SECRET-CONTENT', 'post_status' => 'private']);
        update_option('some_plugin_api_key', 'sk_live_should_never_leave');
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true]]);

        $this->plugin()->heartbeat()->send();

        $sent = wp_json_encode($this->http->api_requests('POST heartbeat')[0]['body']);
        foreach (['TOP-SECRET-CONTENT', 'sk_live_should_never_leave', 'admin@site.example', self::CREDENTIAL, DB_NAME.'"', AUTH_KEY] as $secret) {
            $this->assertStringNotContainsString($secret, $sent);
        }
    }

    public function test_unpaired_site_sends_nothing(): void
    {
        $this->assertFalse($this->plugin()->heartbeat()->send());
        $this->assertSame([], $this->http->requests);
    }

    public function test_revoked_connector_forgets_its_credential_and_does_not_reconnect(): void
    {
        $this->pair();
        $this->http->on('POST heartbeat', FakeHttp::error(401, 'CONNECTOR_REVOKED', 'This connector has been disconnected.'));

        $this->assertFalse($this->plugin()->heartbeat()->send());

        $this->assertSame(Settings::REVOKED, Settings::get('status'));
        $this->assertNull((new CredentialStore)->get());
        $this->assertFalse(wp_next_scheduled(Scheduler::POLL));
        $this->assertFalse(wp_next_scheduled(Scheduler::HEARTBEAT));

        // Nothing is sent afterwards; only a new pairing reconnects.
        $this->http->requests = [];
        $this->assertFalse($this->plugin()->heartbeat()->send());
        $this->assertSame(['executed' => 0, 'delivered' => 0], $this->plugin()->runner()->poll());
        $this->assertSame([], $this->http->requests);
    }

    public function test_network_timeout_keeps_the_connection(): void
    {
        $this->pair();
        $this->http->on('POST heartbeat', new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds'));

        $this->assertFalse($this->plugin()->heartbeat()->send());

        $this->assertTrue(Settings::is_connected());
        $this->assertSame('NETWORK_ERROR', Settings::get('last_error'));
        $this->assertSame(self::CREDENTIAL, (new CredentialStore)->get());
    }

    public function test_server_error_keeps_the_connection(): void
    {
        $this->pair();
        $this->http->on('POST heartbeat', ['status' => 503, 'body' => []], ['status' => 200, 'body' => ['ok' => true]]);

        $this->assertFalse($this->plugin()->heartbeat()->send());
        $this->assertTrue(Settings::is_connected());

        $this->assertTrue($this->plugin()->heartbeat()->send());
        $this->assertNull(Settings::get('last_error'));
    }

    public function test_other_authentication_errors_do_not_disconnect(): void
    {
        // Only an explicit revocation drops the credential; a proxy's 401 must not.
        $this->pair();
        $this->http->on('POST heartbeat', FakeHttp::error(401, 'UNAUTHENTICATED'));

        $this->plugin()->heartbeat()->send();

        $this->assertTrue(Settings::is_connected());
        $this->assertSame('UNAUTHENTICATED', Settings::get('last_error'));
    }

    public function test_cron_events_run_the_heartbeat_and_poll(): void
    {
        $this->pair();
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true]]);
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => []]]);

        do_action(Scheduler::HEARTBEAT);
        do_action(Scheduler::POLL);

        $this->assertCount(1, $this->http->api_requests('POST heartbeat'));
        $this->assertCount(1, $this->http->api_requests('GET jobs'));
    }
}
