<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only snapshot of a price publication. */
class PriceDefinitionVersion extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published price versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Published price versions are immutable.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime'];
    }
}
