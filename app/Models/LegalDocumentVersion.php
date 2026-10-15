<?php

namespace App\Models;

use App\Content\HtmlSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class LegalDocumentVersion extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    private const IMMUTABLE_AFTER_DRAFT = [
        'document_type', 'version', 'locale', 'title', 'body', 'content_sha256', 'acceptance_mode',
        'audiences', 'effective_at', 'approval_reference', 'created_by_user_id', 'approved_by_user_id',
        'prepared_at', 'approved_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            $document->uuid ??= (string) Str::uuid();
            $document->body = HtmlSanitizer::clean($document->body);
        });

        static::updating(function (self $document): void {
            if ($document->getOriginal('status') !== 'draft' && $document->isDirty(self::IMMUTABLE_AFTER_DRAFT)) {
                throw new LogicException('Approved legal document versions are immutable; create a new version.');
            }
            $from = $document->getOriginal('status');
            $to = $document->status;
            $validTransition = $from === $to
                || $from === 'draft' && $to === 'approved'
                || $from === 'approved' && $to === 'published'
                || $from === 'published' && $to === 'retired';
            if (! $validTransition) {
                throw new LogicException('Illegal legal document lifecycle transition.');
            }
            if ($document->isDirty('body')) {
                $document->body = HtmlSanitizer::clean($document->body);
            }
        });

        static::deleting(fn () => throw new LogicException('Legal document version history is retained and cannot be deleted.'));
    }

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
            'audiences' => 'array',
            'effective_at' => 'datetime',
            'prepared_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalDocumentAcceptance::class);
    }

    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, ['published', 'retired'], true)
            && $this->published_at !== null
            && $this->effective_at !== null
            && $this->effective_at->isPast();
    }
}
