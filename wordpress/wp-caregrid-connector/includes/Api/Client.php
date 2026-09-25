<?php

namespace WPCareGrid\Connector\Api;

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Security\Logger;

/**
 * Outbound HTTPS client for the WP CareGrid API. All traffic starts from
 * this site; WP CareGrid never calls in, so NAT and firewalls are fine.
 *
 * Plain HTTP is refused, except for localhost when
 * WP_CAREGRID_ALLOW_INSECURE_LOCALHOST is true (development only).
 * TLS certificates are always verified.
 */
final class Client
{
    public function __construct(private readonly CredentialStore $credentials) {}

    public function post(string $path, array $body = [], bool $authenticated = true): Response
    {
        return $this->request('POST', $path, $body, $authenticated);
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path, [], true);
    }

    public function request(string $method, string $path, array $body, bool $authenticated): Response
    {
        $url = rtrim((string) WP_CAREGRID_API_URL, '/').'/'.ltrim($path, '/');

        if (! self::is_allowed_url($url)) {
            Logger::error('api.insecure_url_refused', ['path' => $path]);

            return new Response(0, [], 'INSECURE_API_URL', 'The WP CareGrid API address must use HTTPS.');
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'WP-CareGrid-Connector/'.WP_CAREGRID_VERSION,
        ];

        if ($authenticated) {
            $credential = $this->credentials->get();
            $website = Settings::get('website_uuid');

            if (! $credential || ! $website) {
                return new Response(0, [], 'NOT_PAIRED', 'This site is not connected to WP CareGrid.');
            }

            $headers['Authorization'] = 'Bearer '.$credential;
            $headers['X-CareGrid-Website'] = $website;
        }

        $response = wp_remote_request(
            $url,
            [
                'method' => $method,
                'headers' => $headers,
                'body' => $method === 'GET' ? null : wp_json_encode($body),
                'timeout' => 20,
                'redirection' => 0,
                'sslverify' => true,
            ]
        );

        if (is_wp_error($response)) {
            Logger::warning('api.network_error', ['path' => $path, 'message' => $response->get_error_message()]);

            return Response::network_error($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        if ($status >= 400) {
            Logger::warning('api.error_response', ['path' => $path, 'status' => $status, 'code' => $error['code'] ?? null]);
        }

        return new Response(
            $status,
            $data,
            isset($error['code']) ? (string) $error['code'] : null,
            isset($error['message']) ? (string) $error['message'] : null
        );
    }

    public static function is_allowed_url(string $url): bool
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if ($scheme === 'https') {
            return $host !== '';
        }

        return $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1'], true)
            && defined('WP_CAREGRID_ALLOW_INSECURE_LOCALHOST')
            && WP_CAREGRID_ALLOW_INSECURE_LOCALHOST;
    }
}
