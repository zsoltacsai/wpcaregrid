<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Models\User;
use App\Content\HtmlSanitizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);

$refuse = static function (): never {
    fwrite(STDERR, "CANCELLATION_FIXTURE=REJECTED\n");
    exit(2);
};

$requiredEnvironment = [
    'GITHUB_ACTIONS' => 'true',
    'M16_CANCELLATION_E2E' => '1',
    'APP_ENV' => 'testing',
    'CAREGRID_DEPLOYMENT_ENV' => 'testing',
    'APP_URL' => 'http://127.0.0.1:8000',
    'DB_CONNECTION' => 'sqlite',
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
        $refuse();
    }
}

$databasePath = getenv('DB_DATABASE');
$fixturePath = getenv('M16_CANCELLATION_FIXTURES_PATH');
$baseUrl = getenv('E2E_BASE_URL');
$mode = $argv[1] ?? 'seed';
if (! is_string($databasePath) || ! is_string($fixturePath) || ! is_string($baseUrl)) {
    $refuse();
}
if (! in_array($mode, ['seed', 'verify-read-only'], true)) {
    $refuse();
}

$rootRealPath = realpath($root);
$databaseRealPath = realpath($databasePath);
$expectedDatabase = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'marketing-browser.sqlite';
$fixtureDirectory = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing';
$fixtureParent = realpath(dirname($fixturePath));
$parsedUrl = parse_url($baseUrl);

