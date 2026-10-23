<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable authorization evidence for one exact M14 Integrity scan. */
class M14SecurityScanIsolation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'window_opened_at' => 'datetime',
            'authorized_at' => 'datetime',
        ];
    }
}
