<?php

namespace App\Backups\CustomerDownloads;

final readonly class DownloadRange
{
    public function __construct(
        public int $offset,
        public int $length,
        public int $total,
        public string $bytes,
    ) {}

    public function contentRange(): string
    {
        return 'bytes '.$this->offset.'-'.($this->offset + $this->length - 1).'/'.$this->total;
    }
}
