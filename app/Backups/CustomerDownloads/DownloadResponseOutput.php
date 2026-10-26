<?php

namespace App\Backups\CustomerDownloads;

/** Small output boundary that keeps response accounting testable. */
class DownloadResponseOutput
{
    /**
     * Submit a chunk to PHP's response output and return its attempted byte
     * count. This is not an acknowledgement that the remote client received it.
     */
    public function write(string $chunk): int
    {
        echo $chunk;

        return strlen($chunk);
    }

    /** Flush submitted bytes and report a disconnect when PHP detects one. */
    public function flushAndCheckAbort(): bool
    {
        flush();

        return connection_aborted() > 0;
    }
}
