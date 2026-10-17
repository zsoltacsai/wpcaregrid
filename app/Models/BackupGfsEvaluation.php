<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Latest bounded, non-destructive GFS/shadow result for one website. */
class BackupGfsEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'evaluated_at' => 'datetime',
            'missing_slot_count' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function entitlementSnapshot(): BelongsTo
    {
        return $this->belongsTo(SubscriptionBackupEntitlement::class, 'subscription_backup_entitlement_id');
    }
}
