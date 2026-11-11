<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A published advisory that affects the installed version of a component. */
class PlatformAdvisory extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'published_at' => 'datetime', 'resolved_at' => 'datetime', 'notified_at' => 'datetime', 'details_checked_at' => 'datetime'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PlatformComponent::class, 'platform_component_id');
    }
}
