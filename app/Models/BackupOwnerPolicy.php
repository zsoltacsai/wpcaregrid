<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Non-destructive Owner extension above a subscription's immutable purchased floor. */
class BackupOwnerPolicy extends Model
{
    protected $table = 'subscription_backup_owner_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'daily_recovery_points' => 'integer',
            'weekly_recovery_points' => 'integer',
            'monthly_recovery_points' => 'integer',
            'desired_post_service_days' => 'integer',
            'version' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
