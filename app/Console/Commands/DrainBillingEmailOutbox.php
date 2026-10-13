<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBillingEmailOutboxJob;
use App\Models\BillingEmailOutbox;
use Illuminate\Console\Command;

class DrainBillingEmailOutbox extends Command
{
    protected $signature = 'billing:emails:drain {--limit=100}';

    protected $description = 'Dispatch pending idempotent billing lifecycle emails';

    public function handle(): int
    {
        // A worker may have died after sending but before recording the outcome.
        // Never resend that ambiguous event automatically.
        BillingEmailOutbox::query()
            ->where('status', 'processing')
            ->where('lease_expires_at', '<', now())
            ->update([
                'status' => 'delivery_unknown',
                'failure_category' => 'WorkerLeaseExpired',
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        $limit = max(1, min(500, (int) $this->option('limit')));
        $ids = BillingEmailOutbox::query()
            ->where('status', 'pending')
            ->where('updated_at', '<=', now()->subMinute())
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessBillingEmailOutboxJob::dispatch((int) $id);
        }

        $this->info('Queued '.$ids->count().' billing email outbox records.');

        return self::SUCCESS;
    }
}
