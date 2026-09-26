<?php

namespace App\Console\Commands\Security;

use App\Security\Vulnerabilities\WordfenceFeed;
use Illuminate\Console\Command;
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

    public function handle(): int
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
            $request = Http::timeout(600)->withOptions(['sink' => $feedPath.'.download']);
            if ($key = config('security.vulnerabilities.feed_key')) {
                $request = $request->withToken($key);
            }

            $response = $request->get($url);
            if (! $response->successful()) {
                File::delete($feedPath.'.download');
                $this->components->error("The feed could not be downloaded (HTTP {$response->status()}).");

                return self::FAILURE;
            }

            rename($feedPath.'.download', $feedPath);
            $source = $feedPath;
        }

        try {
            $count = WordfenceFeed::import((string) $source, WordfenceFeed::indexPathFor($feedPath));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Vulnerability index rebuilt: {$count} affected-software entries.");

        return self::SUCCESS;
    }
}
