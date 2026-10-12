<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringTransition extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(MonitoringIncident::class, 'incident_id');
    }

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }
}
