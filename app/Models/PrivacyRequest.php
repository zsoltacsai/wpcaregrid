<?php

namespace App\Models;

use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Non-destructive case metadata; deliberately contains no request narrative or raw identity payload. */
class PrivacyRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

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
            'request_type' => PrivacyRequestType::class,
            'status' => PrivacyRequestStatus::class,
            'source' => PrivacyRequestSource::class,
            'requested_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrivacyRequestEvent::class)->orderBy('id');
    }

    public function providerTasks(): HasMany
    {
        return $this->hasMany(PrivacyProviderTask::class);
    }
}
