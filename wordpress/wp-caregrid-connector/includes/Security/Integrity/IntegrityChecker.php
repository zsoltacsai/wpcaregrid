<?php

namespace WPCareGrid\Connector\Security\Integrity;

use WPCareGrid\Connector\Security\Scan\Deadline;
use WPCareGrid\Connector\Security\Scan\ReportedFinding;
use WPCareGrid\Connector\Security\Scan\ScanState;

/**
 * Compares installed files with WordPress.org's official checksums.
 *
 *   verified     matches the official file
 *   modified     differs: an integrity finding, never "malware" by itself
 *   missing      listed officially, not on disk
 *   extra        a PHP file inside wp-admin/wp-includes or a checked plugin that is not in the official list
 *   unavailable  no official reference (premium plugins, themes, custom code)
 *
 * The verified/modified/extra paths are remembered for the malware phase:
 * verified official files are not searched for suspicious code (that is
 * what removes most false positives), modified/extra ones weigh more.
 */
final class IntegrityChecker
{
    /** Core files that are routinely removed or changed on healthy sites. */
    private const CORE_TOLERATED = ['readme.html', 'license.txt', 'wp-config-sample.php', 'wp-content/index.php'];

    public function __construct(private readonly ScanState $state, private readonly Deadline $deadline) {}

    /** @return array{findings: list<ReportedFinding>, done: bool} done=false: the time budget ran out (continue next part). */
    public function core(bool $report): array
    {
        $version = get_bloginfo('version');
        $sums = Checksums::core($version, get_locale());

        if ($sums === null) {
            $this->state->integrity['core'] = 'unavailable';

            return ['findings' => [], 'done' => true];
        }

        $findings = [];
        $paths = array_keys($sums);
        sort($paths);

        for ($i = (int) ($this->state->cursor['core'] ?? 0), $n = count($paths); $i < $n; $i++) {
            if ($this->deadline->reached()) {
                $this->state->cursor['core'] = $i;

                return ['findings' => $findings, 'done' => false];
            }

            $path = $paths[$i];
            // wp-content is the site's own: checked per plugin below.
            if (str_starts_with($path, 'wp-content/')) {
                continue;
            }

            $file = ABSPATH.$path;
            if (! is_file($file) || is_link($file)) {
                $this->state->count('integrity', 'missing');
                if ($report && ! in_array($path, self::CORE_TOLERATED, true)) {
                    $findings[] = $this->finding('CORE_FILE_MISSING', str_ends_with($path, '.php') ? 'medium' : 'low', 'high', 'WordPress core file missing', $path, 'core', ['integrity' => 'missing', 'version' => $version, 'reference' => 'wordpress.org']);
                }

                continue;
            }

            if (md5_file($file) === $sums[$path]) {
                $this->state->count('integrity', 'verified');
                $this->state->verified[$path] = true;
            } else {
                $this->state->count('integrity', 'modified');
                $this->state->flagged[$path] = 'modified';
                if ($report && ! in_array($path, self::CORE_TOLERATED, true)) {
                    $findings[] = $this->finding('CORE_FILE_MODIFIED', 'high', 'high', 'WordPress core file differs from the official version', $path, 'core', ['integrity' => 'modified', 'version' => $version, 'reference' => 'wordpress.org', 'sha256' => hash_file('sha256', $file), 'size' => filesize($file)]);
                }
            }
        }

        // PHP files in wp-admin / wp-includes that WordPress does not ship.
        foreach (['wp-admin', 'wp-includes'] as $dir) {
            foreach ($this->php_files(ABSPATH.$dir) as $file) {
                $path = $dir.'/'.substr(wp_normalize_path($file), strlen(wp_normalize_path(ABSPATH.$dir)) + 1);
                if (! isset($sums[$path])) {
                    $this->state->count('integrity', 'extra');
                    $this->state->flagged[$path] = 'extra';
                    if ($report) {
                        $findings[] = $this->finding('CORE_FILE_EXTRA', 'high', 'medium', 'Unexpected PHP file in WordPress core directory', $path, 'core', ['integrity' => 'extra', 'version' => $version, 'sha256' => hash_file('sha256', $file), 'size' => filesize($file)]);
                    }
                }
            }
        }

        $this->state->integrity['core'] = ($this->state->counts['integrity']['modified'] ?? 0) > 0 ? 'modified' : 'verified';
        $this->state->cursor['core'] = PHP_INT_MAX;

        return ['findings' => $findings, 'done' => true];
    }

