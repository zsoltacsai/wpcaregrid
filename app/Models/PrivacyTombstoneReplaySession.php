<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PrivacyTombstoneReplaySession extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'events_discovered' => 'integer',
            'events_applied' => 'integer',
            'events_already_applied' => 'integer',
            'events_blocked' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrivacyTombstoneReplayEvent::class, 'replay_session_id');
    }
}
