<?php

namespace App\Models;

use App\Privacy\ErasurePlanState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable preview binding with separately mutable approval/reconciliation evidence. */
final class PrivacyErasureExecutionPlan extends Model
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

    protected static function booted(): void
    {
        self::updating(function (self $plan): void {
            if ($plan->isDirty(['uuid', 'privacy_request_id', 'customer_id', 'version', 'policy_version', 'source_fingerprint', 'preview_hash', 'preview', 'blocker_codes'])) {
                throw new LogicException('Execution plan previews are immutable; create a new plan version.');
            }
            if ($plan->getOriginal('approved_at') !== null && $plan->isDirty(['approved_preview_hash', 'approved_by_user_id', 'approved_at'])) {
                throw new LogicException('Execution plan approval evidence is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'state' => ErasurePlanState::class,
            'preview' => 'array',
            'blocker_codes' => 'array',
            'version' => 'integer',
            'approved_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class, 'privacy_request_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PrivacyErasureExecutionAction::class, 'execution_plan_id')->orderBy('id');
    }
}
