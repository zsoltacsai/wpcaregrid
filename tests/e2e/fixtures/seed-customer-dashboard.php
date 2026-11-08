<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;

/*
 * Seeds two synthetic customers (EN and HU) with websites for the
 * authenticated Customer Dashboard browser tests. Refuses to run unless the
 * application is the isolated testing SQLite application with Stripe, mail
 * and queues disabled, so it cannot touch real data or billing services.
 */

$root = dirname(__DIR__, 3);

$refuse = static function (string $code): never {
    fwrite(STDERR, "DASHBOARD_FIXTURE=REJECTED code={$code}\n");
    exit(2);
};

$requiredEnvironment = [
    'GITHUB_ACTIONS' => 'true',
    'M16_DASHBOARD_E2E' => '1',
    'APP_ENV' => 'testing',
    'CAREGRID_DEPLOYMENT_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'SESSION_DRIVER' => 'file',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'STRIPE_KEY' => '',
    'STRIPE_SECRET' => '',
    'STRIPE_WEBHOOK_SECRET' => '',
    'STRIPE_ALLOW_LIVE_MODE' => 'false',
];

foreach ($requiredEnvironment as $name => $expected) {
    $actual = getenv($name);
    if ($actual === false || $actual !== $expected) {
        $refuse('ENVIRONMENT');
    }
}

$databasePath = getenv('DB_DATABASE');
$baseUrl = parse_url((string) getenv('E2E_BASE_URL'));
$expectedDatabase = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR
    .'testing'.DIRECTORY_SEPARATOR.'marketing-browser.sqlite';

if (
    PHP_SAPI !== 'cli'
    || ! is_string($databasePath)
    || is_link($databasePath)
    || ! is_file($databasePath)
    || realpath($databasePath) !== realpath($expectedDatabase)
    || ! is_array($baseUrl)
    || ($baseUrl['scheme'] ?? null) !== 'http'
    || ($baseUrl['host'] ?? null) !== '127.0.0.1'
) {
    $refuse('PATHS');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    app()->environment() !== 'testing'
    || config('app.deployment_environment') !== 'testing'
    || config('marketing_release.enforcement_mode') !== 'testing'
    || config('database.default') !== 'sqlite'
    || realpath((string) config('database.connections.sqlite.database')) !== realpath($expectedDatabase)
    || config('mail.default') !== 'array'
    || config('queue.default') !== 'sync'
    || (string) config('services.stripe.secret') !== ''
    || (string) config('services.stripe.webhook_secret') !== ''
    || (string) config('services.stripe.key') !== ''
) {
    $refuse('RUNTIME_CONFIG');
}

if (User::query()->where('email', 'like', 'dashboard-%@example.test')->exists()) {
    $refuse('ALREADY_SEEDED');
}

foreach (['en', 'hu'] as $locale) {
    $customer = Customer::factory()->withUser()->create(['first_name' => 'Dashboard']);
    $customer->user->forceFill(['email' => "dashboard-{$locale}@example.test", 'locale' => $locale])->save();
    Subscription::factory()->for($customer)->create();

    foreach (['connected', 'attention', 'pending'] as $index => $status) {
        Website::factory()->for($customer)->create([
            'url' => "https://dashboard-{$locale}-{$index}.example",
            'status' => $status,
        ]);
    }
}

echo "DASHBOARD_FIXTURE=SEEDED customers=2 websites=6\n";
