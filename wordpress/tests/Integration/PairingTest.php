<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Tests\Support\FakeHttp;
use WPCareGrid\Connector\Tests\Support\TestCase;

final class PairingTest extends TestCase
{
    public function test_valid_code_connects_the_site(): void
    {
        $this->http->on('POST pair', $this->pair_response());

        $result = $this->plugin()->pairing()->pair('abcd-efgh-jk23');

        $this->assertTrue($result['ok']);
        $this->assertTrue(Settings::is_connected());
        $this->assertSame(self::WEBSITE, Settings::get('website_uuid'));
        $this->assertSame(self::CONNECTOR, Settings::get('connector_uuid'));
        $this->assertSame(['inventory', 'health', 'updates', 'license_check'], Settings::get('capabilities'));
        $this->assertSame(self::CREDENTIAL, (new CredentialStore)->get());
        $this->assertNotFalse(wp_next_scheduled(Scheduler::POLL));
        $this->assertNotFalse(wp_next_scheduled(Scheduler::HEARTBEAT));
    }

    public function test_pairing_request_carries_the_code_and_site_facts_only(): void
    {
        $this->http->on('POST pair', $this->pair_response());

        $this->plugin()->pairing()->pair(' abcd efgh-jk23 ');

        [$request] = $this->http->api_requests('POST pair');
        $this->assertSame(FakeHttp::API.'pair', $request['url']);
        $this->assertSame(
            ['pairing_code', 'installation_id', 'site_url', 'connector_version', 'wp_version', 'php_version', 'capabilities'],
            array_keys($request['body'])
        );
        $this->assertSame('ABCDEFGHJK23', $request['body']['pairing_code']);
        $this->assertSame('https://site.example/', $request['body']['site_url']);
        $this->assertSame(JobRegistry::capabilities(), $request['body']['capabilities']);
        $this->assertArrayNotHasKey('Authorization', $request['headers'], 'Pairing is not authenticated with a credential.');
        $this->assertSame(0, $request['args']['redirection'], 'Redirects are never followed.');
        $this->assertTrue($request['args']['sslverify']);
    }

    public function test_code_with_wrong_format_is_rejected_without_a_request(): void
    {
        foreach (['', 'ABC', 'ABCD-EFGH-JK2', 'ABCD-EFGH-JK23-XY', '<script>'] as $code) {
            $result = $this->plugin()->pairing()->pair($code);
            $this->assertFalse($result['ok']);
            $this->assertSame('INVALID_FORMAT', $result['code']);
        }

        $this->assertSame([], $this->http->requests);
        $this->assertFalse(Settings::is_connected());
    }

    public function test_code_rejected_by_wp_caregrid_leaves_the_site_unpaired(): void
    {
        $this->http->on('POST pair', FakeHttp::error(422, 'INVALID_PAIRING_CODE', 'This pairing code is invalid or has expired.'));

        $result = $this->plugin()->pairing()->pair(self::PAIRING_CODE);

        $this->assertFalse($result['ok']);
        $this->assertSame('INVALID_PAIRING_CODE', $result['code']);
        $this->assertSame('This pairing code is invalid or has expired.', $result['message']);
        $this->assertFalse(Settings::is_connected());
        $this->assertNull((new CredentialStore)->get());
        $this->assertFalse(wp_next_scheduled(Scheduler::POLL));
    }

    public function test_malformed_pairing_response_is_not_trusted(): void
    {
        foreach ([
            $this->pair_response(['credential' => 'short']),
            $this->pair_response(['credential' => null]),
            $this->pair_response(['website_uuid' => '../../etc']),
            ['status' => 200, 'body' => $this->pair_response()['body']],
        ] as $response) {
            $this->http->on('POST pair', $response);
            $this->assertFalse($this->plugin()->pairing()->pair(self::PAIRING_CODE)['ok']);
            $this->assertNull((new CredentialStore)->get());
        }
    }

    public function test_unknown_capabilities_from_the_server_are_ignored(): void
    {
        $this->http->on('POST pair', $this->pair_response(['capabilities' => ['inventory', 'run_php', 'restore_everything']]));

        $this->plugin()->pairing()->pair(self::PAIRING_CODE);

        $this->assertSame(['inventory'], Settings::get('capabilities'));
    }

    public function test_server_intervals_never_go_below_the_minimum(): void
    {
        $this->http->on('POST pair', $this->pair_response(['heartbeat_interval' => 1, 'poll_interval' => 5]));

        $this->plugin()->pairing()->pair(self::PAIRING_CODE);

        $this->assertSame(Settings::MIN_HEARTBEAT, Settings::heartbeat_interval());
        $this->assertSame(Settings::MIN_POLL, Settings::poll_interval());
        $this->assertSame(Settings::MIN_POLL, wp_get_schedules()['wp_caregrid_poll']['interval']);
    }

    public function test_network_failure_during_pairing(): void
    {
        $this->http->on('POST pair', new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));

        $result = $this->plugin()->pairing()->pair(self::PAIRING_CODE);

        $this->assertFalse($result['ok']);
        $this->assertSame('NETWORK_ERROR', $result['code']);
        $this->assertFalse(Settings::is_connected());
    }

    public function test_only_https_api_addresses_are_allowed(): void
    {
        $this->assertTrue(Client::is_allowed_url('https://wpcaregrid.com/api/v1/connector/pair'));
        $this->assertFalse(Client::is_allowed_url('http://wpcaregrid.com/api/v1/connector/pair'));
        $this->assertFalse(Client::is_allowed_url('http://127.0.0.1:8000/api'), 'Localhost HTTP needs the explicit development constant.');
        $this->assertFalse(Client::is_allowed_url('ftp://wpcaregrid.com/'));
        $this->assertFalse(Client::is_allowed_url('file:///etc/passwd'));
    }
}
