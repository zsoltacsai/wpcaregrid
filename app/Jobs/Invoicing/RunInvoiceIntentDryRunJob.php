<?php

namespace App\Jobs\Invoicing;

use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIntentWorkflow;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\InvoiceProvider;
use App\Models\InvoiceIntent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunInvoiceIntentDryRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $invoiceIntentId, public readonly NormalizedInvoiceRequest $request)
    {
        $this->tries = max(1, min(5, (int) config('szamlazz.retry_limit', 3)));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(InvoiceProvider $provider, InvoiceIntentWorkflow $workflow): void
    {
        $intent = InvoiceIntent::query()->find($this->invoiceIntentId);
        if (! $intent || ! in_array($intent->status, [InvoiceIntentStatus::ReadyForDryRun, InvoiceIntentStatus::DryRunValidating], true)) {
            return;
        }

        if (! config('szamlazz.provider_enabled') || ! config('szamlazz.dry_run') || config('szamlazz.issuance_enabled')) {
            $workflow->preventProcessing($intent->id, 'provider_configuration_not_dry_run_only');

            return;
        }

        if (! $workflow->startDryRun($intent->id)) {
            return;
        }

        $result = $provider->validate($this->request);
        $workflow->recordDryRunResult($intent->id, ['errors' => $result->errors], $result->valid);
    }

    public function failed(?\Throwable $exception): void
    {
        app(InvoiceIntentWorkflow::class)->markDryRunFailed($this->invoiceIntentId);
        Log::error('Invoice intent local dry-run exhausted its bounded retries.', ['invoice_intent_id' => $this->invoiceIntentId, 'error_category' => 'local_dry_run_failed']);
    }
}
