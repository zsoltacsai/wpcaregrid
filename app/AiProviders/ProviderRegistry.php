<?php

namespace App\AiProviders;

/** Fixed, source-owned configuration metadata. Registry order has no priority meaning. */
final class ProviderRegistry
{
    public const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            'credential_id' => 'ai_openai_api_key',
            'endpoint' => 'https://api.openai.com/v1/models',
            'auth' => 'bearer',
            'query' => [],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'credential_id' => 'ai_anthropic_api_key',
            'endpoint' => 'https://api.anthropic.com/v1/models',
            'auth' => 'x-api-key',
            'query' => ['limit' => 1],
        ],
        'google_gemini' => [
            'label' => 'Google Gemini',
            'credential_id' => 'ai_google_gemini_api_key',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'auth' => 'x-goog-api-key',
            'query' => ['pageSize' => 1],
        ],
    ];

    public function all(): array
    {
        return self::PROVIDERS;
    }

    public function get(string $providerId): array
    {
        abort_unless(isset(self::PROVIDERS[$providerId]), 404);

        return self::PROVIDERS[$providerId];
    }

    public function defaultProvider(): ?string
    {
        return null;
    }
}
