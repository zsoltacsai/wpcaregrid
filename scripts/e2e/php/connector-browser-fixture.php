<?php

declare(strict_types=1);

use App\Billing\SubscriptionStatus;
use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Connector\Presence;
use App\Models\Connector;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$repo = dirname(__DIR__, 3);
$statePath = $repo.'/storage/e2e/connector-browser-state.json';
$mode = $argv[1] ?? '';

require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;

if ($mode === 'browser-seed') {
    if (is_array($state)) {
        fwrite(STDERR, "A browser E2E fixture already exists; clean it before seeding another.\n");
        exit(2);
    }

    $runId = strtolower(Str::random(16));
    $accounts = [];
    foreach (['a', 'b'] as $suffix) {
        $email = "browser-e2e+{$runId}-{$suffix}@example.invalid";
        $password = 'E2e-'.Str::random(32);
        $customer = Customer::factory()->create([
            'email' => $email,
            'first_name' => 'E2E',
            'last_name' => strtoupper($suffix),
        ]);
        $user = User::factory()->create([
            'name' => 'E2E '.strtoupper($suffix),
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $customer->user()->associate($user)->save();
        $subscription = Subscription::factory()->for($customer)->create([
            'stripe_customer_id' => 'cus_e2e_'.$runId.'_'.$suffix,
            'stripe_subscription_id' => 'sub_e2e_'.$runId.'_'.$suffix,
            'status' => SubscriptionStatus::Active,
        ]);

        $accounts[$suffix] = [
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'email' => $email,
            'password' => $password,
            'website_url' => "https://e2e-{$runId}-{$suffix}.example.com",
        ];
    }

    $state = ['run_id' => $runId, 'accounts' => $accounts, 'websites' => []];
    if (! is_dir(dirname($statePath))) {
        mkdir(dirname($statePath), 0700, true);
    }
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($statePath, 0600);
    fwrite(STDOUT, "Created two synthetic browser-only customer accounts.\n");
    exit(0);
}

if (! is_array($state) || empty($state['run_id']) || ! is_array($state['accounts'] ?? null)) {
    fwrite(STDERR, "No marked browser E2E fixture exists.\n");
    exit(2);
}

if ($mode === 'browser-record-website') {
    $websiteUuid = (string) ($argv[2] ?? '');
    $accountKey = (string) ($argv[3] ?? '');
    $account = $state['accounts'][$accountKey] ?? null;
    $website = Website::query()->where('uuid', $websiteUuid)->first();
    if (! is_array($account) || ! $website || (int) $website->customer_id !== (int) $account['customer_id']
        || $website->url !== $account['website_url']) {
        fwrite(STDERR, "The browser-created website did not match its synthetic account fixture.\n");
        exit(3);
    }
    $state['websites'][$accountKey] = $websiteUuid;
    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    fwrite(STDOUT, "Recorded the browser-created synthetic website.\n");
    exit(0);
}

if ($mode === 'browser-expire-code') {
    $websiteUuid = (string) ($argv[2] ?? '');
    $website = Website::query()->where('uuid', $websiteUuid)->first();
    if (! $website || ! in_array((int) $website->customer_id, array_map(fn (array $item) => (int) $item['customer_id'], $state['accounts']), true)) {
        fwrite(STDERR, "The website is outside the synthetic browser fixture.\n");
        exit(3);
    }
    $code = $website->pairingCodes()->whereNull('used_at')->whereNull('invalidated_at')->latest('id')->first();
    if (! $code) {
        fwrite(STDERR, "No unused pairing code exists for the synthetic website.\n");
        exit(4);
    }
    $code->update(['expires_at' => now()->subMinute()]);
    fwrite(STDOUT, "Expired the latest unused code for the marked synthetic website.\n");
    exit(0);
}

if ($mode === 'browser-age-connection') {
    $websiteUuid = (string) ($argv[2] ?? '');
    $website = Website::query()->where('uuid', $websiteUuid)->first();
    if (! $website || ! in_array((int) $website->customer_id, array_map(fn (array $item) => (int) $item['customer_id'], $state['accounts']), true)) {
        fwrite(STDERR, "The website is outside the synthetic browser fixture.\n");
        exit(3);
    }
    $connector = $website->connectors()->where('status', Connector::ACTIVE)->latest('id')->first();
    if (! $connector) {
        fwrite(STDERR, "The synthetic website has no active connector to age.\n");
        exit(4);
    }
    $staleAt = now()->subSeconds((int) config('connector.stale_after') + 5);
    $connector->update(['last_seen_at' => $staleAt]);
    $website->update(['last_seen_at' => $staleAt]);
    if ($connector->refresh()->presence() !== Presence::Stale) {
        fwrite(STDERR, "The connector did not enter the expected stale state.\n");
        exit(5);
    }
    fwrite(STDOUT, "Aged only the marked synthetic connector heartbeat to exercise stale UI.\n");
    exit(0);
}

if ($mode === 'browser-queue-health-inventory') {
    $websiteUuid = (string) ($argv[2] ?? '');
    $website = Website::query()->where('uuid', $websiteUuid)->first();
    if (! $website || ! in_array((int) $website->customer_id, array_map(fn (array $item) => (int) $item['customer_id'], $state['accounts']), true)) {
        fwrite(STDERR, "The website is outside the synthetic browser fixture.\n");
        exit(3);
    }
    $queue = app(JobQueue::class);
    $queue->queue($website, JobType::GetInventory, [], 'browser-e2e');
    $queue->queue($website, JobType::SiteHealth, [], 'browser-e2e');
    fwrite(STDOUT, "Queued safe inventory and health jobs for the marked synthetic website.\n");
    exit(0);
}

if ($mode === 'browser-inspect') {
    $websiteUuid = (string) ($argv[2] ?? '');
    $website = Website::query()->where('uuid', $websiteUuid)->first();
    if (! $website || ! in_array((int) $website->customer_id, array_map(fn (array $item) => (int) $item['customer_id'], $state['accounts']), true)) {
        fwrite(STDERR, "The website is outside the synthetic browser fixture.\n");
        exit(3);
    }
    $connector = $website->connectors()->latest('id')->first();
    $report = [
        'website_uuid' => $website->uuid,
        'website_url' => $website->url,
        'connector_status' => $website->connector_status?->value,
        'connector_record_status' => $connector?->status,
        'presence' => $website->presence()->value,
        'last_seen_at' => $website->last_seen_at?->toIso8601String(),
        'last_heartbeat_at' => $connector?->last_heartbeat_at?->toIso8601String(),
        'connector_version' => $connector?->connector_version,
        'inventory_id' => $website->inventory?->id,
        'inventory_collected_at' => $website->inventory?->collected_at?->toIso8601String(),
        'health_status' => $website->health_status?->value,
        'health_checked_at' => $website->health_checked_at?->toIso8601String(),
    ];
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}

if ($mode === 'browser-cleanup') {
    DB::transaction(function () use ($state): void {
        $customerIds = array_map(fn (array $account) => (int) $account['customer_id'], $state['accounts']);
        $userIds = array_map(fn (array $account) => (int) $account['user_id'], $state['accounts']);
        $subscriptionIds = array_map(fn (array $account) => (int) $account['subscription_id'], $state['accounts']);
        $websiteIds = Website::query()->whereIn('customer_id', $customerIds)->pluck('id')->all();

        foreach (['website_events', 'connector_pairing_codes', 'website_jobs', 'website_inventories', 'connectors'] as $table) {
            DB::table($table)->whereIn('website_id', $websiteIds)->delete();
        }
        DB::table('websites')->whereIn('id', $websiteIds)->delete();
        DB::table('subscriptions')->whereIn('id', $subscriptionIds)->delete();
        DB::table('customers')->whereIn('id', $customerIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        // Every session in this dedicated E2E database belongs to this local run.
        DB::table('sessions')->delete();
    });
    @unlink($statePath);
    fwrite(STDOUT, "Removed only the two tagged browser-test accounts and their websites.\n");
    exit(0);
}

fwrite(STDERR, "Supported modes: browser-seed, browser-record-website, browser-expire-code, browser-age-connection, browser-queue-health-inventory, browser-inspect, browser-cleanup.\n");
exit(2);