    /** @return array{findings: list<ReportedFinding>, done: bool} */
    public function plugins(bool $report): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        ksort($plugins);
        $files = array_keys($plugins);
        $findings = [];

        for ($i = (int) ($this->state->cursor['plugins'] ?? 0), $n = count($files); $i < $n; $i++) {
            if ($this->deadline->reached()) {
                $this->state->cursor['plugins'] = $i;

                return ['findings' => $findings, 'done' => false];
            }

            $file = $files[$i];
            $slug = str_contains($file, '/') ? dirname($file) : null;
            // Single-file plugins (hello.php) and the connector itself have no WordPress.org package to compare with.
            if ($slug === null || $slug === 'wp-caregrid-connector') {
                $this->state->count('integrity', 'unavailable');

                continue;
            }

            $version = (string) ($plugins[$file]['Version'] ?? '');
            $sums = Checksums::plugin($slug, $version);
            if ($sums === null) {
                $this->state->count('integrity', 'unavailable');

                continue;
            }

            $dir = WP_PLUGIN_DIR.'/'.$slug;
            foreach ($sums as $relative => $hashes) {
                $path = $dir.'/'.$relative;
                $site_path = 'wp-content/plugins/'.$slug.'/'.$relative;

                if (! is_file($path) || is_link($path)) {
                    $this->state->count('integrity', 'missing');
                    if ($report && str_ends_with($relative, '.php')) {
                        $findings[] = $this->finding('PLUGIN_FILE_MISSING', 'low', 'high', 'Plugin file missing', $site_path, 'plugin:'.$slug, ['integrity' => 'missing', 'plugin' => $slug, 'version' => $version, 'reference' => 'wordpress.org']);
                    }

                    continue;
                }

                $sha256 = hash_file('sha256', $path);
                if (Checksums::matches($hashes['sha256'], $sha256) || ($hashes['sha256'] === null && Checksums::matches($hashes['md5'], (string) md5_file($path)))) {
                    $this->state->count('integrity', 'verified');
                    $this->state->verified[$site_path] = true;
                } else {
                    $this->state->count('integrity', 'modified');
                    $this->state->flagged[$site_path] = 'modified';
                    if ($report) {
                        $findings[] = $this->finding('PLUGIN_FILE_MODIFIED', str_ends_with($relative, '.php') ? 'medium' : 'low', 'high', 'Plugin file differs from the official version', $site_path, 'plugin:'.$slug, ['integrity' => 'modified', 'plugin' => $slug, 'version' => $version, 'reference' => 'wordpress.org', 'sha256' => $sha256, 'size' => filesize($path)]);
                    }
                }
            }

            foreach ($this->php_files($dir) as $php) {
                $relative = substr(wp_normalize_path($php), strlen(wp_normalize_path($dir)) + 1);
                if (! isset($sums[$relative])) {
                    $site_path = 'wp-content/plugins/'.$slug.'/'.$relative;
                    $this->state->count('integrity', 'extra');
                    $this->state->flagged[$site_path] = 'extra';
                    if ($report) {
                        $findings[] = $this->finding('PLUGIN_FILE_EXTRA', 'medium', 'medium', 'PHP file not part of the official plugin', $site_path, 'plugin:'.$slug, ['integrity' => 'extra', 'plugin' => $slug, 'version' => $version, 'sha256' => hash_file('sha256', $php), 'size' => filesize($php)]);
                    }
                }
            }
        }

        $this->state->cursor['plugins'] = PHP_INT_MAX;

        return ['findings' => $findings, 'done' => true];
    }

    /** @return list<string> PHP-like files under a directory, symlinks not followed. */
    private function php_files(string $dir): array
    {
        if (! is_dir($dir) || is_link($dir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($iterator as $file) {
            if (! $file->isLink() && $file->isFile() && preg_match('/\.(php\d?|phtml|phar|pht)$/i', $file->getFilename())) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    private function finding(string $code, string $severity, string $confidence, string $title, string $path, string $component, array $evidence): ReportedFinding
    {
        return new ReportedFinding('integrity', $code, $severity, $confidence, $title, $component, $path, $evidence);
    }
}
