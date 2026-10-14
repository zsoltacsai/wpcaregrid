<?php

namespace App\Models;

use App\Privacy\DestructiveRetentionGate;
use App\Privacy\PrivacyDataCategoryRegistry;
use App\Privacy\RetentionAction;
use App\Privacy\RetentionApprovalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Versioned policy metadata. No scheduler or deletion executor consumes this model in Wave 1. */
class PrivacyRetentionPolicy extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $policy): void {
            if ($policy->getOriginal('activated_at') === null) {
                return;
            }

            // An activated version may only advance its lifecycle to superseded.
            // Its policy value, approvals, references, and preview binding are immutable.
            if ($policy->isDirty([
                'policy_key', 'version', 'category_code', 'trigger_code', 'duration_value', 'duration_unit',
                'setting_type', 'technical_value', 'action', 'legal_review_reference', 'accounting_review_reference',
                'security_review_reference', 'owner_decision_reference', 'legal_review_status',
                'accounting_review_status', 'security_review_status', 'owner_approval_status', 'compliance_lock',
                'effective_at', 'owner_approved_by', 'owner_approved_at', 'active_preview_hash',
            ])) {
                throw new LogicException('Activated retention policy versions are immutable; create a new version.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'duration_value' => 'integer',
            'technical_value' => 'array',
            'action' => RetentionAction::class,
            'approval_status' => RetentionApprovalStatus::class,
            'effective_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'enabled' => 'boolean',
            'compliance_lock' => 'boolean',
            'owner_approved_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function isImmutableForReporting(): bool
    {
        return $this->activated_at !== null || $this->enabled;
    }

    public function isExecutable(DestructiveRetentionGate $gate): bool
    {
        $category = PrivacyDataCategoryRegistry::all()[$this->category_code] ?? null;

        return $gate->enabled()
            && $this->enabled
            && $this->approval_status === RetentionApprovalStatus::Approved
            && $this->action === RetentionAction::Delete
            && $this->duration_value !== null
            && $this->duration_value > 0
            && $this->duration_unit !== null
            && $this->effective_at !== null
            && $this->effective_at->isPast()
            && filled($this->legal_review_reference)
            && $category !== null
            && (! $category['immutable_evidence_candidate'] || filled($this->accounting_review_reference));
    }
}
