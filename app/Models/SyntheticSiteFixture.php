<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Server-created marker and bounded backup/restore entitlement for one synthetic test site. */
class SyntheticSiteFixture extends Model
{
    protected $fillable = [
        'purpose', 'environment', 'customer_id', 'website_id', 'enabled',
        'backup_restore_entitled', 'created_by_user_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'backup_restore_entitled' => 'boolean',
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isActive(): bool
    {
        return $this->enabled && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
