<?php

namespace App\Models;

use App\Pricing\PriceKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A technical price managed in the owner area (docs/PRICING_ADMIN.md). The
 * published columns are what the catalogue and checkout use; the draft is
 * never used until it is validated and published.
 */
class PriceDefinition extends Model
{
    protected $fillable = ['product_type', 'tier', 'interval', 'market'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'active' => 'boolean',
            'published_at' => 'datetime',
            'retired_stripe_price_ids' => 'array',
            'draft' => 'array',
            'draft_updated_at' => 'datetime',
        ];
    }

    public function scopeForKey(Builder $query, PriceKey $key): void
    {
        $query->where($key->attributes());
    }

    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    public function key(): ?PriceKey
    {
        return PriceKey::tryFrom("{$this->product_type}.{$this->tier}.{$this->interval}.{$this->market}");
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function draftAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PriceDefinitionVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PriceDefinitionVersion::class, 'current_version_id');
    }
}
