<?php

namespace App\Platform\Updates;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The installed platform components, read only from fixed, read-only sources of this
 * deployment: the lock files it was built from, the running PHP process, the database
 * connection and two fixed files. No shell command, no user-supplied path, nothing is
 * written. What cannot be read is reported as UNKNOWN, never guessed.
 */
final class ComponentInventory
{
    private const VERSION = '/^[0-9][0-9A-Za-z.+~:_-]{0,62}$/';

    private const NAME = '/^[A-Za-z0-9@][A-Za-z0-9@._\/-]{0,150}$/';

    private const ACTIONS_MAX_BYTES = 65536;

    public function __construct(private readonly ?string $basePath = null, private readonly string $osRelease = '/etc/os-release') {}

    /** @return list<DetectedComponent> */
    public function collect(): array
    {
        return [...$this->composer(), ...$this->npm(), ...$this->runtime(), ...$this->connector(), ...(PlatformUpdateGate::actionsInventoryEnabled() ? $this->githubActions() : [])];
    }

    /** @return list<DetectedComponent> */
    private function composer(): array
    {
        $lock = $this->json('composer.lock');
        if ($lock === null) {
            return [new DetectedComponent('composer:lock', 'composer', 'composer.lock', null, null, 'composer.lock')];
        }
        $out = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                $name = is_array($package) && is_string($package['name'] ?? null) ? $package['name'] : null;
                if ($name === null || preg_match(self::NAME, $name) !== 1 || strlen($name) > 150) {
                    continue;
                }
                $version = $this->version(is_string($package['version'] ?? null) ? ltrim($package['version'], 'v') : null);
                $out[] = new DetectedComponent('composer:'.$name, 'composer', $name, $version, 'Packagist', 'composer.lock', $dev);
            }
        }

        return $out;
    }

    /** @return list<DetectedComponent> */
    private function npm(): array
    {
        $lock = $this->json('package-lock.json');
        if ($lock === null) {
            return [new DetectedComponent('npm:lock', 'npm', 'package-lock.json', null, null, 'package-lock.json')];
        }
        $out = [];
        $seen = [];
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $path => $package) {
            if (! is_string($path) || ! is_array($package) || ! str_starts_with($path, 'node_modules/')) {
                continue;
            }
            $name = substr($path, (int) strrpos('/'.$path, '/node_modules/') + strlen('node_modules/'));
            $version = $this->version(is_string($package['version'] ?? null) ? $package['version'] : null);
            if (preg_match(self::NAME, $name) !== 1 || strlen($name) > 150) {   // keeps every key within the 191-character column
                continue;
            }
            // A nested copy can be a different (vulnerable) version: every distinct name@version is kept.
            $key = $path === 'node_modules/'.$name ? 'npm:'.$name : 'npm:'.$name.'@'.($version ?? 'unknown');
            if (strlen($key) > 190 || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = new DetectedComponent($key, 'npm', $name, $version, 'npm', 'package-lock.json', ($package['dev'] ?? false) === true);
        }

        return $out;
    }

    /** @return list<DetectedComponent> */
    private function runtime(): array
    {
        // Runtime components have no advisory source wired yet (ecosystem null => advisory state UNVERIFIED).
        $out = [new DetectedComponent('runtime:php', 'runtime', 'PHP', $this->version(PHP_VERSION), null, 'php_runtime')];

        $openssl = defined('OPENSSL_VERSION_TEXT') && preg_match('/^(?:OpenSSL|LibreSSL)\s+([0-9][0-9A-Za-z.]*)/', (string) OPENSSL_VERSION_TEXT, $m) === 1 ? $m[1] : null;
        $out[] = new DetectedComponent('runtime:openssl', 'runtime', 'OpenSSL (PHP)', $this->version($openssl), null, 'php_runtime');

        $driver = null;
        $database = null;
        try {
            $driver = DB::connection()->getDriverName();
            $database = match ($driver) {
                'mysql', 'mariadb' => (string) (DB::selectOne('select version() as v')->v ?? ''),
                'sqlite' => (string) (DB::selectOne('select sqlite_version() as v')->v ?? ''),
                default => null,
            };
        } catch (Throwable) {
            $database = null;
        }
        $label = match (true) {
            is_string($database) && stripos($database, 'mariadb') !== false => 'MariaDB',
            $driver === 'mysql' || $driver === 'mariadb' => 'MySQL',
            $driver === 'sqlite' => 'SQLite',
            default => 'Database',
        };
        $numeric = is_string($database) && preg_match('/^([0-9]+(?:\.[0-9]+){1,3})/', $database, $m) === 1 ? $m[1] : null;
        $out[] = new DetectedComponent('runtime:database', 'runtime', $label, $this->version($numeric), null, 'database_connection');

        $os = $this->osRelease();
        $out[] = new DetectedComponent('runtime:os', 'runtime', $os['name'] ?? 'Operating system', $this->version($os['version'] ?? null), null, 'os_release');

        return $out;
    }

    /** @return list<DetectedComponent> */
    private function connector(): array
    {
        $file = $this->path('wordpress/wp-caregrid-connector/wp-caregrid-connector.php');
        $head = is_file($file) && ! is_link($file) ? (string) @file_get_contents($file, false, null, 0, 4096) : '';
        $version = preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.+-]*)\s*$/m', $head, $m) === 1 ? $m[1] : null;

        return [new DetectedComponent('connector:wp-caregrid-connector', 'connector', 'WP CareGrid Connector', $this->version($version), null, 'connector_plugin_header')];
    }

    /**
     * Phase E1: the GitHub Actions the workflows use, read only from .github/workflows/actions.lock (never modified).
     *
     * Inventory only. These components have no advisory source: ecosystem is null, so they are never sent anywhere, never
     * CHECKED and stay UNVERIFIED. The file must match the one supported structure line by line; anything else (unknown
     * version, unexpected line, oversized, too many entries, symlink, unreadable) is rejected as a whole and reported as one
     * UNKNOWN placeholder, never as a partial or empty list.
     *
     * @return list<DetectedComponent>
     */
    private function githubActions(): array
    {
        $placeholder = [new DetectedComponent('github-action:lock', 'github-action', 'actions.lock', null, null, 'actions.lock')];
        $file = $this->path('.github/workflows/actions.lock');
        if (! is_file($file) || is_link($file) || (int) filesize($file) > self::ACTIONS_MAX_BYTES) {
            return $placeholder;
        }
        $text = @file_get_contents($file, false, null, 0, self::ACTIONS_MAX_BYTES + 1);
        if (! is_string($text) || strlen($text) > self::ACTIONS_MAX_BYTES) {
            return $placeholder;
        }
        $entries = ActionsLockParser::parse($text);

        return $entries === null ? $placeholder : $this->actionComponents($entries);
    }

    /**
     * One component per action and pinned commit (the same action under its tag and under its SHA is one component).
     *
     * @param  list<array{name: string, ref: string, commit: string}>  $entries
     * @return list<DetectedComponent>
     */
    private function actionComponents(array $entries): array
    {
        $byKey = [];
        foreach ($entries as $entry) {
            $key = 'github-action:'.$entry['name'].'@'.$entry['commit'];
            // The reference is shown as the version only when it looks like one (v7.0.1 / 2.40.0); a branch or other ref is UNKNOWN.
            $version = $this->version(ltrim($entry['ref'], 'v'));
            if (! isset($byKey[$key]) || ($byKey[$key][1] === null && $version !== null)) {
                $byKey[$key] = [$entry['name'], $version];
            }
        }
        ksort($byKey);
        $out = [];
        foreach ($byKey as $key => [$name, $version]) {
            if (strlen($key) > 190) {
                continue;   // keeps every key within the 191-character column
            }
            $out[] = new DetectedComponent($key, 'github-action', $name, $version, null, 'actions.lock');
        }

        return $out;
    }

    /** @return array{name?: string, version?: string} */
    private function osRelease(): array
    {
        if (! is_file($this->osRelease) || is_link($this->osRelease) && ! str_starts_with((string) realpath($this->osRelease), '/usr/lib/')) {
            return [];
        }
        $text = (string) @file_get_contents($this->osRelease, false, null, 0, 4096);
        $value = fn (string $key): ?string => preg_match('/^'.$key.'="?([A-Za-z0-9 ._()\/-]{1,60})"?\s*$/m', $text, $m) === 1 ? trim($m[1]) : null;

        return array_filter(['name' => $value('NAME'), 'version' => $value('VERSION_ID')]);
    }

    private function version(?string $version): ?string
    {
        return is_string($version) && preg_match(self::VERSION, $version) === 1 ? $version : null;
    }

    /** @return array<string, mixed>|null */
    private function json(string $relative): ?array
    {
        $file = $this->path($relative);
        if (! is_file($file) || is_link($file) || filesize($file) > 20 * 1024 * 1024) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function path(string $relative): string
    {
        return rtrim($this->basePath ?? base_path(), '/\\').DIRECTORY_SEPARATOR.$relative;
    }
}
