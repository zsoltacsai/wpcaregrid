<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStripeWebhookInboxJob;
use App\Models\StripeWebhookInbox;
use Illuminate\Console\Command;

class DrainStripeWebhookInbox extends Command
{
    protected $signature = 'stripe:webhooks:drain {--limit=100}';

    protected $description = 'Queue bounded retries for durable Stripe webhook receipts';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $cutoff = now()->subMinute();

        $ids = StripeWebhookInbox::query()
            ->where(function ($query) {
                $query->whereIn('status', [StripeWebhookInbox::STATUS_RECEIVED, StripeWebhookInbox::STATUS_FAILED])
                    ->orWhere(function ($query) {
                        $query->where('status', StripeWebhookInbox::STATUS_PROCESSING)
                            ->where('lease_expires_at', '<', now());
                    });
            })
            ->where('attempts', '<', 10)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessStripeWebhookInboxJob::dispatch((int) $id);
        }

        $this->info('Queued '.$ids->count().' Stripe webhook receipt retries.');

        return self::SUCCESS;
    }
}
