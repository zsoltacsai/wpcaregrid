<?php

namespace App\Owner;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Fixed, application-supported service credentials. Values are never returned to views. */
final class IntegrationCredentials
{
    /** @var array<string, array{label: string, config: list<string>, runtime_supported?: bool, runtime_resolved?: bool, environment_fallback?: bool, description_key?: string, credential_label_key?: string, save_label_key?: string, replace_label_key?: string, clear_label_key?: string, provider_id?: string}> */
    public const FIELDS = [
        'stripe_publishable' => ['label' => 'Stripe publishable key', 'config' => ['services.stripe.key']],
        'stripe_secret' => ['label' => 'Stripe secret key', 'config' => ['services.stripe.secret']],
        'stripe_webhook' => ['label' => 'Stripe webhook secret', 'config' => ['services.stripe.webhook_secret']],
        'cloudflare_api_token' => ['label' => 'Cloudflare API token', 'config' => ['services.cloudflare.api_token']],
        's3_access' => ['label' => 'S3-compatible storage access key (Hetzner)', 'config' => ['backup.providers.s3.key']],
        's3_secret' => ['label' => 'S3-compatible storage secret key (Hetzner)', 'config' => ['backup.providers.s3.secret']],
        'platform_backup_s3_access_key' => ['label' => 'Platform Backup S3 access key', 'config' => ['filesystems.disks.platform_backups.key'], 'runtime_resolved' => true, 'description_key' => 'owner.integrations.platform_backup_s3.description'],
        'platform_backup_s3_secret_key' => ['label' => 'Platform Backup S3 secret key', 'config' => ['filesystems.disks.platform_backups.secret'], 'runtime_resolved' => true],
        's3_sse_c' => ['label' => 'S3 SSE-C encryption key', 'config' => ['backup.providers.s3.sse_customer_key']],
        'smtp_password' => ['label' => 'SMTP password', 'config' => ['mail.mailers.smtp.password']],
        'postmark' => ['label' => 'Postmark API key', 'runtime_supported' => false, 'config' => ['services.postmark.key']],
        'resend' => ['label' => 'Resend API key', 'runtime_supported' => false, 'config' => ['services.resend.key']],
        // The credential reference is already used by config/szamlazz.php; provider ID stays szamlazz_hu.
        'szamlazz_agent' => [
            'label' => 'Számlázz.hu — Számla Agent',
            'runtime_supported' => false,
            'config' => ['szamlazz.agent_api_key'],
            'description_key' => 'owner.integrations.szamlazz_agent.description',
            'credential_label_key' => 'owner.integrations.szamlazz_agent.credential_label',
            'save_label_key' => 'owner.integrations.szamlazz_agent.save',
            'replace_label_key' => 'owner.integrations.szamlazz_agent.replace',
            'clear_label_key' => 'owner.integrations.szamlazz_agent.clear',
            'provider_id' => 'szamlazz_hu',
        ],
        'ses_access' => ['label' => 'Amazon SES access key', 'config' => ['services.ses.key', 'filesystems.disks.s3.key', 'queue.connections.sqs.key', 'cache.stores.dynamodb.key']],
        'ses_secret' => ['label' => 'Amazon SES secret key', 'config' => ['services.ses.secret', 'filesystems.disks.s3.secret', 'queue.connections.sqs.secret', 'cache.stores.dynamodb.secret']],
        'wordfence_feed' => ['label' => 'Wordfence vulnerability feed key', 'config' => ['security.vulnerabilities.feed_key']],
        // AI provider keys are resolved only for an explicit Owner health probe.
        // Empty config paths and the explicit no-fallback flag keep them out of
        // environment resolution and global config hydration.
        'ai_openai_api_key' => ['label' => 'OpenAI API key', 'config' => [], 'runtime_resolved' => true, 'environment_fallback' => false],
        'ai_anthropic_api_key' => ['label' => 'Anthropic API key', 'config' => [], 'runtime_resolved' => true, 'environment_fallback' => false],
        'ai_google_gemini_api_key' => ['label' => 'Google Gemini API key', 'config' => [], 'runtime_resolved' => true, 'environment_fallback' => false],
    ];

    /** @return array<string, array{owner_managed: bool, environment_fallback: bool}> */
    public function configured(): array
    {
        $saved = DB::table('integration_credentials')->pluck('id')->all();
        $saved = array_fill_keys($saved, true);
        $fallbacks = config('owner_integration_credentials.environment_fallbacks', []);

        $result = [];
        foreach (self::FIELDS as $id => $field) {
            $environmentFallback = ($field['environment_fallback'] ?? true) && (array_key_exists($id, $fallbacks)
                ? (bool) $fallbacks[$id]
                : collect($field['config'])->contains(fn (string $path) => filled(config($path))));
            $result[$id] = [
                'owner_managed' => isset($saved[$id]),
                'environment_fallback' => $environmentFallback,
            ];
        }

        return $result;
    }

    public function store(string $id, string $value): void
    {
        abort_unless(isset(self::FIELDS[$id]), 404);

        DB::table('integration_credentials')->updateOrInsert(
            ['id' => $id],
            ['value' => Crypt::encryptString($value), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function clear(string $id): void
    {
        abort_unless(isset(self::FIELDS[$id]), 404);
        DB::table('integration_credentials')->where('id', $id)->delete();
    }

    /** Resolve a fixed credential at operation time, preferring its encrypted Owner value. */
    public function resolve(string $id): ?string
    {
        abort_unless(isset(self::FIELDS[$id]), 404);

        $stored = DB::table('integration_credentials')->where('id', $id)->value('value');
        if (is_string($stored)) {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable $exception) {
                throw new \RuntimeException('A stored integration credential cannot be decrypted. Verify that APP_KEY has not changed.', previous: $exception);
            }
        }

        if (! (self::FIELDS[$id]['environment_fallback'] ?? true)) {
            return null;
        }

        foreach (self::FIELDS[$id]['config'] as $path) {
            $value = config($path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Apply stored values only to the fixed config paths; called at runtime, never when building config cache. */
    public function apply(): void
    {
        if (! is_array(config('owner_integration_credentials.environment_fallbacks'))) {
            $fallbacks = [];
            foreach (self::FIELDS as $id => $field) {
                $fallbacks[$id] = collect($field['config'])->contains(fn (string $path) => filled(config($path)));
            }
            config(['owner_integration_credentials.environment_fallbacks' => $fallbacks]);
        }

        foreach (DB::table('integration_credentials')->get(['id', 'value']) as $credential) {
            $field = self::FIELDS[$credential->id] ?? null;
            if (! $field || ($field['runtime_resolved'] ?? false)) {
                continue;
            }

            try {
                $value = Crypt::decryptString($credential->value);
            } catch (\Throwable $exception) {
                throw new \RuntimeException('A stored integration credential cannot be decrypted. Verify that APP_KEY has not changed.', previous: $exception);
            }

            foreach ($field['config'] as $path) {
                config([$path => $value]);
            }
        }
    }
}
