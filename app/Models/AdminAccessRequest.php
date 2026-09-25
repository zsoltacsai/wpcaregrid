<?php

namespace App\Models;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One request to open a website's WordPress admin. Holds only a hash of its one-time token. */
class AdminAccessRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['token_hash'];

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
            'access_level' => AccessLevel::class,
            'actor_type' => ActorType::class,
            'status' => AdminAccessStatus::class,
            'token_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'authorized_at' => 'datetime',
            'used_at' => 'datetime',
            'started_at' => 'datetime',
            'session_expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WebsiteJob::class, 'job_id');
    }

    public function transitionTo(AdminAccessStatus $next, array $attributes = []): void
    {
        if (! in_array($next, $this->status->transitions(), true)) {
            throw new LogicException("Admin access {$this->uuid} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->forceFill(['status' => $next] + $attributes)->save();
    }

    /** The WordPress session exists (or is being created) and has not expired. */
    public function isLive(): bool
    {
        return in_array($this->status, [AdminAccessStatus::Authorized, AdminAccessStatus::Active], true)
            && $this->session_expires_at?->isFuture();
    }
}
