<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One run of the platform update check: states and counts only. */
class PlatformUpdateCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_results' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
