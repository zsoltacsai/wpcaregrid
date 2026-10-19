<?php

namespace App\Console\Commands\Security;

use App\Security\Vulnerabilities\AtomicIndexPublisher;
use App\Security\Vulnerabilities\WordfenceFeed;
use App\Security\Vulnerabilities\WordfenceFeedAddressResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Downloads only the official v3 scanner feed, or imports an explicitly supplied local fixture/file. */
class SyncVulnerabilities extends Command
{
    protected $signature = 'security:vulnerabilities:sync {--file= : Import a local feed file instead of downloading}';

    protected $description = 'Download or import the Wordfence v3 vulnerability feed and rebuild its index';

    private const ENDPOINT = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';

    public function handle(WordfenceFeedAddressResolver $resolver, AtomicIndexPublisher $publisher): int
    {
        if (config('security.vulnerabilities.source') !== 'wordfence') {
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
                $this->components->error('Configure the official Wordfence v3 Scanner Feed URL and the Owner-managed API key.');

                return self::FAILURE;
            }

            $feedDirectory = dirname($feedPath);
            File::ensureDirectoryExists($feedDirectory);
            $limit = min(160 * 1024 * 1024, max(1, (int) config('security.vulnerabilities.max_bytes', 160 * 1024 * 1024)));
            $indexLimit = (int) config('security.vulnerabilities.max_index_bytes', 128 * 1024 * 1024);
            $minimumFreeSpace = $limit + 2 * $indexLimit + 64 * 1024 * 1024;
            $freeSpace = @disk_free_space($feedDirectory);
            if ($freeSpace === false || $freeSpace < $minimumFreeSpace) {
                throw new RuntimeException('Insufficient staging disk space.');
            }

            $download = tempnam($feedDirectory, 'wpcg-feed-');
            if ($download === false || realpath(dirname($download)) !== realpath($feedDirectory)) {
                if (is_string($download)) {
                    File::delete($download);
                }
                throw new RuntimeException('The feed could not be staged safely.');
            }
            @chmod($download, 0600);

            try {
                $addresses = $resolver->resolve('www.wordfence.com');
                $resolve = array_map(function (string $address): string {
                    $formatted = str_contains($address, ':') ? '['.$address.']' : $address;

                    return 'www.wordfence.com:443:'.$formatted;
                }, $addresses);

                $request = Http::connectTimeout((int) config('security.vulnerabilities.connect_timeout_seconds', 5))
                    ->timeout((int) config('security.vulnerabilities.timeout_seconds', 180))
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'proxy' => '',
                        'curl' => [CURLOPT_RESOLVE => $resolve],
                    ])
                    ->acceptJson()
                    ->withToken($key);

                $response = $request->get($url);
                if (! $response->successful()) {
                    $status = $response->status();
                    Log::warning('Wordfence vulnerability feed request failed.', ['status' => $status]);
                    throw new RuntimeException($status === 429 ? 'Wordfence rate limit reached.' : 'Wordfence returned an unsuccessful response.');
                }
                $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
                if ($contentType !== 'application/json' && ! preg_match('#^application/[a-z0-9.+-]+\+json$#', $contentType)) {
                    throw new RuntimeException('Wordfence returned an unexpected content type.');
                }

                $body = $response->toPsrResponse()->getBody();
                $output = @fopen($download, 'wb');
                if ($output === false) {
                    throw new RuntimeException('The temporary feed file could not be opened.');
                }
                try {
                    $bytes = 0;
                    while (! $body->eof()) {
                        $chunk = $body->read(min(65536, $limit - $bytes + 1));
                        if ($chunk === '') {
                            break;
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > $limit || fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('The feed exceeded its size limit or could not be written.');
                        }
                    }
                    if (! fflush($output) || $bytes === 0) {
                        throw new RuntimeException('The feed download was empty or incomplete.');
                    }
                } finally {
                    fclose($output);
                }

                $count = WordfenceFeed::import($download, WordfenceFeed::indexPathFor($feedPath), $publisher, $feedPath, $startedAt);
            } finally {
                File::delete($download);
            }

            $this->components->info("Wordfence v3 vulnerability index rebuilt: {$count} affected-software entries.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Only the exception class is retained; provider messages and HTTP headers may contain secrets.
            Log::warning('Wordfence vulnerability feed synchronization failed.', ['exception_class' => $exception::class]);
            $this->components->error('The vulnerability feed could not be safely downloaded, validated, or published.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
