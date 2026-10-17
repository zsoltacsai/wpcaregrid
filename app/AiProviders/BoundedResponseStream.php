<?php

namespace App\AiProviders;

use GuzzleHttp\Psr7\Stream;
use RuntimeException;

/** A temporary response sink that refuses the first byte beyond its fixed limit. */
final class BoundedResponseStream extends Stream
{
    private int $bytesWritten = 0;

    public function __construct(private readonly int $maximumBytes)
    {
        $resource = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($resource === false) {
            throw new RuntimeException('Unable to create a bounded response sink.');
        }

        parent::__construct($resource);
    }

    public function write(string $string): int
    {
        if ($this->bytesWritten + strlen($string) > $this->maximumBytes) {
            throw new ResponseLimitExceeded;
        }

        $written = parent::write($string);
        $this->bytesWritten += $written;

        return $written;
    }
}
