<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A short-lived, session-authorized grant pinned to one verified backup object. */
class BackupDownloadGrant extends Model
{
    use HasUuids;

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const REVOKED = 'revoked';

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'block_hashes' => 'encrypted',
            'object_size' => 'integer',
            'fingerprint_block_size' => 'integer',
            'bytes_served' => 'integer',
            'range_responses' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_range_at' => 'datetime',
            'last_response_completed_at' => 'datetime',
            'last_response_interrupted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function backupObject(): BelongsTo
    {
        return $this->belongsTo(BackupObject::class);
    }
}
