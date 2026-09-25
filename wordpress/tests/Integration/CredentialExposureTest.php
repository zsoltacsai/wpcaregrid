<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Admin\SettingsPage;
use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Tests\Support\FakeHttp;
use WPCareGrid\Connector\Tests\Support\TestCase;

/** The credential stays inside the plugin: encrypted at rest, sent only to WP CareGrid. */
final class CredentialExposureTest extends TestCase
{
    public function test_credential_is_encrypted_at_rest(): void
    {
        $this->pair();

        global $wpdb;
        $raw = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wp_caregrid_credential'");

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::CREDENTIAL, $raw);
        $this->assertStringNotContainsString(self::CREDENTIAL, base64_decode($raw));
        $this->assertStringNotContainsString(self::CREDENTIAL, (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wp_caregrid_state'"));
    }

    public function test_tampered_credential_is_rejected(): void
    {
        $this->pair();
        $encrypted = get_option('wp_caregrid_credential');

        // Tampered ciphertext is rejected, not returned.
        $raw = base64_decode($encrypted);
        update_option('wp_caregrid_credential', base64_encode(substr($raw, 0, -1).chr(ord($raw[-1]) ^ 1)), false);
        $this->assertNull((new CredentialStore)->get());
    }

    public function test_credential_is_not_in_logs(): void
    {
        $this->http->on('POST pair', $this->pair_response());
        $this->plugin()->pairing()->pair(self::PAIRING_CODE);
        $this->http->on('POST heartbeat', FakeHttp::error(500, 'SERVER_ERROR', 'Bad token '.self::CREDENTIAL));
        $this->plugin()->heartbeat()->send();
        Logger::error('test.context', ['authorization' => 'Bearer '.self::CREDENTIAL, 'note' => 'leaked '.self::CREDENTIAL, 'code' => self::PAIRING_CODE]);

        $log = wp_json_encode(Logger::entries());

        $this->assertStringNotContainsString(self::CREDENTIAL, $log);
        $this->assertStringNotContainsString(substr(self::CREDENTIAL, 4, 16), $log);
        $this->assertStringNotContainsString(self::PAIRING_CODE, $log);
        $this->assertStringNotContainsString('ABCDEFGHJK23', $log);
    }

    public function test_credential_is_sent_only_to_the_wp_caregrid_api(): void
    {
        $this->pair();
        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true]]);
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [$this->job('SITE_HEALTH')]]]);
        $this->http->on('POST jobs/*/result', ['status' => 202, 'body' => []]);

        $this->plugin()->heartbeat()->send();
        $this->plugin()->runner()->poll(); // Health checks make loopback requests to the site.

        $loopbacks = array_filter($this->http->requests, fn ($request) => $request['path'] === null);
        $this->assertNotEmpty($loopbacks);
        foreach ($this->http->requests as $request) {
            $sent = wp_json_encode([$request['headers'], $request['body'], $request['url']]);
            if ($request['path'] === null) {
                $this->assertStringNotContainsString(self::CREDENTIAL, $sent, 'Credential sent to '.$request['url']);
            } else {
                $this->assertStringStartsWith(FakeHttp::API, $request['url']);
            }
        }
    }

    public function test_credential_is_not_exposed_in_admin_frontend_or_rest(): void
    {
        $this->pair();
        $this->admin();

        ob_start();
        (new SettingsPage($this->plugin()))->render();
        do_action('wp_head');
        do_action('wp_footer');
        do_action('admin_footer');
        $html = ob_get_clean();

        $this->assertStringContainsString(self::WEBSITE, $html, 'The admin page did render.');
        $this->assertStringNotContainsString(self::CREDENTIAL, $html);
        $this->assertStringNotContainsString('cgc_', $html);

        // No REST routes, public AJAX or admin_init handlers from the connector.
        $routes = array_keys(rest_get_server()->get_routes());
        $this->assertSame([], preg_grep('/caregrid/i', $routes));
        global $wp_filter;
        foreach (array_keys($wp_filter) as $hook) {
            if (str_starts_with($hook, 'wp_ajax') || in_array($hook, ['admin_init', 'init', 'rest_api_init', 'template_redirect', 'parse_request'], true)) {
                foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                    foreach ($callbacks as $callback) {
                        $function = $callback['function'];
                        $owner = is_array($function) ? (is_object($function[0]) ? get_class($function[0]) : $function[0]) : (is_string($function) ? $function : '');
                        // The only exception: the admin-access session guard (signs out ended CareGrid sessions).
                        if ($hook === 'init' && $function === [AdminAccess::class, 'guard']) {
                            continue;
                        }
                        $this->assertStringNotContainsString('WPCareGrid', $owner, "Connector hooked into {$hook}.");
                    }
                }
            }
        }
    }
}
