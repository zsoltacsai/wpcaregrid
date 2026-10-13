<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionOperation extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'request_snapshot' => 'array',
            'lease_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
