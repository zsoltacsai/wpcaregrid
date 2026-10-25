<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Core\Lock;
use WPCareGrid\Connector\Security\Logger;

/**
 * Sends a local backup file to WP CareGrid in chunks (raw PUT bodies, each
 * with its SHA-256). Resumable: it starts from the offset the server says
 * it has, and continues from the server's expected offset after a
 * mismatch. Temporary failures are retried with backoff, a limited number
 * of times; the job then fails (the file stays for a later resume).
 */
final class Uploader
{
    /** @var callable(int): void */
    private $sleep;

    public function __construct(private readonly Client $api, ?callable $sleep = null, private readonly int $max_retries = 6)
    {
        $this->sleep = $sleep ?? static fn (int $seconds) => sleep($seconds);
    }

    public function upload(string $backup_uuid, string $name, string $path, int $size, string $sha256, int $chunk_size): void
    {
        $base = "backups/{$backup_uuid}/objects/{$name}";
        Lock::refreshOrFail();
        $status = $this->api->get($base);
        Lock::refreshOrFail();
        $this->stop_if_refused($status, 'status');

        $offset = min($size, max(0, (int) ($status->data['received_bytes'] ?? 0)));
        if (($status->data['status'] ?? null) === 'received') {
            return;
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new BackupFailure('UPLOAD_FAILED', 'The backup file to upload is missing.');
        }

        $retries = 0;
        $restarted = false;

        try {
            while (true) {
                while ($offset < $size) {
                    Lock::refreshOrFail();
                    fseek($handle, $offset);
                    $chunk = (string) fread($handle, min($chunk_size, $size - $offset));
                    $response = $this->api->put_raw("{$base}?offset={$offset}", $chunk, ['X-Chunk-SHA256' => hash('sha256', $chunk)]);
                    Lock::refreshOrFail();

                    if ($response->ok()) {
                        $offset = (int) ($response->data['received_bytes'] ?? $offset + strlen($chunk));
                        $retries = 0;

                        continue;
                    }

                    if ($response->status === 409 && $response->error_code === 'OFFSET_MISMATCH' && isset($response->error_details()['expected_offset'])) {
                        $offset = (int) $response->error_details()['expected_offset'];

                        continue;
                    }

                    if ($response->status === 409 && $response->error_code === 'OBJECT_COMPLETE') {
                        break;
                    }

                    $this->retry_or_fail($response, $retries);
                }

                Lock::refreshOrFail();
                $response = $this->api->post("{$base}/complete", ['size' => $size, 'sha256' => $sha256]);
                Lock::refreshOrFail();
                if ($response->ok()) {
                    return;
                }

                // The server discarded what it had: upload the whole object once more.
                if ($response->error_code === 'CHECKSUM_MISMATCH' && ! $restarted) {
                    Logger::warning('backup.upload_restarted', ['object' => $name]);
                    $restarted = true;
                    $offset = 0;

                    continue;
                }

                $this->retry_or_fail($response, $retries);
            }
        } finally {
            fclose($handle);
        }
    }

    private function retry_or_fail($response, int &$retries): void
    {
        $this->stop_if_refused($response, 'upload');

        if (! $response->is_temporary() && $response->error_code !== 'CHUNK_REJECTED') {
            throw new BackupFailure('UPLOAD_REJECTED', 'WP CareGrid refused the upload.', ['code' => (string) $response->error_code]);
        }

        if (++$retries > $this->max_retries) {
            throw new BackupFailure('UPLOAD_FAILED', 'The upload did not complete.', ['code' => (string) ($response->error_code ?? 'HTTP_'.$response->status)]);
        }

        ($this->sleep)(min(60, 2 ** $retries));
    }

    private function stop_if_refused($response, string $step): void
    {
        if ($response->is_revoked()) {
            throw new BackupFailure('CONNECTOR_REVOKED', 'The connector was disconnected.');
        }

        if (in_array($response->error_code, ['BACKUP_NOT_ACCEPTING', 'BACKUP_NOT_FOUND'], true)) {
            throw new BackupFailure('BACKUP_NOT_ACCEPTED', 'WP CareGrid no longer accepts this backup.', ['code' => $response->error_code]);
        }

        if ($step === 'status' && ! $response->ok() && ! $response->is_temporary()) {
            throw new BackupFailure('UPLOAD_REJECTED', 'WP CareGrid refused the upload.', ['code' => (string) $response->error_code]);
        }
    }
}
