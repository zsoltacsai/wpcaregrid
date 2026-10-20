<?php

namespace App\Console\Commands\Security;

use App\Security\Vulnerabilities\AtomicIndexPublisher;
use App\Security\Vulnerabilities\WordfenceFeed;
use App\Security\Vulnerabilities\WordfenceFeedAddressResolver;
use App\Security\Vulnerabilities\WordfenceFeedProfiler;
use App\Security\Vulnerabilities\WordfenceSyncFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Downloads only the official v3 scanner feed, or imports an explicitly supplied local fixture/file. */
class SyncVulnerabilities extends Command
{
    protected $signature = 'security:vulnerabilities:sync {--file= : Import a local feed file instead of downloading} {--profile-feed : Write an aggregate-only offline profile during this staging synchronization}';

    protected $description = 'Download or import the Wordfence v3 vulnerability feed and rebuild its index';

    private const ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';

    private const RATE_LIMIT_KEY = 'security:vulnerabilities:wordfence:cooldown_until';

    private const RATE_LIMIT_COOLDOWN_SECONDS = 1800;

    public function handle(WordfenceFeedAddressResolver $resolver, AtomicIndexPublisher $publisher, WordfenceFeedProfiler $profiler): int
    {
        if ($this->option('profile-feed') && ($this->option('file') || ! $this->isAuthorizedStagingContext())) {
            Log::warning('Wordfence feed profiling was refused.', ['failure_class' => 'feed_profile_context_rejected']);
            $this->components->error('Offline feed profiling is restricted to the verified staging deployment.');

            return self::FAILURE;
        }

        if (config('security.vulnerabilities.source') !== 'wordfence') {
            Log::warning('Wordfence vulnerability feed synchronization failed.', ['failure_class' => 'source_not_configured']);
            $this->components->warn('No vulnerability source is configured.');

            return self::FAILURE;
        }

        $lock = Cache::lock('security:vulnerabilities:sync', 3600);
        if (! $lock->get()) {
            $this->components->warn('Another vulnerability feed synchronization is already running.');

            return self::FAILURE;
        }

        try {
            $startedAt = (int) floor(microtime(true) * 1000);
            $feedPath = (string) config('security.vulnerabilities.feed_path');
            $source = $this->option('file');
            if ($source) {
                $count = WordfenceFeed::import((string) $source, WordfenceFeed::indexPathFor($feedPath), $publisher, null, $startedAt);
                $this->components->info("Vulnerability index rebuilt from local input: {$count} affected-software entries.");

                return self::SUCCESS;
            }

            $url = (string) config('security.vulnerabilities.feed_url');
            $key = config('security.vulnerabilities.feed_key');
            if ($url !== self::ENDPOINT || ! is_string($key) || trim($key) === '') {
                Log::warning('Wordfence vulnerability feed synchronization failed.', ['failure_class' => 'invalid_configuration']);
                $this->components->error('Configure the official Wordfence v3 Scanner Feed URL and the Owner-managed API key.');

                return self::FAILURE;
            }

            $cooldownUntil = Cache::get(self::RATE_LIMIT_KEY);
            if (is_int($cooldownUntil) && $cooldownUntil > now()->getTimestamp()) {
                $remaining = $cooldownUntil - now()->getTimestamp();
                Log::warning('Wordfence vulnerability feed synchronization deferred.', [
                    'failure_class' => 'rate_limit_cooldown_active',
                    'retry_after_seconds' => $remaining,
                ]);
                $this->components->warn("Wordfence synchronization is paused by the rate-limit cooldown ({$remaining} seconds remaining).");

                return self::FAILURE;
            }

            $feedDirectory = dirname($feedPath);
            try {
                File::ensureDirectoryExists($feedDirectory);
            } catch (Throwable $exception) {
                throw new WordfenceSyncFailure('staging_filesystem_failure', 'The feed staging directory could not be prepared safely.', $exception);
            }
            $limit = min(160 * 1024 * 1024, max(1, (int) config('security.vulnerabilities.max_bytes', 160 * 1024 * 1024)));
            $indexLimit = (int) config('security.vulnerabilities.max_index_bytes', 128 * 1024 * 1024);
            $minimumFreeSpace = $limit + 2 * $indexLimit + 64 * 1024 * 1024;
            $freeSpace = @disk_free_space($feedDirectory);
            if ($freeSpace === false || $freeSpace < $minimumFreeSpace) {
                throw new WordfenceSyncFailure('insufficient_disk_space', 'Insufficient staging disk space.');
            }

            try {
                $addresses = $resolver->resolve('www.wordfence.com');
            } catch (Throwable $exception) {
                throw new WordfenceSyncFailure('dns_validation_failed', 'The official feed host did not resolve to safe public addresses.', $exception);
            }
            $resolve = array_map(function (string $address): string {
                $formatted = str_contains($address, ':') ? '['.$address.']' : $address;

                return 'www.wordfence.com:443:'.$formatted;
            }, $addresses);

            $download = $this->stageWordfenceFeed($url, $key, $resolve, $feedDirectory, $limit);
            try {
                if ($this->option('profile-feed')) {
                    try {
                        $failure = $profiler->profile($download, $limit);
                    } catch (Throwable) {
                        $failure = 'process_failed';
                    }
                    if ($failure !== null) {
                        if (! in_array($failure, ['input_limit', 'process_timeout', 'output_limit', 'process_failed', 'private_storage_unavailable', 'report_write_failed'], true)) {
                            $failure = 'process_failed';
                        }
                        Log::warning('Wordfence feed profiling failed.', [
                            'failure_class' => 'feed_profile_failed',
                            'failure_subcode' => $failure,
                        ]);
                    }
                }

                $count = WordfenceFeed::import($download, WordfenceFeed::indexPathFor($feedPath), $publisher, $feedPath, $startedAt);
            } finally {
                File::delete($download);
            }

            $this->components->info("Wordfence v3 vulnerability index rebuilt: {$count} affected-software entries.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $classification = $exception instanceof WordfenceSyncFailure ? $exception->classification : 'unexpected_failure';
            $context = ['failure_class' => $classification];
            if ($exception instanceof WordfenceSyncFailure && $exception->subcode !== null) {
                $context['failure_subcode'] = $exception->subcode;
            }
            Log::warning('Wordfence vulnerability feed synchronization failed.', $context);
            $this->components->error('The vulnerability feed could not be safely downloaded, validated, or published.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function isAuthorizedStagingContext(): bool
    {
        return config('app.deployment_environment') === 'staging'
            && config('app.deployment_hostname') === 'stg1.wpcaregrid.com'
            && config('app.url') === 'https://staging.wpcaregrid.com';
    }

    /** @param list<string> $resolve */
    protected function stageWordfenceFeed(string $url, string $key, array $resolve, string $feedDirectory, int $limit): string
    {
        $download = tempnam($feedDirectory, 'wpcg-feed-');
        if ($download === false || realpath(dirname($download)) !== realpath($feedDirectory)) {
            if (is_string($download)) {
                File::delete($download);
            }
            throw new WordfenceSyncFailure('staging_filesystem_failure', 'The feed could not be staged safely.');
        }
        @chmod($download, 0600);
        $sink = @fopen($download, 'wb');
        if ($sink === false) {
            File::delete($download);
            throw new WordfenceSyncFailure('staging_filesystem_failure', 'The feed download could not be opened safely.');
        }

        try {
            $response = Http::connectTimeout((int) config('security.vulnerabilities.connect_timeout_seconds', 5))
                ->timeout((int) config('security.vulnerabilities.timeout_seconds', 180))
                ->withOptions($this->wordfenceHttpOptions($resolve, $sink, $limit))
                ->acceptJson()
                ->withToken($key)
                ->get($url);
            fclose($sink);
            $sink = null;

            if (! $response->successful()) {
                $status = $response->status();
                if ($status === 429) {
                    $cooldown = $this->rateLimitCooldownSeconds((string) $response->header('Retry-After', ''));
                    Cache::put(self::RATE_LIMIT_KEY, now()->getTimestamp() + $cooldown, now()->addSeconds($cooldown));
                    throw new WordfenceSyncFailure('rate_limited', 'Wordfence rate limit reached.');
                }
                throw new WordfenceSyncFailure('http_response_rejected', 'Wordfence returned an unsuccessful response.');
            }
            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if ($contentType !== 'application/json' && ! preg_match('#^application/[a-z0-9.+-]+\\+json$#', $contentType)) {
                throw new WordfenceSyncFailure('unexpected_content_type', 'Wordfence returned an unexpected content type.');
            }

            clearstatcache(true, $download);
            $bytes = @filesize($download);
            if ($bytes === false || $bytes === 0 || $bytes > $limit) {
                throw new WordfenceSyncFailure('download_size_or_completeness_failure', 'The feed download was empty, oversized, or incomplete.');
            }

            return $download;
        } catch (Throwable $exception) {
            if (is_resource($sink)) {
                fclose($sink);
            }
            File::delete($download);
            if (! $exception instanceof WordfenceSyncFailure) {
                throw new WordfenceSyncFailure('http_transport_failure', 'The Wordfence feed could not be downloaded safely.', $exception);
            }
            throw $exception;
        }
    }

    /** @param list<string> $resolve @param resource|string $download @return array<string, mixed> */
    protected function wordfenceHttpOptions(array $resolve, mixed $download, int $limit): array
    {
        return [
            'allow_redirects' => false,
            'proxy' => '',
            'sink' => $download,
            'progress' => static fn (int $downloadSize, int $downloaded): bool => $downloadSize > $limit || $downloaded > $limit,
            'curl' => [CURLOPT_RESOLVE => $resolve],
        ];
    }

    private function rateLimitCooldownSeconds(string $retryAfter): int
    {
        $seconds = self::RATE_LIMIT_COOLDOWN_SECONDS;
        if (ctype_digit(trim($retryAfter))) {
            $seconds = max($seconds, (int) trim($retryAfter));
        } elseif ($retryAfter !== '') {
            $retryAt = strtotime($retryAfter);
            if ($retryAt !== false) {
                $seconds = max($seconds, $retryAt - now()->getTimestamp());
            }
        }

        return min(24 * 60 * 60, $seconds);
    }
}
