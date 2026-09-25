<?php

namespace WPCareGrid\Connector\Tests\Support;

/**
 * Answers every outbound HTTP request made through the WordPress HTTP API
 * (hooked on `pre_http_request`), and records it.
 *
 * - WP CareGrid API calls are answered by routes registered with on();
 *   unregistered routes get a 404.
 * - Loopback requests to the test site (health checks) get a 200.
 * - WordPress.org update checks answer "no updates".
 * - Anything else fails, as it would on a host without network access.
 */
final class FakeHttp
{
    public const API = 'https://app.caregrid.test/api/v1/connector/';

    /** @var array<int, array{method: string, url: string, path: ?string, headers: array, body: mixed, args: array}> */
    public array $requests = [];

    /** @var array<string, array<int, array|\WP_Error|callable>> */
    private array $routes = [];

    private array $site = ['status' => 200, 'body' => '<!doctype html><title>Site</title>'];

    public function register(): void
    {
        add_filter('pre_http_request', [$this, 'handle'], 10, 3);
    }

    public function unregister(): void
    {
        remove_filter('pre_http_request', [$this, 'handle'], 10);
    }

    /**
     * Register responses for "METHOD path" (path relative to the API; `*`
     * matches one segment). Several responses are returned in turn; the
     * last one repeats.
     *
     * @param  array{status: int, body?: array}|\WP_Error|callable  ...$responses
     */
    public function on(string $route, array|\WP_Error|callable ...$responses): self
    {
        $this->routes[$route] = $responses;

        return $this;
    }

    public function site_responds(int $status): void
    {
        $this->site = ['status' => $status, 'body' => ''];
    }

    public function handle($preempt, array $args, string $url)
    {
        $method = strtoupper($args['method'] ?? 'GET');
        $path = str_starts_with($url, self::API) ? substr($url, strlen(self::API)) : null;
        $body = is_string($args['body'] ?? null) ? json_decode($args['body'], true) : ($args['body'] ?? null);

        $this->requests[] = ['method' => $method, 'url' => $url, 'path' => $path, 'headers' => $args['headers'] ?? [], 'body' => $body, 'args' => $args];

        if ($path === null) {
            $host = wp_parse_url($url, PHP_URL_HOST);
            $wporg = (string) wp_parse_url($url, PHP_URL_PATH);

            return match (true) {
                $host === 'site.example' => $this->reply($this->site['status'], $this->site['body']),
                $host === 'api.wordpress.org' && str_starts_with($wporg, '/plugins/update-check/') => $this->reply(200, '{"plugins":{},"no_update":{},"translations":[]}'),
                $host === 'api.wordpress.org' && str_starts_with($wporg, '/themes/update-check/') => $this->reply(200, '{"themes":{},"no_update":{},"translations":[]}'),
                default => new \WP_Error('http_request_failed', 'No network in tests: '.$host),
            };
        }

        foreach ($this->routes as $route => &$responses) {
            [$route_method, $pattern] = explode(' ', $route, 2);
            $regex = '#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#';

            if ($route_method !== $method || ! preg_match($regex, $path)) {
                continue;
            }

            $response = count($responses) > 1 ? array_shift($responses) : $responses[0];

            if (is_callable($response)) {
                $response = $response(end($this->requests));
            }

            if ($response instanceof \WP_Error) {
                return $response;
            }

            return $this->reply($response['status'], wp_json_encode($response['body'] ?? new \stdClass));
        }

        return $this->reply(404, wp_json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']]));
    }

    /** API requests only, optionally for one "METHOD path" (with `*`). */
    public function api_requests(?string $route = null): array
    {
        return array_values(array_filter($this->requests, function (array $request) use ($route) {
            if ($request['path'] === null) {
                return false;
            }
            if ($route === null) {
                return true;
            }
            [$method, $pattern] = explode(' ', $route, 2);
            $regex = '#^'.str_replace('\*', '[^/]+', preg_quote($pattern, '#')).'$#';

            return $request['method'] === $method && preg_match($regex, $request['path']);
        }));
    }

    public static function error(int $status, string $code, string $message = 'Error.'): array
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }

    private function reply(int $status, string $body): array
    {
        return [
            'headers' => ['content-type' => 'application/json'],
            'body' => $body,
            'response' => ['code' => $status, 'message' => get_status_header_desc($status)],
            'cookies' => [],
            'filename' => null,
        ];
    }
}
