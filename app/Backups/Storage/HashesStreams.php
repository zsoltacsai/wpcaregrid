<?php

namespace App\Backups\Storage;

/** Read-back verification: hash an object in fixed-size pieces, never all at once. */
trait HashesStreams
{
    /** @param  callable(int $offset, int $length): string  $read */
    protected function readbackHash(int $size, callable $read, int $piece = 8 * 1024 * 1024): string
    {
        $hash = hash_init('sha256');

        for ($offset = 0; $offset < $size; $offset += $piece) {
            $data = $read($offset, min($piece, $size - $offset));
            if ($data === '') {
                break;
            }
            hash_update($hash, $data);
        }

        return hash_final($hash);
    }

    /** @param  resource  $stream */
    protected function streamHash($stream): string
    {
        $hash = hash_init('sha256');
        while (! feof($stream)) {
            $data = fread($stream, 1024 * 1024);
            if ($data === false) {
                throw new StorageUnavailable('Reading the stored object failed.');
            }
            hash_update($hash, $data);
        }

        return hash_final($hash);
    }
}
