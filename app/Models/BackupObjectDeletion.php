<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-logical-object deletion request and read-after-delete evidence. */
class BackupObjectDeletion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'verified_absent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
