<?php

namespace App\Models;

use App\Backups\BackupObjectName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One object of a backup (files archive, database dump, manifest) and its upload/verification state. */
class BackupObject extends Model
{
    public const PENDING = 'pending';

    public const RECEIVING = 'receiving';

    public const RECEIVED = 'received';

    public const STORED = 'stored';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_bytes' => 'integer',
            'size' => 'integer',
            'remote_size' => 'integer',
            'stored_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function objectName(): BackupObjectName
    {
        return BackupObjectName::from($this->name);
    }

    /** Where received data waits on this server until it is stored. */
    public function stagingPath(): string
    {
        return rtrim(config('backup.upload.staging_path'), '/').'/'.$this->backup->uuid.'/'.$this->objectName()->filename();
    }
}
