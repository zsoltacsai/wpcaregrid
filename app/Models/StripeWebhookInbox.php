<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookInbox extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'stripe_webhook_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'livemode' => 'boolean',
            'attempts' => 'integer',
            'stripe_created_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
