<?php

namespace App\Console\Commands\Security;

use App\Security\Vulnerabilities\AtomicIndexPublisher;
use App\Security\Vulnerabilities\WordfenceFeed;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Refresh the vulnerability index. Downloads the Wordfence Intelligence
 * feed only from the configured SECURITY_VULNERABILITY_FEED_URL (with
 * SECURITY_VULNERABILITY_FEED_KEY when the feed needs one), or imports a
 * feed file already on disk (--file). Never contacts anything unconfigured.
 */
class SyncVulnerabilities extends Command
{
    protected $signature = 'security:vulnerabilities:sync {--file= : Import this downloaded feed file instead of downloading}';

    protected $description = 'Download or import the vulnerability feed and rebuild its index';

    public function handle(AtomicIndexPublisher $publisher): int
    {
        if (config('security.vulnerabilities.source') !== 'wordfence') {
            $this->components->warn('No vulnerability source is configured (SECURITY_VULNERABILITY_SOURCE=wordfence).');

            return self::FAILURE;
        }

        $feedPath = (string) config('security.vulnerabilities.feed_path');
        $source = $this->option('file');

        if (! $source) {
            $url = (string) config('security.vulnerabilities.feed_url');
            if (! str_starts_with($url, 'https://')) {
                $this->components->error('Set SECURITY_VULNERABILITY_FEED_URL (https) or pass --file.');

                return self::FAILURE;
            }

            File::ensureDirectoryExists(dirname($feedPath));
            $download = tempnam(dirname($feedPath), 'wpcg-feed-');
            if ($download === false || realpath(dirname($download)) !== realpath(dirname($feedPath))) {
                if (is_string($download)) {
                    File::delete($download);
                }
                $this->components->error('The feed could not be prepared safely.');

                return self::FAILURE;
            }

            $indexPath = WordfenceFeed::indexPathFor($feedPath);
            $publishDirectory = null;
            $stagedIndex = null;

            try {
                $publishDirectory = dirname($indexPath).DIRECTORY_SEPARATOR.'wpcg-index-stage-'.bin2hex(random_bytes(12));
                if (! @mkdir($publishDirectory, 0700) || is_link($publishDirectory)
                    || realpath(dirname($publishDirectory)) !== realpath(dirname($indexPath))) {
                    throw new RuntimeException('The index publication directory could not be prepared safely.');
                }
                $stagedIndex = $publishDirectory.DIRECTORY_SEPARATOR.'index.json';

                $request = Http::connectTimeout((int) config('security.vulnerabilities.connect_timeout_seconds'))
                    ->timeout((int) config('security.vulnerabilities.timeout_seconds'))
                    ->withOptions(['allow_redirects' => false, 'stream' => true]);
                if ($key = config('security.vulnerabilities.feed_key')) {
                    $request = $request->withToken($key);
                }

                $response = $request->get($url);
                if (! $response->successful()) {
                    throw new RuntimeException('The provider returned a non-success response.');
                }
                $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
                if ($contentType !== 'application/json' && ! preg_match('#^application/[a-z0-9.+-]+\\+json$#', $contentType)) {
                    throw new RuntimeException('The provider returned an unexpected content type.');
                }

                $limit = min(64 * 1024 * 1024, max(1, (int) config('security.vulnerabilities.max_bytes')));
                $body = $response->toPsrResponse()->getBody();
                $output = fopen($download, 'wb');
                if ($output === false) {
                    throw new RuntimeException('The temporary feed file could not be opened.');
                }

                try {
                    $bytes = 0;
                    while (! $body->eof()) {
                        // Read at most one byte over the cap so an oversized response
                        // is rejected before it can be buffered or written in full.
                        $chunk = $body->read(min(8192, $limit - $bytes + 1));
                        if ($chunk === '') {
                            break;
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > $limit || fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('The feed exceeded its configured size limit or could not be written.');
                        }
                    }
                } finally {
                    fclose($output);
                }

                if ($bytes === 0) {
                    throw new RuntimeException('The feed response was empty.');
                }

                // Build the complete replacement index away from the active path.
                // The scanner reads only the index, so publish that single
                // authoritative artifact atomically. The raw response stays
                // temporary; publishing two independent active files cannot be
                // made atomic with two renames. Remove the legacy raw cache
                // before publishing, so it cannot masquerade as this index's
                // source if the atomic index replacement fails.
                $count = WordfenceFeed::import($download, $stagedIndex);
                if (file_exists($feedPath) || is_link($feedPath)) {
                    if (is_link($feedPath) || ! is_file($feedPath) || ! @unlink($feedPath)) {
                        throw new RuntimeException('The legacy feed cache could not be removed safely.');
                    }
                }
                $publisher->publish($stagedIndex, $indexPath);
            } catch (ConnectionException|RuntimeException $e) {
                $this->components->error('The feed could not be safely downloaded or validated.');

                return self::FAILURE;
            } finally {
                File::delete($download);
                if (is_string($publishDirectory) && is_dir($publishDirectory) && ! is_link($publishDirectory)) {
                    File::deleteDirectory($publishDirectory);
                }
            }
        }

        if ($source) {
            try {
                $count = WordfenceFeed::import((string) $source, WordfenceFeed::indexPathFor($feedPath));
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->components->info("Vulnerability index rebuilt: {$count} affected-software entries.");

        return self::SUCCESS;
    }
}
