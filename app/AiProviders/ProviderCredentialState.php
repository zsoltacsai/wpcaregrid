<?php

namespace App\AiProviders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Generation-bound, non-secret state. Configured is always derived from the encrypted credential row. */
final class ProviderCredentialState
{
    public function generation(string $providerId): ?string
    {
        return DB::table('ai_provider_statuses')->where('provider_id', $providerId)->value('credential_generation');
    }

    public function rotate(string $providerId, bool $configured): string
    {
        $now = now();
        DB::table('ai_provider_statuses')->insertOrIgnore([
            'provider_id' => $providerId,
            'credential_generation' => (string) Str::uuid(),
            'health_status' => ProviderHealthStatus::Unknown->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_provider_statuses')->where('provider_id', $providerId)->lockForUpdate()->first();
        $generation = (string) Str::uuid();
        DB::table('ai_provider_statuses')->where('provider_id', $providerId)->update([
            'credential_generation' => $generation,
            'health_generation' => null,
            'health_status' => $configured ? ProviderHealthStatus::Unknown->value : ProviderHealthStatus::NotConfigured->value,
            'checked_at' => null,
            'normalized_error_category' => null,
            'latency_bucket_ms' => null,
            'updated_at' => $now,
        ]);

        return $generation;
    }

    /** Persist only if this result still belongs to the currently stored credential generation. */
    public function recordHealth(string $providerId, string $generation, ProviderHealthStatus $status, ?string $errorCategory, ?int $latencyMs): bool
    {
        $credentialId = (new ProviderRegistry)->get($providerId)['credential_id'];

        return DB::transaction(function () use ($providerId, $credentialId, $generation, $status, $errorCategory, $latencyMs): bool {
            $row = DB::table('ai_provider_statuses')->where('provider_id', $providerId)->lockForUpdate()->first();
            $credentialExists = DB::table('integration_credentials')->where('id', $credentialId)->exists();

            if (! $row || ! $credentialExists || ! hash_equals((string) $row->credential_generation, $generation)) {
                return false;
            }

            DB::table('ai_provider_statuses')->where('provider_id', $providerId)->update([
                'health_generation' => $generation,
                'health_status' => $status->value,
                'checked_at' => now(),
                'normalized_error_category' => $errorCategory,
                'latency_bucket_ms' => $latencyMs === null ? null : max(0, min(60000, (int) (ceil($latencyMs / 1000) * 1000))),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function state(string $providerId): array
    {
        $credentialId = (new ProviderRegistry)->get($providerId)['credential_id'];
        $configured = DB::table('integration_credentials')->where('id', $credentialId)->exists();
        $row = DB::table('ai_provider_statuses')->where('provider_id', $providerId)->first();
        $status = $configured
            ? ($row && $row->health_generation === $row->credential_generation && $row->checked_at !== null
                ? ProviderHealthStatus::tryFrom($row->health_status) ?? ProviderHealthStatus::Unknown
                : ProviderHealthStatus::Unknown)
            : ProviderHealthStatus::NotConfigured;

        if ($configured && $row?->checked_at && now()->parse($row->checked_at)->lt(now()->subDays(7))) {
            $status = ProviderHealthStatus::Unknown;
        }

        return [
            'configured' => $configured,
            'health_status' => $status->value,
            'checked_at' => $configured && $row?->health_generation === $row?->credential_generation ? $row?->checked_at : null,
            'generation' => $row?->credential_generation,
        ];
    }
}
