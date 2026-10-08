<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SzamlazzSyntheticTestRun extends Model
{
    protected $fillable = [
        'execution_slot', 'designation', 'status', 'prepared_by', 'submitted_by', 'tax_code', 'currency', 'amount',
        'test_account_attested', 'test_seller_attested', 'credential_reference', 'external_reference',
        'snapshot_sha256', 'commercial_snapshot', 'provider_invoice_number', 'provider_metadata', 'error_category',
        'pdf_disk', 'pdf_path', 'pdf_sha256', 'pdf_bytes', 'submitted_at', 'resolved_at',
    ];

    protected $hidden = ['commercial_snapshot', 'provider_metadata'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'test_account_attested' => 'boolean',
            'test_seller_attested' => 'boolean',
            'commercial_snapshot' => 'encrypted:array',
            'provider_metadata' => 'array',
            'submitted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Synthetic provider test evidence cannot be deleted.'));
        static::updating(function (self $run): void {
            foreach ([
                'execution_slot', 'designation', 'prepared_by', 'tax_code', 'currency', 'amount',
                'test_account_attested', 'test_seller_attested', 'credential_reference',
                'external_reference', 'snapshot_sha256', 'commercial_snapshot',
            ] as $immutable) {
                if ($run->isDirty($immutable)) {
                    throw new LogicException('Synthetic provider test commercial evidence is immutable.');
                }
            }
        });
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
