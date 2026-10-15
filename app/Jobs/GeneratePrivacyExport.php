<?php

namespace App\Jobs;

use App\Privacy\PrivacyExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GeneratePrivacyExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $artifactUuid) {}

    public function uniqueId(): string
    {
        return $this->artifactUuid;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('privacy-export-'.$this->artifactUuid))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(PrivacyExportService $exports): void
    {
        $exports->generate($this->artifactUuid);
    }

    public function failed(Throwable $exception): void
    {
        app(PrivacyExportService::class)->markFailed($this->artifactUuid);
    }
}
