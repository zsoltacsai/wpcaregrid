<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class LegalDocumentAcceptance extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'uuid'];

    protected static function booted(): void
    {
        static::creating(fn (self $acceptance) => $acceptance->uuid ??= (string) Str::uuid());
        static::updating(fn () => throw new LogicException('Legal acceptance records are append-only.'));
        static::deleting(fn () => throw new LogicException('Legal acceptance records are append-only.'));
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
