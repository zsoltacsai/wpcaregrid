<?php

namespace App\Connector;

use App\Models\Website;
use App\Websites\WebsiteStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Stores a health report: the connector's overall status and every finding
 * behind it, so the platform can make its own judgement later.
 */
final class HealthIngest
{
    /** @return array<string, mixed> */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}status" => ['required', Rule::enum(HealthStatus::class)],
            "{$prefix}findings" => ['present', 'array', 'max:50'],
            "{$prefix}findings.*.id" => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.-]+$/'],
            "{$prefix}findings.*.status" => ['required', Rule::in(['pass', 'warning', 'fail', 'unknown'])],
            "{$prefix}findings.*.label" => ['nullable', 'string', 'max:191'],
            "{$prefix}findings.*.message" => ['nullable', 'string', 'max:500'],
            "{$prefix}checked_at" => ['nullable', 'date'],
        ];
    }

    /** @param  array<string, mixed>  $health */
    public function store(Website $website, array $health): void
    {
        $data = Validator::make($health, self::rules())->validate();
        $status = HealthStatus::from($data['status']);

        $website->update([
            'health_status' => $status,
            'health_findings' => $data['findings'],
            'health_checked_at' => now(),
            'status' => match ($status) {
                HealthStatus::Critical => WebsiteStatus::Critical,
                HealthStatus::Attention => WebsiteStatus::Attention,
                HealthStatus::Healthy, HealthStatus::Unknown => WebsiteStatus::Connected,
            },
        ]);
    }
}
