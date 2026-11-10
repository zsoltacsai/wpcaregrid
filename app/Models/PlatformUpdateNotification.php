<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** History and de-duplication of Owner notifications about platform updates (counts only, no content). */
class PlatformUpdateNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
