<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyRetentionPreview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'evaluated_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PrivacyRetentionPolicy::class, 'policy_id');
    }
}
