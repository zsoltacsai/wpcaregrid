<?php

namespace WPCareGrid\Connector\Tests\Support;

/**
 * WordPress.org for security tests. Registered after FakeHttp (which answers
 * every request), so its answers for these hosts win:
 *
 *   api.wordpress.org/core/checksums/1.0      MD5s computed from the real core files in vendor/
 *   downloads.wordpress.org/plugin-checksums  per registered plugin fixture
 *   core.svn.wordpress.org/tags/…             the real core file (or an override)
 *   plugins.svn.wordpress.org/…/tags/…        registered clean plugin files
 *
 * Tests change the *reference* (a checksum, a missing entry) rather than
 * WordPress core itself, which is shared and must stay untouched.
 */
final class FakeWordPressOrg
{
    public bool $core_available = true;

    /** @var array<string, ?string> path => md5 to report instead (null: leave the path out) */
    public array $core_overrides = [];

    /** @var array<string, string> "slug/version" => [relative => content] clean package */
    public array $plugins = [];

    /** @var array<string, string> core path => content served instead of the real file */
    public array $svn_core = [];

    /** @var list<string> */
    public array $requested = [];

    private static ?array $core_sums = null;

    public function register(): void
    {
        add_filter('pre_http_request', [$this, 'handle'], 20, 3);
    }

    public function unregister(): void
    {
        remove_filter('pre_http_request', [$this, 'handle'], 20);
    }

    /** Register a clean plugin package (the same files the fixture installs). */
    public function plugin(string $slug, string $version, array $files): void
    {
        $this->plugins["{$slug}/{$version}"] = $files;
    }

    public function handle($preempt, array $args, string $url)
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        if (! in_array($host, ['api.wordpress.org', 'downloads.wordpress.org', 'core.svn.wordpress.org', 'plugins.svn.wordpress.org'], true)) {
            return $preempt;
        }
        if ($host === 'api.wordpress.org' && ! str_starts_with($path, '/core/checksums/')) {
            return $preempt; // Update checks: FakeHttp.
        }

        $this->requested[] = $url;

        if ($host === 'api.wordpress.org') {
            if (! $this->core_available) {
                return self::reply(404, '{"checksums":false}');
            }
            $sums = self::core_sums();
            foreach ($this->core_overrides as $file => $md5) {
                if ($md5 === null) {
                    unset($sums[$file]);
                } else {
                    $sums[$file] = $md5;
                }
            }

            return self::reply(200, wp_json_encode(['checksums' => $sums]));
        }

        if ($host === 'downloads.wordpress.org' && preg_match('#^/plugin-checksums/([^/]+)/([^/]+)\.json$#', $path, $m)) {
            $files = $this->plugins[rawurldecode($m[1]).'/'.rawurldecode($m[2])] ?? null;
            if ($files === null) {
                return self::reply(404, '{"error":"not found"}');
            }

            return self::reply(200, wp_json_encode(['plugin' => $m[1], 'version' => $m[2], 'files' => array_map(fn ($c) => ['md5' => md5($c), 'sha256' => hash('sha256', $c)], $files)]));
        }

        if ($host === 'core.svn.wordpress.org' && preg_match('#^/tags/[^/]+/(.+)$#', $path, $m)) {
            $file = rawurldecode($m[1]);

            return isset($this->svn_core[$file]) ? self::reply(200, $this->svn_core[$file]) : (is_file(ABSPATH.$file) ? self::reply(200, (string) file_get_contents(ABSPATH.$file)) : self::reply(404, ''));
        }

        if ($host === 'plugins.svn.wordpress.org' && preg_match('#^/([^/]+)/tags/([^/]+)/(.+)$#', $path, $m)) {
            $content = $this->plugins[rawurldecode($m[1]).'/'.rawurldecode($m[2])][rawurldecode($m[3])] ?? null;

            return $content === null ? self::reply(404, '') : self::reply(200, $content);
        }

        return self::reply(404, '');
    }

    /** @return array<string, string> Every core file outside wp-content, as WordPress.org lists them. */
    public static function core_sums(): array
    {
        if (self::$core_sums !== null) {
            return self::$core_sums;
        }

        $sums = [];
        $root = rtrim(wp_normalize_path(ABSPATH), '/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr(wp_normalize_path($file->getPathname()), strlen($root) + 1);
            $core = str_starts_with($relative, 'wp-admin/') || str_starts_with($relative, 'wp-includes/') || (! str_contains($relative, '/') && $relative !== 'wp-config.php');
            if ($file->isFile() && $core) {
                $sums[$relative] = md5_file($file->getPathname());
            }
        }
        ksort($sums);

        return self::$core_sums = $sums;
    }

    private static function reply(int $status, string $body): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];
    }
}
