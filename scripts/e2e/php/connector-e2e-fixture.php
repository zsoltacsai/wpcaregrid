<?php

declare(strict_types=1);

use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Models\Connector;
use App\Models\ConnectorPairingCode;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInventory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$repo = dirname(__DIR__, 3);
$statePath = $repo.'/storage/e2e/connector-e2e-state.json';
$mode = $argv[1] ?? '';

require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;

if ($mode === 'seed') {
    if (is_array($state) && ! empty($state['website_uuid'])) {
        fwrite(STDERR, "An E2E fixture already exists; clean it before seeding another.\n");
        exit(2);
    }

    $tag = Str::uuid()->toString();
    $customer = Customer::factory()->withUser()->create([
        'email' => 'e2e+'.str_replace('-', '', $tag).'@example.invalid',
    ]);
    $subscription = Subscription::factory()->for($customer)->create([
        'stripe_customer_id' => 'cus_e2e_'.str_replace('-', '', $tag),
        'stripe_subscription_id' => 'sub_e2e_'.str_replace('-', '', $tag),
    ]);
    $website = Website::factory()->for($customer)->create([
        'subscription_id' => $subscription->id,
        'url' => 'http://127.0.0.1:8318',
    ]);
    $code = app(PairingCodes::class)->issue($website, $customer->user);
    $codeRow = $website->pairingCodes()->latest('id')->firstOrFail();

    $state = [
        'run_id' => $tag,
        'customer_id' => $customer->id,
        'user_id' => $customer->user_id,
        'subscription_id' => $subscription->id,
        'website_id' => $website->id,
        'website_uuid' => $website->uuid,
        'pairing_code' => $code,
        'pairing_code_id' => $codeRow->id,
        'credential_hash' => null,
    ];
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($statePath, 0600);
    fwrite(STDOUT, "Synthetic fixture created; pairing code retained only in ignored E2E state.\n");
    exit(0);
}

if (! is_array($state) || empty($state['website_id']) || empty($state['run_id'])) {
    fwrite(STDERR, "No marked E2E fixture exists.\n");
    exit(2);
}

if ($mode === 'issue') {
    $website = Website::query()->findOrFail((int) $state['website_id']);
    $code = app(PairingCodes::class)->issue($website, User::query()->find((int) $state['user_id']));
    $codeRow = $website->pairingCodes()->latest('id')->firstOrFail();
    $state['pairing_code'] = $code;
    $state['pairing_code_id'] = $codeRow->id;
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    fwrite(STDOUT, "Fresh one-time code issued into ignored E2E state.\n");
    exit(0);
}

if ($mode === 'expire') {
    $code = ConnectorPairingCode::query()->findOrFail((int) $state['pairing_code_id']);
    $code->update(['expires_at' => now()->subMinute()]);
    fwrite(STDOUT, "Current E2E pairing code marked expired.\n");
    exit(0);
}

if ($mode === 'inspect') {
    $website = Website::query()->findOrFail((int) $state['website_id']);
    $connector = $website->connectors()->latest('id')->first();
    $inventory = $website->inventory;
    $rawCredentialAbsent = true;
    if ($connector && $state['credential_hash']) {
        $rawCredentialAbsent = $connector->credential_hash === $state['credential_hash']
            && ! array_key_exists('credential_hash', $connector->toArray());
    }

    $report = [
        'connector_count' => $website->connectors()->count(),
        'active_connector_count' => $website->connectors()->where('status', Connector::ACTIVE)->count(),
        'connector_hash_matches_plugin' => $rawCredentialAbsent && (bool) $state['credential_hash'],
        'pairing_code_used' => (bool) $website->pairingCodes()->whereNotNull('used_at')->count(),
        'website_connected' => $website->refresh()->connector_status?->value === 'connected',
        'heartbeat_recorded' => (bool) $connector?->last_heartbeat_at,
        'connector_version' => $connector?->connector_version,
        'connector_last_heartbeat_at' => $connector?->last_heartbeat_at?->toIso8601String(),
        'connector_last_inventory_at' => $connector?->last_inventory_at?->toIso8601String(),
        'website_last_seen_at' => $website->last_seen_at?->toIso8601String(),
        'wordpress_version' => $website->wordpress_version,
        'php_version' => $website->php_version,
        'health_status' => $website->health_status?->value,
        'health_checked_at' => $website->health_checked_at?->toIso8601String(),
        'health_findings_count' => count($website->health_findings ?? []),
        'inventory_row_count' => WebsiteInventory::query()->where('website_id', $website->id)->count(),
        'inventory_id' => $inventory?->id,
        'inventory_collected_at' => $inventory?->collected_at?->toIso8601String(),
        'inventory_core' => $inventory?->core,
        'inventory_php_version' => $inventory?->php_version,
        'inventory_updates_pending' => $inventory?->updates_pending,
        'inventory_plugins' => collect($inventory?->plugins ?? [])->filter(fn (array $item) => str_starts_with($item['file'] ?? '', 'e2e-'))->map(fn (array $item) => [
            'file' => $item['file'] ?? null,
            'version' => $item['version'] ?? null,
            'active' => $item['active'] ?? null,
            'update_available' => $item['update_available'] ?? null,
            'new_version' => $item['new_version'] ?? null,
            'update_source' => $item['update_source'] ?? null,
        ])->values()->all(),
        'inventory_themes' => collect($inventory?->themes ?? [])->filter(fn (array $item) => str_starts_with($item['stylesheet'] ?? '', 'e2e-'))->map(fn (array $item) => [
            'stylesheet' => $item['stylesheet'] ?? null,
            'version' => $item['version'] ?? null,
            'active' => $item['active'] ?? null,
            'update_available' => $item['update_available'] ?? null,
            'new_version' => $item['new_version'] ?? null,
        ])->values()->all(),
        'jobs' => $website->jobs()->orderBy('id')->get(['type', 'status', 'result_code'])->map(fn ($job) => [
            'type' => $job->type->value,
            'status' => $job->status->value,
            'result_code' => $job->result_code,
        ])->all(),
    ];
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($mode === 'queue-inventory') {
    $website = Website::query()->findOrFail((int) $state['website_id']);
    $job = app(JobQueue::class)->queue($website, JobType::GetInventory, [], 'e2e');
    fwrite(STDOUT, json_encode(['queued' => true, 'job_uuid' => $job->uuid], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($mode === 'cleanup') {
    DB::transaction(function () use ($state): void {
        $websiteId = (int) $state['website_id'];
        DB::table('website_events')->where('website_id', $websiteId)->delete();
        DB::table('connector_pairing_codes')->where('website_id', $websiteId)->delete();
        DB::table('website_jobs')->where('website_id', $websiteId)->delete();
        DB::table('website_inventories')->where('website_id', $websiteId)->delete();
        DB::table('connectors')->where('website_id', $websiteId)->delete();
        DB::table('websites')->where('id', $websiteId)->delete();
        DB::table('subscriptions')->where('id', (int) $state['subscription_id'])->delete();
        DB::table('customers')->where('id', (int) $state['customer_id'])->delete();
        DB::table('users')->where('id', (int) $state['user_id'])->delete();
    });
    @unlink($statePath);
    fwrite(STDOUT, "Only the tagged synthetic E2E fixture rows were removed.\n");
    exit(0);
}

fwrite(STDERR, "Supported modes: seed, issue, expire, inspect, queue-inventory, cleanup.\n");
exit(2);
