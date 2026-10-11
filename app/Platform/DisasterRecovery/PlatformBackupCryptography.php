<?php

namespace App\Platform\DisasterRecovery;

use RuntimeException;

/** Streaming, versioned platform-backup encryption and authenticated manifests. */
final class PlatformBackupCryptography
{
    private const MAGIC = "WPCG-PLATFORM-BACKUP\x01\n";

    private const CHUNK_BYTES = 1048576;

    /** @return array{version: int, algorithm: string, key_id: string, header: string} */
    public function encryptFile(string $source, string $destination): array
    {
        $keyId = (string) config('platform_backup.encryption.current_key_id');
        $key = $this->key($keyId);
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not open private platform-backup encryption files.');
        }
        chmod($destination, 0600);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        try {
            $this->writeAll($output, self::MAGIC.$header);
            while (! feof($input)) {
                $plain = fread($input, self::CHUNK_BYTES);
                if ($plain === false) {
                    throw new RuntimeException('Could not read platform-backup source during encryption.');
                }
                if ($plain === '') {
                    continue;
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                $this->writeFrame($output, $cipher);
            }
            $this->writeFrame($output, sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL));
        } finally {
            sodium_memzero($key);
            sodium_memzero($state);
            fclose($input);
            fclose($output);
        }

        return ['version' => 1, 'algorithm' => 'libsodium-secretstream-xchacha20poly1305', 'key_id' => $keyId, 'header' => base64_encode($header)];
    }

    /** Decrypts only authenticated frames and rejects truncation or trailing data. */
    public function decryptFile(string $source, string $destination, array $metadata): void
    {
        if (($metadata['version'] ?? null) !== 1
            || ($metadata['algorithm'] ?? null) !== 'libsodium-secretstream-xchacha20poly1305'
            || ! is_string($metadata['header'] ?? null)) {
            throw new RuntimeException('Unsupported platform-backup encryption format.');
        }
        $key = $this->key((string) ($metadata['key_id'] ?? ''));
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            sodium_memzero($key);
            throw new RuntimeException('Could not open private platform-backup decryption files.');
        }
        chmod($destination, 0600);
        $state = null;
        try {
            $magic = $this->readExact($input, strlen(self::MAGIC));
            $header = base64_decode($metadata['header'], true);
            if ($magic !== self::MAGIC || $header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Invalid platform-backup encryption header.');
            }
            $embeddedHeader = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (! hash_equals($header, $embeddedHeader)) {
                throw new RuntimeException('Platform-backup encryption header does not match its authenticated metadata.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finalSeen = false;
            while (! feof($input)) {
                $lengthBytes = fread($input, 4);
                if ($lengthBytes === '' && feof($input)) {
                    break;
                }
                if (! is_string($lengthBytes) || strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('Truncated platform-backup encrypted frame.');
                }
                $length = unpack('Nlength', $lengthBytes)['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Invalid platform-backup encrypted frame length ('.$length.').');
                }
                $cipher = $this->readExact($input, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($pulled === false) {
                    throw new RuntimeException('Platform-backup authentication tag verification failed.');
                }
                [$plain, $tag] = $pulled;
                if ($finalSeen) {
                    throw new RuntimeException('Unexpected data after the final platform-backup frame.');
                }
                $this->writeAll($output, $plain);
                $finalSeen = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! $finalSeen || fread($input, 1) !== '') {
                throw new RuntimeException('Platform-backup ciphertext is truncated or has trailing data.');
            }
        } finally {
            if (is_string($state)) {
                sodium_memzero($state);
            }
            sodium_memzero($key);
            fclose($input);
            fclose($output);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function signManifest(array $manifest, string $keyId): string
    {
        unset($manifest['manifest_hmac']);
        $key = $this->key($keyId);
        $macKey = sodium_crypto_generichash('wpcg-platform-manifest-v1', $key, 32);
        sodium_memzero($key);
        try {
            return hash_hmac('sha256', $this->canonicalJson($manifest), $macKey);
        } finally {
            sodium_memzero($macKey);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function verifyManifest(array $manifest): bool
    {
        $expected = $manifest['manifest_hmac'] ?? null;
        $keyId = data_get($manifest, 'encryption.key_id');
        if (! is_string($expected) || ! preg_match('/\A[a-f0-9]{64}\z/', $expected) || ! is_string($keyId)) {
            return false;
        }
        try {
            return hash_equals($expected, $this->signManifest($manifest, $keyId));
        } catch (RuntimeException) {
            return false;
        }
    }

    private function key(string $keyId): string
    {
        if (! extension_loaded('sodium') || $keyId === '' || ! preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/', $keyId)) {
            throw new RuntimeException('Platform-backup encryption is not configured with a supported key.');
        }
        $encoded = config('platform_backup.encryption.keys.'.$keyId);
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Platform-backup encryption key is unavailable or invalid.');
        }

        return $key;
    }

    /** @param resource $stream */
    private function writeFrame($stream, string $frame): void
    {
        $this->writeAll($stream, pack('N', strlen($frame)).$frame);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write private platform-backup artifact.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($value));
            if (! is_string($chunk) || $chunk === '') {
                break;
            }
            $value .= $chunk;
        }
        if (strlen($value) !== $length) {
            throw new RuntimeException('Truncated platform-backup artifact.');
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
