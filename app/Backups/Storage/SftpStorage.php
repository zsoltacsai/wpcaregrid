<?php

namespace App\Backups\Storage;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * SFTP storage. The server's host key must match the configured SHA256
 * fingerprint before any credential is sent. SFTP has no checksum command,
 * so verification reads the object back in pieces and hashes it. Uploads
 * go to a ".part" name and are renamed when complete.
 */
final class SftpStorage implements IncompleteUploadListable, ListableBackupStorage
{
    use HashesStreams;

    private ?SFTP $sftp = null;

    /** @param  array<string, mixed>  $config  config('backup.providers.sftp') */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'sftp';
    }

    public function put(string $key, $stream, int $size, string $sha256): void
    {
        $sftp = $this->connection();
        $path = $this->path($key);

        if (! $sftp->is_dir(dirname($path)) && ! $sftp->mkdir(dirname($path), 0700, true)) {
            throw new StorageUnavailable('The SFTP directory cannot be created.');
        }

        if (! $sftp->put($path.'.part', $stream)) {
            throw new StorageUnavailable('SFTP upload failed.');
        }

        $sftp->delete($path, false);
        if (! $sftp->rename($path.'.part', $path)) {
            throw new StorageUnavailable('SFTP rename failed.');
        }
    }

    public function verify(string $key, int $size, string $sha256): Verification
    {
        $sftp = $this->connection();
        $path = $this->path($key);
        $stat = $sftp->stat($path);

        if ($stat === false) {
            return Verification::failed('MISSING');
        }

        $remoteSize = (int) ($stat['size'] ?? -1);
        if ($remoteSize !== $size) {
            return Verification::failed('SIZE_MISMATCH', $remoteSize);
        }

        if (! config('backup.transfer.readback')) {
            return Verification::failed('UNVERIFIABLE', $remoteSize);
        }

        $actual = $this->readbackHash($size, fn (int $offset, int $length) => $this->readRange($key, $offset, $length));

        return hash_equals($sha256, $actual)
            ? Verification::passed(Verification::READBACK, $remoteSize)
            : Verification::failed('CHECKSUM_MISMATCH', $remoteSize, Verification::READBACK);
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        $data = $this->connection()->get($this->path($key), false, $offset, $length);

        if ($data === false) {
            throw new StorageUnavailable('SFTP read failed.');
        }

        return (string) $data;
    }

    public function delete(string $key): void
    {
        $sftp = $this->connection();
        $path = $this->path($key);
        $sftp->delete($path, false);
        $sftp->rmdir(dirname($path));
        $sftp->rmdir(dirname($path, 2));
    }

    public function listKeys(): iterable
    {
        $sftp = $this->connection();
        $root = rtrim((string) $this->config['root'], '/');
        $keys = [];
        $walk = function (string $directory) use (&$walk, &$keys, $sftp, $root): void {
            $entries = $sftp->nlist($directory);
            if (! is_array($entries)) {
                throw new StorageUnavailable('SFTP backup listing failed.');
            }
            foreach ($entries as $entry) {
                $name = is_array($entry) ? (string) ($entry['filename'] ?? '') : (string) $entry;
                if ($name === '' || $name === '.' || $name === '..') {
                    continue;
                }
                $path = $directory.'/'.$name;
                $stat = $sftp->lstat($path);
                if (! is_array($stat)) {
                    continue;
                }
                $type = ((int) ($stat['mode'] ?? 0)) & 0170000;
                if ($type === 0040000) {
                    $walk($path);
                } elseif ($type === 0100000 && str_starts_with($path, $root.'/')) {
                    $key = substr($path, strlen($root) + 1);
                    try {
                        $keys[] = ObjectKey::assert($key);
                    } catch (\InvalidArgumentException) {
                        // Ignore files outside the documented backup object format.
                    }
                }
            }
        };
        if ($sftp->is_dir($root)) {
            $walk($root);
        }

        return $keys;
    }

    public function listIncompleteUploads(): iterable
    {
        $uploads = [];
        foreach ($this->listRecursiveFiles() as [$path, $stat]) {
            if (str_ends_with($path, '.part')) {
                $uploads[] = ['object_key' => substr($path, strlen(rtrim((string) $this->config['root'], '/')) + 1), 'initiated_at' => isset($stat['mtime']) ? date(DATE_ATOM, (int) $stat['mtime']) : null];
            }
        }

        return $uploads;
    }

    /** @return iterable<array{0: string, 1: array<string, mixed>}> */
    private function listRecursiveFiles(): iterable
    {
        $sftp = $this->connection();
        $root = rtrim((string) $this->config['root'], '/');
        $files = [];
        $walk = function (string $directory) use (&$walk, &$files, $sftp): void {
            $entries = $sftp->nlist($directory);
            if (! is_array($entries)) {
                throw new StorageUnavailable('SFTP backup listing failed.');
            }
            foreach ($entries as $entry) {
                $name = is_array($entry) ? (string) ($entry['filename'] ?? '') : (string) $entry;
                if ($name === '' || $name === '.' || $name === '..') {
                    continue;
                }
                $path = $directory.'/'.$name;
                $stat = $sftp->lstat($path);
                if (! is_array($stat)) {
                    continue;
                }
                $type = ((int) ($stat['mode'] ?? 0)) & 0170000;
                if ($type === 0040000) {
                    $walk($path);
                } elseif ($type === 0100000) {
                    $files[] = [$path, $stat];
                }
            }
        };
        if ($sftp->is_dir($root)) {
            $walk($root);
        }

        return $files;
    }

    private function path(string $key): string
    {
        return rtrim((string) $this->config['root'], '/').'/'.ObjectKey::assert($key);
    }

    private function connection(): SFTP
    {
        if ($this->sftp?->isConnected()) {
            return $this->sftp;
        }

        $sftp = new SFTP((string) $this->config['host'], (int) $this->config['port'], (int) $this->config['timeout']);

        $hostKey = @$sftp->getServerPublicHostKey();
        if (! is_string($hostKey) || ! hash_equals((string) $this->config['host_fingerprint'], self::fingerprint($hostKey))) {
            throw new StorageUnavailable('SFTP host key does not match the configured fingerprint.');
        }

        $secret = ! empty($this->config['private_key'])
            ? PublicKeyLoader::load((string) file_get_contents((string) $this->config['private_key']), $this->config['passphrase'] ?: false)
            : (string) $this->config['password'];

        if (! @$sftp->login((string) $this->config['username'], $secret)) {
            throw new StorageUnavailable('SFTP login failed.');
        }

        return $this->sftp = $sftp;
    }

    /** "SHA256:…" as printed by ssh-keygen -lf, from "ssh-ed25519 AAAA…". */
    public static function fingerprint(string $hostKey): string
    {
        $blob = base64_decode(explode(' ', trim($hostKey))[1] ?? '', true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) $blob, true)), '=');
    }
}
