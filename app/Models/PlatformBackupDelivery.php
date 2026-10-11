<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformBackupDelivery extends Model
{
    protected $guarded = ['id'];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(PlatformDatabaseBackup::class, 'platform_database_backup_id');
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }
}
