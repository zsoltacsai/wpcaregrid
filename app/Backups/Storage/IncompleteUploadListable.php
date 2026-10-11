<?php

namespace App\Backups\Storage;

/** Optional read-only inventory for interrupted multipart or temporary uploads. */
interface IncompleteUploadListable
{
    /** @return iterable<array{object_key: string, initiated_at: ?string}> */
    public function listIncompleteUploads(): iterable;
}
