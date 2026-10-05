<?php

namespace App\Backups\Storage;

/** Validates the S3 encryption settings without exposing secret values. */
final class S3EncryptionConfig
{
    /**
     * Return a safe configuration issue, or null when encryption settings are valid.
     *
     * @param  array<string, mixed>  $config
     */
    public static function issue(array $config, bool $production): ?string
    {
        $mode = $config['encryption_mode'] ?? 'legacy';
        if (! in_array($mode, ['legacy', 'sse-s3', 'sse-c', 'none'], true)) {
            return 'Unsupported S3 encryption mode.';
        }

        if ($mode === 'sse-c') {
            $key = $config['sse_customer_key'] ?? null;
            if (! is_string($key) || preg_match('/\A[0-9a-fA-F]{64}\z/D', $key) !== 1 || strlen((string) hex2bin($key)) !== 32) {
                return 'S3 SSE-C requires a 64-character hexadecimal key (32 bytes).';
            }
        }

        if ($mode === 'none' && $production && ($config['allow_unencrypted'] ?? false) !== true) {
            return 'S3 encryption mode "none" requires BACKUP_S3_ALLOW_UNENCRYPTED=true in production to confirm provider-side at-rest encryption.';
        }

        return null;
    }
}
