<?php

namespace App\Connector;

use RuntimeException;
use ZipArchive;

/** Build and verify immutable, versioned Connector distribution artifacts. */
final class ConnectorPackageArtifact
{
    private const MANIFEST_PATH = 'wp-caregrid-connector/package-manifest.json';

    /** @return array{version:string, source_sha256:string, source_revision:?string, package_sha256:string, path:string} */
    public function buildOrVerify(): array
    {
        $directory = (string) config('connector.package_path');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Connector package directory could not be created.');
        }
        $lock = fopen($directory.'/.package-build.lock', 'c');
        if (! is_resource($lock) || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Connector package build lock could not be acquired.');
        }
        try {
            return $this->buildLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{version:string, source_sha256:string, source_revision:?string, package_sha256:string, path:string} */
    private function buildLocked(): array
    {
        $source = realpath((string) config('connector.plugin_source'));
        $directory = (string) config('connector.package_path');
        $release = $this->release($source ?: '');
        $version = $release['version'];
        $files = $this->sourceFiles($source);
        $sourceHash = hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $sourceRevision = $this->sourceRevision();
        $internal = ['schema' => 1, 'version' => $version, 'source_revision' => $sourceRevision, 'source_sha256' => $sourceHash, 'files' => $files];
        $internalJson = json_encode($internal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $path = $directory."/wp-caregrid-connector-{$version}.zip";
        $sidecar = $path.'.manifest.json';

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Connector package directory could not be created.');
        }
        if (is_link($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Connector package directory is not a safe writable directory.');
        }
        @chmod($directory, 0700);

        if (file_exists($path) || file_exists($sidecar)) {
            $verified = $this->verify($path);
            if ($verified['version'] !== $version || ! hash_equals($sourceHash, $verified['source_sha256'])) {
                throw new RuntimeException("Immutable Connector package {$version} does not match current source; bump the release version.");
            }

            return $verified;
        }

        $temporary = $directory.'/.connector-'.bin2hex(random_bytes(12)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Connector ZIP could not be opened for writing.');
        }

        try {
            foreach ($files as $relative => $sha256) {
                $file = $source.'/'.$relative;
                if (! $zip->addFile($file, 'wp-caregrid-connector/'.$relative)) {
                    throw new RuntimeException('Connector ZIP could not add a source file.');
                }
            }
            if (! $zip->addFromString(self::MANIFEST_PATH, $internalJson) || ! $zip->close()) {
                throw new RuntimeException('Connector ZIP could not be finalized.');
            }
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($temporary);
            throw $error;
        }

        $packageHash = hash_file('sha256', $temporary);
        if (! is_string($packageHash) || ! preg_match('/^[a-f0-9]{64}$/D', $packageHash)) {
            @unlink($temporary);
            throw new RuntimeException('Connector ZIP SHA-256 could not be calculated.');
        }
        $sidecarJson = json_encode([
            'schema' => 1,
            'version' => $version,
            'source_revision' => $sourceRevision,
            'source_sha256' => $sourceHash,
            'package_sha256' => $packageHash,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Connector ZIP could not be atomically published.');
        }
        @chmod($path, 0600);
        $sidecarTemp = $sidecar.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($sidecarTemp, $sidecarJson, LOCK_EX) === false || ! chmod($sidecarTemp, 0600) || ! rename($sidecarTemp, $sidecar)) {
            @unlink($sidecarTemp);
            @unlink($path);
            throw new RuntimeException('Connector package manifest could not be atomically published.');
        }

        return $this->verify($path);
    }

    /** @return array{version:string, source_sha256:string, source_revision:?string, package_sha256:string, path:string} */
    public function verify(?string $path = null): array
    {
        $version = $this->release((string) realpath((string) config('connector.plugin_source')))['version'];
        $path ??= (string) config('connector.package_path')."/wp-caregrid-connector-{$version}.zip";
        $expected = realpath((string) config('connector.package_path'));
        $real = realpath($path);
        if (! $expected || ! $real || ! str_starts_with($real, $expected.DIRECTORY_SEPARATOR) || is_link($path) || ! is_file($real)) {
            throw new RuntimeException('Connector package path is missing or outside the private package directory.');
        }
        $sidecarPath = $real.'.manifest.json';
        $sidecar = json_decode((string) @file_get_contents($sidecarPath), true);
        $packageHash = hash_file('sha256', $real);
        if (! is_array($sidecar) || ($sidecar['schema'] ?? null) !== 1 || ! is_string($packageHash)
            || ! hash_equals((string) ($sidecar['package_sha256'] ?? ''), $packageHash)) {
            throw new RuntimeException('Connector package integrity manifest is missing or does not match the artifact.');
        }

        $zip = new ZipArchive;
        if ($zip->open($real, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Connector package is not a readable ZIP archive.');
        }
        $manifest = json_decode((string) $zip->getFromName(self::MANIFEST_PATH), true);
        $members = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $name) || preg_match('/^[A-Za-z]:/', $name)) {
                $zip->close();
                throw new RuntimeException('Connector package contains an unsafe archive path.');
            }
            $members[] = $name;
        }
        if (! is_array($manifest) || ($manifest['schema'] ?? null) !== 1
            || ! hash_equals($version, (string) ($manifest['version'] ?? ''))
            || ($sidecar['source_revision'] ?? null) !== ($manifest['source_revision'] ?? null)
            || ! hash_equals((string) ($sidecar['version'] ?? ''), (string) ($manifest['version'] ?? ''))
            || ! hash_equals((string) ($sidecar['source_sha256'] ?? ''), (string) ($manifest['source_sha256'] ?? ''))
            || ! is_array($manifest['files'] ?? null)
            || ! isset($manifest['files']['bin/caregrid-controlled-poll.php'])) {
            throw new RuntimeException('Connector package content manifest is invalid or the controlled runner is missing.');
        }
        foreach ($manifest['files'] as $relative => $expectedHash) {
            if (! is_string($relative) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $expectedHash)
                || str_starts_with($relative, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative)) {
                $zip->close();
                throw new RuntimeException('Connector package manifest contains an unsafe path or hash.');
            }
            $contents = $zip->getFromName('wp-caregrid-connector/'.$relative);
            if (! is_string($contents) || ! is_string($expectedHash) || ! hash_equals($expectedHash, hash('sha256', $contents))) {
                $zip->close();
                throw new RuntimeException('Connector package source file does not match its embedded content manifest.');
            }
        }
        $manifestSourceHash = hash('sha256', json_encode($manifest['files'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! hash_equals((string) $manifest['source_sha256'], $manifestSourceHash)) {
            $zip->close();
            throw new RuntimeException('Connector package source digest does not match its file manifest.');
        }
        $zip->close();
        $expectedMembers = array_map(static fn (string $relative): string => 'wp-caregrid-connector/'.$relative, array_keys($manifest['files']));
        sort($members, SORT_STRING);
        $expectedMembers[] = self::MANIFEST_PATH;
        sort($expectedMembers, SORT_STRING);
        if ($members !== $expectedMembers) {
            throw new RuntimeException('Connector package members do not match its content manifest.');
        }
        $currentFiles = $this->sourceFiles(realpath((string) config('connector.plugin_source')));
        $currentSourceHash = hash('sha256', json_encode($currentFiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! hash_equals($currentSourceHash, (string) $manifest['source_sha256'])) {
            throw new RuntimeException('Connector package is stale for the current source tree.');
        }

        return ['version' => (string) $manifest['version'], 'source_sha256' => (string) $manifest['source_sha256'], 'source_revision' => is_string($manifest['source_revision'] ?? null) ? $manifest['source_revision'] : null, 'package_sha256' => $packageHash, 'path' => $real];
    }

    private function sourceRevision(): ?string
    {
        $process = proc_open(['git', '-C', base_path(), 'rev-parse', 'HEAD'], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return null;
        }
        $revision = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        $status = proc_close($process);
        if ($status !== 0) {
            if (in_array(config('app.deployment_environment'), ['staging', 'production'], true)) {
                throw new RuntimeException('A committed Git source revision is required to package Connector releases.');
            }

            return null; // Disposable tests may intentionally run without a Git checkout.
        }

        $dirtyCheck = proc_open(['git', '-C', base_path(), 'status', '--porcelain', '--untracked-files=all', '--ignored=matching', '--', 'wordpress/wp-caregrid-connector'], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $dirtyPipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($dirtyCheck)) {
            throw new RuntimeException('Connector source revision could not be verified.');
        }
        $dirty = trim((string) stream_get_contents($dirtyPipes[1]));
        fclose($dirtyPipes[1]);
        $dirtyStatus = proc_close($dirtyCheck);
        if ($dirtyStatus !== 0) {
            throw new RuntimeException('Connector source revision could not be verified.');
        }
        foreach (preg_split('/\r?\n/', $dirty) ?: [] as $entry) {
            if (str_starts_with($entry, '??') || str_starts_with($entry, '!!')) {
                throw new RuntimeException('Refusing to package untracked or ignored Connector source files.');
            }
        }
        if ($dirty !== '' && in_array(config('app.deployment_environment'), ['staging', 'production'], true)) {
            throw new RuntimeException('Refusing to package uncommitted Connector source.');
        }
        if ($dirty !== '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{40}$/D', $revision) ? $revision : null;
    }

    /** @return array{version:string, package_schema:int} */
    private function release(string $source): array
    {
        $metadata = json_decode((string) @file_get_contents($source.'/release.json'), true);
        if (! is_array($metadata) || ! is_string($metadata['version'] ?? null)
            || ! preg_match('/^\d+\.\d+\.\d+$/D', $metadata['version']) || ($metadata['package_schema'] ?? null) !== 1) {
            throw new RuntimeException('Connector release metadata is invalid.');
        }
        $header = (string) @file_get_contents($source.'/wp-caregrid-connector.php');
        if (! preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $match) || $match[1] !== $metadata['version']) {
            throw new RuntimeException('Connector plugin header and canonical release version do not match.');
        }

        return $metadata;
    }

    /** @return array<string, string> */
    private function sourceFiles(string|false $source): array
    {
        if (! $source || ! is_dir($source) || is_link($source)) {
            throw new RuntimeException('Connector source directory is invalid.');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            if ($file->isLink() || ! $file->isFile() || preg_match('#(^|/)(?:\.git|tests?|fixtures?)(?:/|$)|(^|/)\.env(?:\.|$)|\.(?:sqlite|sqlite3|log|zip)$#i', $relative)
                || str_starts_with($relative, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative)) {
                throw new RuntimeException('Connector source contains a link, unsafe path, or development-only artifact.');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Connector source file could not be hashed.');
            }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);
        if (! isset($files['wp-caregrid-connector.php'], $files['bin/caregrid-controlled-poll.php'])) {
            throw new RuntimeException('Connector package is missing its bootstrap or controlled runner.');
        }

        return $files;
    }
}
