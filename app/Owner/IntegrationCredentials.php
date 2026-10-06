<?php

namespace App\Owner;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Fixed, application-supported service credentials. Values are never returned to views. */
final class IntegrationCredentials
{
    /** @var array<string, array{label: string, config: list<string>, runtime_supported?: bool}> */
    public const FIELDS = [
        'stripe_publishable' => ['label' => 'Stripe publishable key', 'config' => ['services.stripe.key']],
        'stripe_secret' => ['label' => 'Stripe secret key', 'config' => ['services.stripe.secret']],
        'stripe_webhook' => ['label' => 'Stripe webhook secret', 'config' => ['services.stripe.webhook_secret']],
        'cloudflare_api_token' => ['label' => 'Cloudflare API token', 'config' => ['services.cloudflare.api_token']],
        's3_access' => ['label' => 'S3-compatible storage access key (Hetzner)', 'config' => ['backup.providers.s3.key']],
        's3_secret' => ['label' => 'S3-compatible storage secret key (Hetzner)', 'config' => ['backup.providers.s3.secret']],
        's3_sse_c' => ['label' => 'S3 SSE-C encryption key', 'config' => ['backup.providers.s3.sse_customer_key']],
        'smtp_password' => ['label' => 'SMTP password', 'config' => ['mail.mailers.smtp.password']],
        'postmark' => ['label' => 'Postmark API key', 'runtime_supported' => false, 'config' => ['services.postmark.key']],
        'resend' => ['label' => 'Resend API key', 'runtime_supported' => false, 'config' => ['services.resend.key']],
        'ses_access' => ['label' => 'Amazon SES access key', 'config' => ['services.ses.key', 'filesystems.disks.s3.key', 'queue.connections.sqs.key', 'cache.stores.dynamodb.key']],
        'ses_secret' => ['label' => 'Amazon SES secret key', 'config' => ['services.ses.secret', 'filesystems.disks.s3.secret', 'queue.connections.sqs.secret', 'cache.stores.dynamodb.secret']],
        'wordfence_feed' => ['label' => 'Wordfence vulnerability feed key', 'config' => ['security.vulnerabilities.feed_key']],
    ];

    /** @return array<string, array{owner_managed: bool, environment_fallback: bool}> */
    public function configured(): array
    {
        $saved = DB::table('integration_credentials')->pluck('id')->all();
        $saved = array_fill_keys($saved, true);
        $fallbacks = config('owner_integration_credentials.environment_fallbacks', []);

        $result = [];
        foreach (self::FIELDS as $id => $field) {
            $environmentFallback = array_key_exists($id, $fallbacks)
                ? (bool) $fallbacks[$id]
                : collect($field['config'])->contains(fn (string $path) => filled(config($path)));
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
            if (! $field) {
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
