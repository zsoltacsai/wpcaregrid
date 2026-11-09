<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A previewed (dry-run) Stripe catalogue plan and, once approved, the record of what was done. */
class StripeCatalogueOperation extends Model
{
    use HasUlids;

    public const PREVIEWED = 'previewed';

    public const APPLYING = 'applying';

    public const COMPLETED = 'completed';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const STALE = 'stale';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'approved_items' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::PARTIAL, self::FAILED], true);
    }
}
