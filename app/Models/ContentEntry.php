<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One content section in one locale: a draft (what the owner is working
 * on) and a published version (what public pages show). Values follow the
 * section's schema (App\Content\Schema\ContentSchema) and are validated
 * before they are stored.
 */
class ContentEntry extends Model
{
    protected $fillable = ['key', 'locale'];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
            'draft_updated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function draftAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