if (
    PHP_SAPI !== 'cli'
    || ! $rootRealPath
    || ! $databaseRealPath
    || is_link($databasePath)
    || $databaseRealPath !== realpath($expectedDatabase)
    || ! is_file($databaseRealPath)
    || ! is_writable($databaseRealPath)
    || ! is_array($parsedUrl)
    || ($parsedUrl['scheme'] ?? null) !== 'http'
    || ($parsedUrl['host'] ?? null) !== '127.0.0.1'
    || ($parsedUrl['port'] ?? null) !== 8000
    || $fixturePath !== $fixtureDirectory.DIRECTORY_SEPARATOR.'subscription-cancellation.json'
    || $fixtureParent !== realpath($fixtureDirectory)
    || is_link($fixturePath)
    || ($mode === 'seed' && file_exists($fixturePath))
    || ($mode === 'verify-read-only' && (! is_file($fixturePath) || ! is_readable($fixturePath)))
) {
    $refuse();
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$stateFingerprint = static fn (string $model): string => hash('sha256', json_encode(
    $model::query()->orderBy('id')->get()->map(static fn ($record): array => $record->getRawOriginal())->all(),
    JSON_THROW_ON_ERROR,
));

if (
    app()->environment() !== 'testing'
    || config('app.deployment_environment') !== 'testing'
    || config('marketing_release.enforcement_mode') !== 'testing'
    || config('marketing_release.test_app_environment') !== 'testing'
    || config('marketing_release.test_deployment_environment') !== 'testing'
    || config('database.default') !== 'sqlite'
    || realpath((string) config('database.connections.sqlite.database')) !== $databaseRealPath
    || config('mail.default') !== 'array'
    || config('queue.default') !== 'sync'
    || (string) config('services.stripe.secret') !== ''
    || (string) config('services.stripe.webhook_secret') !== ''
    || (string) config('services.stripe.key') !== ''
) {
    $refuse();
}

if ($mode === 'verify-read-only') {
    $fixture = json_decode((string) file_get_contents($fixturePath), true);
    if (! is_array($fixture)
        || ! is_int($fixture['seeded_operation_count'] ?? null)
        || ! is_int($fixture['seeded_subscription_count'] ?? null)
        || ! is_int($fixture['seeded_legal_acceptance_count'] ?? null)
        || ! is_int($fixture['seeded_legal_document_count'] ?? null)
        || ! is_string($fixture['operation_state_sha256'] ?? null)
        || ! is_string($fixture['subscription_state_sha256'] ?? null)
        || ! is_string($fixture['legal_acceptance_state_sha256'] ?? null)
        || ! is_string($fixture['legal_document_state_sha256'] ?? null)
        || SubscriptionOperation::query()->count() !== $fixture['seeded_operation_count']
        || Subscription::query()->count() !== $fixture['seeded_subscription_count']
        || LegalDocumentAcceptance::query()->count() !== $fixture['seeded_legal_acceptance_count']
        || LegalDocumentVersion::query()->count() !== $fixture['seeded_legal_document_count']
        || ! hash_equals($fixture['operation_state_sha256'], $stateFingerprint(SubscriptionOperation::class))
        || ! hash_equals($fixture['subscription_state_sha256'], $stateFingerprint(Subscription::class))
        || ! hash_equals($fixture['legal_acceptance_state_sha256'], $stateFingerprint(LegalDocumentAcceptance::class))
        || ! hash_equals($fixture['legal_document_state_sha256'], $stateFingerprint(LegalDocumentVersion::class))) {
        fwrite(STDERR, "CANCELLATION_GET_SIDE_EFFECTS=FAIL\n");
        exit(1);
    }

    echo "CANCELLATION_GET_SIDE_EFFECTS=PASS\n";
    exit(0);
}

if (! is_dir($fixtureDirectory) && ! mkdir($fixtureDirectory, 0700, true) && ! is_dir($fixtureDirectory)) {
    $refuse();
}
if (is_link($fixtureDirectory) || realpath($fixtureDirectory) !== $fixtureDirectory) {
    $refuse();
}

$password = 'Synthetic-Cancel-Only-2026!';
$customers = [];

$createCustomer = static function (string $suffix, string $locale) use (&$customers, $password): Customer {
    $email = 'm16-cancellation-'.$suffix.'@caregrid.test';
    if (Customer::query()->where('email', $email)->exists()) {
        throw new RuntimeException('fixture identity already exists');
    }

    $customer = Customer::factory()->create([
        'email' => $email,
        'first_name' => 'Synthetic',
        'last_name' => 'Cancellation QA',
    ]);
    $user = (new User)->forceFill([
        'name' => 'Synthetic Cancellation QA',
        'email' => $email,
        'email_verified_at' => now(),
        'password' => $password,
        'locale' => $locale,
    ]);
    $user->save();
    $customer->user()->associate($user)->save();
    $customer->setRelation('user', $user);
    $customers[$suffix] = ['email' => $email, 'locale' => $locale];

    return $customer;
};

$createTermsVersion = static function (string $locale): LegalDocumentVersion {
    $body = '<p>Synthetic terms used only by the isolated cancellation browser test.</p>';
    return LegalDocumentVersion::query()->create([
        'document_type' => 'terms_of_service',
        'version' => 90000001,
        'locale' => $locale,
        'title' => 'Synthetic cancellation E2E terms',
        'body' => $body,
        'content_sha256' => hash('sha256', HtmlSanitizer::clean($body)),
        'status' => 'published',
        'acceptance_mode' => 'acceptance_required_before_account_use',
        'effective_at' => now('UTC')->subMinute(),
        'published_at' => now('UTC')->subMinute(),
    ]);
};

$createSubscription = static function (Customer $customer, bool $cancelAtPeriodEnd = false): Subscription {
    return Subscription::factory()->for($customer)->create([
        'billing_source' => 'stripe',
        'cancel_at_period_end' => $cancelAtPeriodEnd,
    ]);
};

$createOperation = static function (
    Customer $customer,
    Subscription $subscription,
    string $operation,
    string $status,
    bool $targetCancellation,
    bool $confirmed = false,
): SubscriptionOperation {
    return SubscriptionOperation::query()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $customer->id,
        'operation' => $operation,
        'operation_key' => 'm16-cancel-e2e-'.Str::uuid(),
        'status' => $status,
        'request_snapshot' => ['cancel_at_period_end' => $targetCancellation],
        'confirmed_at' => $confirmed ? now() : null,
    ]);
};

