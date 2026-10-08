<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceIntentFollowUpMarker extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
