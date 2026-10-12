<?php

namespace App\Jobs\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringResult;
use App\Monitoring\MonitoringEngine;
use App\Monitoring\MonitoringObservation;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecordWorkerPulse implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 240;

    public function __construct(public readonly string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function uniqueId(): string
    {
        return 'monitor-worker-pulse:'.$this->queueName;
    }

    public function handle(MonitoringEngine $engine): void
    {
        $result = MonitoringResult::query()->where('check_id', 'platform.worker')->where('scope', MonitoringScope::Platform->value)->where('subject_type', 'queue')->where('subject_id', $this->queueName)->first();
        $engine->record(new MonitoringObservation(
            'platform.worker', MonitoringScope::Platform, 'queue', $this->queueName,
            HealthStatus::Healthy, null, MonitoringReason::MONITORING_OK, 'queue',
            ['queue' => $this->queueName, 'pulse_age_seconds' => $result ? now()->diffInSeconds($result->observed_at) : null],
            now(), 900, 2, 2,
        ));
    }
}
