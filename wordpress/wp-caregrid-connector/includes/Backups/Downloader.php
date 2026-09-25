<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Core\Lock;

/**
 * Fetches a backup object for a restore, in ranges, from WP CareGrid (never
 * from storage directly). Every range is checked against its SHA-256
 * header, the whole file against the expected SHA-256.
 */
final class Downloader
{
    private const RANGE = 8 * 1024 * 1024;

    /** @var callable(int): void */
    private $sleep;

    public function __construct(private readonly Client $api, ?callable $sleep = null, private readonly int $max_retries = 6)
    {
        $this->sleep = $sleep ?? static fn (int $seconds) => sleep($seconds);
    }

    public function download(string $backup_uuid, string $name, int $size, string $sha256, string $path): void
    {
        TempDir::create($path);
        $out = fopen($path, 'wb');
        $offset = 0;
        $retries = 0;

        try {
            while ($offset < $size) {
                $response = $this->api->get_raw("backups/{$backup_uuid}/objects/{$name}/content?offset={$offset}&length=".min(self::RANGE, $size - $offset));
                Lock::refresh();

                $body = $response->body;
                if ($response->ok() && $body !== '' && hash_equals((string) $response->header('x-chunk-sha256'), hash('sha256', $body))) {
                    if (fwrite($out, $body) !== strlen($body)) {
                        throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'The backup could not be written to disk.');
                    }
                    $offset += strlen($body);
                    $retries = 0;

                    continue;
                }

                if ($response->ok() || $response->is_temporary()) {
                    if (++$retries > $this->max_retries) {
                        throw new BackupFailure('RESTORE_DOWNLOAD_FAILED', 'The backup could not be downloaded.');
                    }
                    ($this->sleep)(min(60, 2 ** $retries));

                    continue;
                }

                throw new BackupFailure('RESTORE_DOWNLOAD_FAILED', 'WP CareGrid refused the download.', ['code' => (string) $response->error_code]);
            }
        } finally {
            fclose($out);
        }

        if (! hash_equals($sha256, (string) hash_file('sha256', $path))) {
            throw new BackupFailure('RESTORE_CHECKSUM_MISMATCH', 'The downloaded backup does not match its checksum.');
        }
    }
}
