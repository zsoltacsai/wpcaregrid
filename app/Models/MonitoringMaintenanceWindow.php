<?php

namespace App\Models;

use App\Monitoring\MonitoringScope;
use Illuminate\Database\Eloquent\Model;

final class MonitoringMaintenanceWindow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scope' => MonitoringScope::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
