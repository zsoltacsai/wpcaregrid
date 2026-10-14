<?php

namespace App\Models;

use App\Privacy\PrivacyProvider;
use App\Privacy\PrivacyProviderTaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Future provider reconciliation metadata only. This model never calls a provider. */
class PrivacyProviderTask extends Model
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
            'provider' => PrivacyProvider::class,
            'status' => PrivacyProviderTaskStatus::class,
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }
}
