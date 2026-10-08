<?php

namespace App\Jobs\Invoicing;

use App\Invoicing\InvoiceIntentWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PrepareInvoiceIntentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $invoiceIntentId)
    {
        $this->tries = max(1, min(5, (int) config('szamlazz.retry_limit', 3)));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(InvoiceIntentWorkflow $workflow): void
    {
        $workflow->prepare($this->invoiceIntentId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(InvoiceIntentWorkflow::class)->markPreparationFailed($this->invoiceIntentId);
        Log::error('Invoice intent preparation exhausted its bounded retries.', ['invoice_intent_id' => $this->invoiceIntentId, 'error_category' => 'preparation_failed']);
    }
}
