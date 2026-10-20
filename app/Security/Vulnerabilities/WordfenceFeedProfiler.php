<?php

namespace App\Security\Vulnerabilities;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Runs the repository's offline feed profiler without retaining source data or diagnostics. */
class WordfenceFeedProfiler
{
    private const MAXIMUM_INPUT_BYTES = 160 * 1024 * 1024;

    private const MAXIMUM_OUTPUT_BYTES = 32 * 1024;

    /**
     * Return null on success or a fixed, provider-value-free failure subcode.
     *
     * @return null|'input_limit'|'process_timeout'|'output_limit'|'process_failed'|'private_storage_unavailable'|'report_write_failed'
     */
    public function profile(string $feedPath, int $maximumBytes): ?string
    {
        try {
            if (is_link($feedPath) || ! is_file($feedPath)) {
                return 'process_failed';
            }
            clearstatcache(true, $feedPath);
            $size = @filesize($feedPath);
            $maximumBytes = min(self::MAXIMUM_INPUT_BYTES, max(2, $maximumBytes));
            if ($size === false || $size < 2 || $size > $maximumBytes) {
                return 'input_limit';
            }

            $directory = $this->profileDirectory();
            if ($directory === null) {
                return 'private_storage_unavailable';
            }

            $script = $this->profilerScriptPath();
            if (! is_file($script) || is_link($script)) {
                return 'process_failed';
            }
            $scriptArgument = $this->childPath($script);
            $feedArgument = $this->childPath($feedPath);

            $process = new Process([
                PHP_BINARY,
                '-d', 'memory_limit=64M',
                $scriptArgument,
                $feedArgument,
                '--max-bytes='.$maximumBytes,
            ], base_path());
            $process->setTimeout(min(120, max(1, (int) config('security.vulnerabilities.profile_timeout_seconds', 120))));

            $output = '';
            $observedBytes = 0;
            $overflow = false;
            $environment = [];
            foreach (array_unique(array_merge(array_keys(getenv() ?: []), array_keys($_ENV), array_keys($_SERVER))) as $name) {
                if (is_string($name)) {
                    $environment[$name] = false;
                }
            }
            foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $environment[$name] = $value;
                }
            }
            try {
                $process->run(function (string $type, string $chunk) use ($process, &$output, &$observedBytes, &$overflow): void {
                    $observedBytes += strlen($chunk);
                    if ($observedBytes > self::MAXIMUM_OUTPUT_BYTES) {
                        $overflow = true;
                        $process->stop(0.1);
                    } elseif ($type === Process::OUT) {
                        $output .= $chunk;
                    }

                    // Symfony Process invokes this callback after reading a chunk.
                    // Drop its internal copies so memory use stays bounded as well.
                    $process->clearOutput();
                    $process->clearErrorOutput();
                }, $environment);
            } catch (ProcessTimedOutException) {
                return 'process_timeout';
            } catch (Throwable) {
                return $overflow ? 'output_limit' : 'process_failed';
            }

            if ($overflow) {
                return 'output_limit';
            }
            if (! $process->isSuccessful() || $output === '' || preg_match('/\A[\x20-\x7E\r\n]+\z/D', $output) !== 1) {
                return 'process_failed';
            }

            return $this->writeReport($directory, $output) ? null : 'report_write_failed';
        } catch (Throwable) {
            // Never expose process diagnostics, paths, or untrusted feed values.
            return 'process_failed';
        }
    }

    protected function profilerScriptPath(): string
    {
        return base_path('scripts/profile-wordfence-v3-feed.php');
    }

    private function childPath(string $path): string
    {
        $root = realpath(base_path());
        $resolved = realpath($path);
        if ($root === false || $resolved === false) {
            return $path;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($resolved, $prefix)) {
            return $path;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($prefix)));
    }

    protected function privateStorageDirectory(): string
    {
        return storage_path('app/private');
    }

    private function profileDirectory(): ?string
    {
        $storage = storage_path();
        $app = $storage.DIRECTORY_SEPARATOR.'app';
        $private = $this->privateStorageDirectory();
        $profiles = $private.DIRECTORY_SEPARATOR.'wordfence-profiles';

        $privateIsDefault = $private === $app.DIRECTORY_SEPARATOR.'private';
        $directoriesToCheck = $privateIsDefault ? [$storage, $app, $private] : [$private];
        foreach ($directoriesToCheck as $directory) {
            if (is_link($directory) || ! is_dir($directory)) {
                return null;
            }
        }
        if (is_link($profiles)) {
            return null;
        }
        if (! is_dir($profiles)) {
            $previousUmask = umask(0077);
            try {
                if (! @mkdir($profiles, 0700)) {
                    return null;
                }
            } finally {
                umask($previousUmask);
            }
        }
        if (! is_dir($profiles) || ! @chmod($profiles, 0700)) {
            return null;
        }

        $privateReal = realpath($private);
        $profileReal = realpath($profiles);
        if ($privateReal === false || $profileReal === false
            || $profileReal !== $privateReal.DIRECTORY_SEPARATOR.'wordfence-profiles'
            || is_link($profiles)) {
            return null;
        }

        if (function_exists('posix_geteuid') && function_exists('fileowner')
            && @fileowner($profiles) !== posix_geteuid()) {
            return null;
        }

        return $profileReal;
    }

    private function writeReport(string $directory, string $output): bool
    {
        $name = 'wordfence-v3-profile-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(12)).'.txt';
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        if (is_link($path)) {
            return false;
        }
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            return false;
        }

        $success = false;
        try {
            if (! @chmod($path, 0600)) {
                return false;
            }
            $written = 0;
            $length = strlen($output);
            while ($written < $length) {
                $chunk = @fwrite($handle, substr($output, $written));
                if ($chunk === false || $chunk === 0) {
                    return false;
                }
                $written += $chunk;
            }

            if (! @fflush($handle) || $written !== $length || @filesize($path) !== $length) {
                return false;
            }
            if (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid()) {
                return false;
            }
            if (function_exists('posix_geteuid') && ((@fileperms($path) ?: 0) & 0777) !== 0600) {
                return false;
            }

            $success = true;

            return $success;
        } finally {
            fclose($handle);
            if (! $success && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
