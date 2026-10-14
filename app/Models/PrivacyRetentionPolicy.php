<?php

namespace App\Models;

use App\Privacy\DestructiveRetentionGate;
use App\Privacy\PrivacyDataCategoryRegistry;
use App\Privacy\RetentionAction;
use App\Privacy\RetentionApprovalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Versioned policy metadata. No scheduler or deletion executor consumes this model in Wave 1. */
class PrivacyRetentionPolicy extends Model
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
            'duration_value' => 'integer',
            'action' => RetentionAction::class,
            'approval_status' => RetentionApprovalStatus::class,
            'effective_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'enabled' => 'boolean',
        ];
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
