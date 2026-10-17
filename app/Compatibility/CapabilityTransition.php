<?php

namespace App\Compatibility;

use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapabilityTransition extends Model
{
    protected $table = 'website_capability_transitions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'changed_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
