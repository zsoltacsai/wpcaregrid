<?php

namespace App\Models;

use App\Privacy\ErasureActionState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-action preparation and reconciliation state; no provider client is attached. */
final class PrivacyErasureExecutionAction extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['state' => ErasureActionState::class, 'blocker_codes' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PrivacyErasureExecutionPlan::class, 'execution_plan_id');
    }
}
