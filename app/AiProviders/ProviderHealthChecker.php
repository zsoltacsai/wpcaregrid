<?php

namespace App\AiProviders;

use App\Owner\IntegrationCredentials;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Configuration-only health probe. There is deliberately no input for a payload, model or URL. */
final class ProviderHealthChecker
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly IntegrationCredentials $credentials,
        private readonly ProviderCredentialState $state,
        private readonly ProviderHealthTransport $transport,
    ) {}

    public function check(string $providerId): ProviderHealthResult
    {
        $provider = $this->registry->get($providerId);
        $credentialId = $provider['credential_id'];
        $generation = $this->state->generation($providerId);
        if (! DB::table('integration_credentials')->where('id', $credentialId)->exists()) {
            return new ProviderHealthResult($providerId, ProviderHealthStatus::NotConfigured, null, $generation, false);
        }
        if ($generation === null) {
            return new ProviderHealthResult($providerId, ProviderHealthStatus::Unknown, 'GENERATION_UNAVAILABLE', null, false);
        }

        try {
            $credential = $this->credentials->resolve($credentialId);
        } catch (Throwable) {
            return $this->finish($providerId, $generation, ProviderHealthStatus::Error, 'CREDENTIAL_UNAVAILABLE', null);
        }
        if (! is_string($credential) || $credential === '') {
            return $this->finish($providerId, $generation, ProviderHealthStatus::Unknown, 'CREDENTIAL_UNAVAILABLE', null);
        }

        try {
            $response = $this->transport->get($providerId, $credential);
            [$status, $errorCategory] = $this->classify($providerId, $response->statusCode, $response->body);

            return $this->finish($providerId, $generation, $status, $errorCategory, $response->latencyMs);
        } catch (ProviderTransportException $exception) {
            return $this->finish($providerId, $generation, $exception->healthStatus, $exception->errorCategory, null);
        } catch (Throwable) {
            return $this->finish($providerId, $generation, ProviderHealthStatus::Error, 'PROBE_FAILED', null);
        }
    }

    /** @return array{ProviderHealthStatus, ?string} */
    private function classify(string $providerId, int $statusCode, string $body): array
    {
        if ($statusCode === 401) {
            return [ProviderHealthStatus::AuthFailed, 'AUTH_REJECTED'];
        }
        if ($statusCode === 429) {
            return [ProviderHealthStatus::RateLimited, 'RATE_LIMITED'];
        }
        if ($statusCode >= 500) {
            return [ProviderHealthStatus::Unavailable, 'PROVIDER_UNAVAILABLE'];
        }
        if ($statusCode >= 300 && $statusCode < 400) {
            return [ProviderHealthStatus::Misconfigured, 'REDIRECT_REJECTED'];
        }
        if ($statusCode === 403 || $statusCode === 400) {
            return [ProviderHealthStatus::Misconfigured, 'PERMISSION_OR_CONFIGURATION'];
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return [ProviderHealthStatus::Error, 'UNEXPECTED_STATUS'];
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [ProviderHealthStatus::Error, 'UNEXPECTED_RESPONSE'];
        }

        $collection = match ($providerId) {
            'google_gemini' => 'models',
            'openai', 'anthropic' => 'data',
            default => null,
        };
        if (! is_array($payload) || $collection === null || ! array_key_exists($collection, $payload) || ! is_array($payload[$collection])) {
            return [ProviderHealthStatus::Error, 'UNEXPECTED_RESPONSE'];
        }

        return [ProviderHealthStatus::Healthy, null];
    }

    private function finish(string $providerId, string $generation, ProviderHealthStatus $status, ?string $errorCategory, ?int $latencyMs): ProviderHealthResult
    {
        $persisted = $this->state->recordHealth($providerId, $generation, $status, $errorCategory, $latencyMs);

        return new ProviderHealthResult(
            $providerId,
            $persisted ? $status : ProviderHealthStatus::Unknown,
            $persisted ? $errorCategory : 'STALE_GENERATION',
            $generation,
            $persisted,
        );
    }
}
