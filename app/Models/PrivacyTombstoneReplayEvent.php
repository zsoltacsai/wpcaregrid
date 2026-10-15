<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PrivacyTombstoneReplayEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PrivacyTombstoneReplaySession::class, 'replay_session_id');
    }
}