try {
    $english = $createCustomer('en', 'en');
    $hungarian = $createCustomer('hu', 'hu');
    $foreign = $createCustomer('foreign', 'en');

    // The account-use legal gate is part of the real customer journey. Seed
    // isolated, synthetic EN and HU versions and accept both for each test
    // user so locale negotiation cannot divert the browser away from accounts.
    $terms = collect(['en', 'hu'])->mapWithKeys(
        fn (string $locale): array => [$locale => $createTermsVersion($locale)],
    );
    foreach ([$english, $hungarian, $foreign] as $customer) {
        foreach ($terms as $locale => $document) {
            LegalDocumentAcceptance::query()->create([
                'user_id' => $customer->user->id,
                'customer_id' => $customer->id,
                'legal_document_version_id' => $document->id,
                'document_type' => $document->document_type,
                'locale' => $locale,
                'content_sha256' => $document->content_sha256,
                'source' => 'customer_legal_area',
                'accepted_at' => now('UTC'),
            ]);
        }
    }

    $subscriptions = [];

    $subscriptions['pending_cancellation'] = $createSubscription($english);
    $createOperation($english, $subscriptions['pending_cancellation'], 'cancel_at_period_end', 'awaiting_confirmation', true);

    $subscriptions['confirmed_cancellation'] = $createSubscription($english, true);
    $createOperation($english, $subscriptions['confirmed_cancellation'], 'cancel_at_period_end', 'confirmed', true, true);

    $subscriptions['pending_reactivation'] = $createSubscription($english, true);
    $createOperation($english, $subscriptions['pending_reactivation'], 'cancel_at_period_end', 'confirmed', true, true);
    $createOperation($english, $subscriptions['pending_reactivation'], 'reactivate', 'awaiting_confirmation', false);

    $subscriptions['confirmed_reactivation'] = $createSubscription($hungarian, false);
    $createOperation($hungarian, $subscriptions['confirmed_reactivation'], 'cancel_at_period_end', 'confirmed', true, true);
    $createOperation($hungarian, $subscriptions['confirmed_reactivation'], 'reactivate', 'confirmed', false, true);

    $subscriptions['reconciliation_required'] = $createSubscription($english, true);
    $createOperation($english, $subscriptions['reconciliation_required'], 'reactivate', 'reconciliation_required', false);

    $subscriptions['stale_provider_mirror'] = $createSubscription($english, true);
    $createOperation($english, $subscriptions['stale_provider_mirror'], 'reactivate', 'confirmed', false, true);

    $subscriptions['foreign_tenant'] = $createSubscription($foreign);
    $createOperation($foreign, $subscriptions['foreign_tenant'], 'cancel_at_period_end', 'awaiting_confirmation', true);

    $subscriptionIds = [];
    foreach ($subscriptions as $key => $subscription) {
        $subscriptionIds[$key] = $subscription->uuid;
    }

    $payload = [
        'base_url' => $baseUrl,
        'password' => $password,
        'customers' => $customers,
        'subscriptions' => $subscriptionIds,
        'seeded_operation_count' => SubscriptionOperation::query()->count(),
        'seeded_subscription_count' => Subscription::query()->count(),
        'seeded_legal_acceptance_count' => LegalDocumentAcceptance::query()->count(),
        'seeded_legal_document_count' => LegalDocumentVersion::query()->count(),
        'operation_state_sha256' => $stateFingerprint(SubscriptionOperation::class),
        'subscription_state_sha256' => $stateFingerprint(Subscription::class),
        'legal_acceptance_state_sha256' => $stateFingerprint(LegalDocumentAcceptance::class),
        'legal_document_state_sha256' => $stateFingerprint(LegalDocumentVersion::class),
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (
        file_put_contents($fixturePath, $json, LOCK_EX) !== strlen($json)
        || ! chmod($fixturePath, 0600)
        || (fileperms($fixturePath) & 0777) !== 0600
    ) {
        @unlink($fixturePath);
        $refuse();
    }
} catch (Throwable) {
    $refuse();
}

echo "CANCELLATION_FIXTURE=READY\n";
