<?php

namespace App\Console\Commands\Monitoring;

use App\Mail\MonitoringIncidentMail;
use App\Models\MonitoringAlertDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class DeliverMonitoringAlerts extends Command
{
    protected $signature = 'monitoring:deliver-alerts';

    protected $description = 'Deliver the bounded Owner monitoring alert outbox';

    public function handle(): int
    {
        // SMTP is not an idempotent transaction. If a worker dies while sending,
        // do not blindly retry because the provider may already have accepted it.
        MonitoringAlertDelivery::query()->where('channel', 'email')->where('status', 'sending')
            ->where('last_attempt_at', '<=', now()->subMinutes(10))
            ->update(['status' => 'failed', 'failure_code' => 'delivery_interrupted', 'updated_at' => now()]);

        MonitoringAlertDelivery::query()->where('channel', 'email')->where('status', 'pending')
            ->orderBy('id')->limit(100)->pluck('id')->each(function (int $id): void {
                $claimed = MonitoringAlertDelivery::query()->whereKey($id)->where('status', 'pending')->update([
                    'status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => now(), 'updated_at' => now(),
                ]);
                if ($claimed !== 1) {
                    return;
                }
                $delivery = MonitoringAlertDelivery::query()->with(['incident', 'recipient'])->find($id);
                try {
                    if (! $delivery || ! $delivery->recipient?->email_verified_at) {
                        MonitoringAlertDelivery::query()->whereKey($id)->update(['status' => 'failed', 'failure_code' => 'recipient_unavailable', 'updated_at' => now()]);

                        return;
                    }
                    Mail::to($delivery->recipient->email)->send(new MonitoringIncidentMail($delivery));
                    MonitoringAlertDelivery::query()->whereKey($id)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'failure_code' => null, 'updated_at' => now()]);
                } catch (\Throwable) {
                    $attempts = (int) MonitoringAlertDelivery::query()->whereKey($id)->value('attempts');
                    MonitoringAlertDelivery::query()->whereKey($id)->where('status', 'sending')->update([
                        'status' => $attempts >= 3 ? 'failed' : 'pending', 'failure_code' => 'mail_transport_failed', 'updated_at' => now(),
                    ]);
                }
            });

        return self::SUCCESS;
    }
}
