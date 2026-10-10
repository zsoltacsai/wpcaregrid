<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$releasePath = $root.'/wordpress/wp-caregrid-connector/release.json';
$pluginPath = $root.'/wordpress/wp-caregrid-connector/wp-caregrid-connector.php';
$release = json_decode((string) file_get_contents($releasePath), true);
$plugin = (string) file_get_contents($pluginPath);
if (! is_array($release) || ! is_string($release['version'] ?? null)
    || ! preg_match('/^\d+\.\d+\.\d+$/D', $release['version'])
    || ! preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $plugin, $match)
    || $match[1] !== $release['version']
    || ! str_contains($plugin, 'release.json')
    || ! str_contains($plugin, "define('WP_CAREGRID_VERSION'")) {
    fwrite(STDERR, "Connector release metadata, plugin header, and semantic version must agree.\n");
    exit(1);
}

$base = $argv[1] ?? '';
if ($base === '') {
    fwrite(STDERR, "A base Git revision is required for Connector release validation.\n");
    exit(2);
}
$run = static function (array $command) use ($root): array {
    $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to run Git release check.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(trim($stderr) ?: 'Git release check failed.');
    }

    return [$stdout, $stderr];
};

try {
    try {
        [$baseRelease] = $run(['git', 'show', $base.':wordpress/wp-caregrid-connector/release.json']);
        $previous = json_decode($baseRelease, true);
    } catch (Throwable) {
        // Older Connector releases stored their version only in the plugin header.
        [$basePlugin] = $run(['git', 'show', $base.':wordpress/wp-caregrid-connector/wp-caregrid-connector.php']);
        if (! preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $basePlugin, $legacyMatch)) {
            throw new RuntimeException('Base revision has neither release metadata nor a plugin version header.');
        }
        $previous = ['version' => $legacyMatch[1]];
    }
    [$changed] = $run(['git', 'diff', '--name-only', $base, 'HEAD', '--', 'wordpress/wp-caregrid-connector']);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}

if (! is_array($previous) || ! is_string($previous['version'] ?? null)) {
    fwrite(STDERR, "Base revision has no valid Connector release metadata.\n");
    exit(2);
}
if (trim($changed) !== '' && version_compare($release['version'], $previous['version'], '<=')) {
    fwrite(STDERR, "Connector distributable source changed; bump release version above {$previous['version']}.\n");
    exit(1);
}

fwrite(STDOUT, "Connector release {$release['version']} is consistent; distributable changes have a version bump.\n");
