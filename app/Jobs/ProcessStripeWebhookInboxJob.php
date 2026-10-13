<?php

namespace App\Jobs;

use App\Checkout\ProcessStripeWebhookInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStripeWebhookInboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public int $uniqueFor = 600;

    public function __construct(public readonly int $receiptId) {}

    public function uniqueId(): string
    {
        return (string) $this->receiptId;
    }

    public function handle(ProcessStripeWebhookInbox $processor): void
    {
        $processor->process($this->receiptId);
    }
}
