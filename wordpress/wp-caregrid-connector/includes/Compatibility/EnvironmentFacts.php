<?php

namespace WPCareGrid\Connector\Compatibility;

use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\TempDir;

/** Bounded observations only; the SaaS server owns support policy. */
final class EnvironmentFacts
{
    public const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public function collect(bool $probeTemporaryDirectory = true): array
    {
        global $wpdb;

        $root = rtrim((string) realpath(ABSPATH), '/\\');
        $content = rtrim((string) realpath(WP_CONTENT_DIR), '/\\');
        $layout = self::classifyLayout($root, $content, is_multisite(), $root !== '' && $this->isBedrockLike($root));

        $tempProbe = $probeTemporaryDirectory
            ? $this->probeTemporaryDirectory()
            : ['write' => null, 'rename' => null, 'free_bytes' => null];
        $dbVersion = DatabaseDumper::supported() ? (string) $wpdb->db_server_info() : null;
        $dbEngine = $dbVersion === null ? 'unknown' : (stripos($dbVersion, 'mariadb') !== false ? 'mariadb' : 'mysql');
        $free = $tempProbe['free_bytes'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'multisite' => is_multisite(),
            'layout' => $layout,
            'root_symlink' => (is_link(rtrim(ABSPATH, '/\\')) && $root === '')
                || (is_link(rtrim(WP_CONTENT_DIR, '/\\')) && $content === ''),
            'filesystem' => [
                'read_root' => is_readable(ABSPATH),
                'read_content' => is_readable(WP_CONTENT_DIR),
                'writable_root' => is_writable(ABSPATH),
                'writable_content' => is_writable(WP_CONTENT_DIR),
                'write_probe' => $tempProbe['write'],
                'rename_probe' => $tempProbe['rename'],
            ],
            'disk_free_bytes' => $free,
            'database' => [
                'engine' => $dbEngine,
                'version' => $dbVersion ? substr($dbVersion, 0, 64) : null,
                'charset' => isset($wpdb->charset) ? substr((string) $wpdb->charset, 0, 32) : null,
                'standard_prefix' => isset($wpdb->base_prefix) ? preg_match('/^wp_[A-Za-z0-9_]*$/', (string) $wpdb->base_prefix) === 1 : null,
            ],
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'openssl' => extension_loaded('openssl'),
                'sodium' => extension_loaded('sodium'),
                'zlib' => extension_loaded('zlib'),
                'mysqli' => extension_loaded('mysqli'),
            ],
            'php_limits' => [
                'memory_bytes' => $this->boundedIniBytes((string) ini_get('memory_limit')),
                'max_execution_seconds' => max(0, min(86400, (int) ini_get('max_execution_time'))),
            ],
            'cron' => [
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true,
            ],
        ];
    }

    public static function classifyLayout(string $root, string $content, bool $multisite, bool $bedrockLike = false): string
    {
        if ($root === '' || $content === '') {
            return 'unknown';
        }
        if ($multisite) {
            return 'multisite';
        }
        if ($content !== rtrim($root, '/\\').DIRECTORY_SEPARATOR.'wp-content') {
            return $bedrockLike ? 'bedrock' : 'custom';
        }

        return 'standard';
    }

    /** @return array{write: ?bool, rename: ?bool, free_bytes: ?int} */
    private function probeTemporaryDirectory(): array
    {
        $dir = null;
        $source = null;
        $target = null;
        $write = null;
        $rename = null;

        try {
            $dir = TempDir::ensure();
            $source = $dir.'/preflight-'.bin2hex(random_bytes(12)).'.tmp';
            $target = $source.'.renamed';
            $written = @file_put_contents($source, 'wp-caregrid-preflight', LOCK_EX);
            $write = $written === 21;
            $rename = $write ? @rename($source, $target) : false;
        } catch (\Throwable) {
            $write = false;
            $rename = false;
        } finally {
            if (is_string($source) && (is_file($source) || is_link($source))) {
                @unlink($source);
            }
            if (is_string($target) && (is_file($target) || is_link($target))) {
                @unlink($target);
            }
        }

        $free = is_string($dir) && function_exists('disk_free_space') ? @disk_free_space($dir) : false;

        $freeBytes = is_numeric($free) && is_finite((float) $free) && (float) $free >= 0 && (float) $free <= PHP_INT_MAX
            ? (int) $free
            : null;

        return [
            'write' => $write,
            'rename' => $rename,
            'free_bytes' => $freeBytes,
        ];
    }

    private function isBedrockLike(string $root): bool
    {
        $parent = dirname($root);

        return is_file($parent.'/config/application.php') && is_dir($root.'/wp');
    }

    private function boundedIniBytes(string $value): ?int
    {
        if (in_array($value, ['', '-1'], true)) {
            return $value === '-1' ? PHP_INT_MAX : null;
        }
        if (! preg_match('/^(\d+)([KMG])?$/i', trim($value), $matches)) {
            return null;
        }
        $amount = (int) $matches[1];
        $multiplier = match (strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return min(PHP_INT_MAX, $amount * $multiplier);
    }
}
