<?php

declare(strict_types=1);

use App\Legal\LegalDocumentCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__, 3);

$refuse = static function (string $reason): never {
    fwrite(STDERR, "Synthetic legal fixture refused: {$reason}\n");
    exit(1);
};

$readEnvironment = static function (string $name): ?string {
    $value = getenv($name);

    return $value === false ? null : $value;
};

$requiredEnvironment = [
    'GITHUB_ACTIONS' => 'true',
    'CAREGRID_SEED_SYNTHETIC_LEGAL_FIXTURE' => '1',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'caregrid_test',
    'DB_USERNAME' => 'caregrid_ci',
    'DB_URL' => '',
    'CAREGRID_EXPECTED_DATABASE_DRIVER' => 'mysql',
];

foreach ($requiredEnvironment as $name => $expected) {
    if ($readEnvironment($name) !== $expected) {
        $refuse('a required CI opt-in or disposable database environment value did not match');
    }
}

$password = $readEnvironment('DB_PASSWORD');
if ($password === null || $password === '') {
    $refuse('the disposable CI database credential is missing');
}
unset($password);

foreach (['APP_ENV' => 'local', 'CAREGRID_DEPLOYMENT_ENV' => 'local'] as $name => $expected) {
    $value = $readEnvironment($name);
    if ($value !== null && $value !== $expected) {
        $refuse('a process-level application environment conflicts with the CI test identity');
    }
}

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mysql = config('database.connections.mysql');
if (! is_array($mysql)) {
    $refuse('the effective MySQL connection configuration is unavailable');
}

$expectedConfiguration = [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'caregrid_test',
    'username' => 'caregrid_ci',
];

foreach ($expectedConfiguration as $key => $expected) {
    if ((string) ($mysql[$key] ?? '') !== $expected) {
        $refuse('the effective Laravel MySQL connection does not match the disposable CI identity');
    }
}

if (config('database.default') !== 'mysql'
    || in_array($mysql['url'] ?? null, [null, ''], true) === false
    || (string) ($mysql['unix_socket'] ?? '') !== ''
    || ($mysql['read'] ?? null) !== null
    || ($mysql['write'] ?? null) !== null
) {
    $refuse('the effective Laravel database connection has an unexpected route or transport');
}

if (config('app.env') !== 'local'
    || config('app.deployment_environment') !== 'local'
    || config('app.locale') !== 'en'
) {
    $refuse('the effective Laravel application identity is not the expected local English CI app');
}

$connection = DB::connection('mysql');
$pdo = $connection->getPdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    $refuse('the active PDO driver is not MySQL');
}

$databaseIdentity = $connection->selectOne(
    'SELECT DATABASE() AS database_name, @@port AS server_port, CURRENT_USER() AS authenticated_account'
);

if (($databaseIdentity->database_name ?? null) !== 'caregrid_test'
    || (int) ($databaseIdentity->server_port ?? 0) !== 3306
    || explode('@', (string) ($databaseIdentity->authenticated_account ?? ''), 2)[0] !== 'caregrid_ci'
) {
    $refuse('the connected database identity does not match the disposable CI database');
}

if (app()->getLocale() !== 'en'
    || Schema::connection('mysql')->hasTable('legal_document_versions') === false
) {
    $refuse('the English locale or migrated legal-document table is unavailable');
}

$fixtureMarker = 'CI-SYNTHETIC-LEGAL-FIXTURE-v1';
$publishedAt = '2000-01-01 00:00:00';
$documents = [
    ['type' => 'terms_of_service', 'title' => '[SYNTHETIC CI] Terms fixture - not a legal document', 'uuid' => 'cf100000-0000-4000-8000-000000000001'],
    ['type' => 'privacy_notice', 'title' => '[SYNTHETIC CI] Privacy fixture - not a legal document', 'uuid' => 'cf100000-0000-4000-8000-000000000002'],
    ['type' => 'cookie_notice', 'title' => '[SYNTHETIC CI] Cookie fixture - not a legal document', 'uuid' => 'cf100000-0000-4000-8000-000000000003'],
    ['type' => 'dpa', 'title' => '[SYNTHETIC CI] DPA fixture - not a legal document', 'uuid' => 'cf100000-0000-4000-8000-000000000004'],
];

$rows = [];
foreach ($documents as $document) {
    $body = 'SYNTHETIC CI FIXTURE ONLY. This text is not a legal document and creates no rights, duties, consent, or contractual terms.';
    $rows[] = [
        'document_type' => $document['type'],
        'version' => 999999,
        'locale' => 'en',
        'uuid' => $document['uuid'],
        'title' => $document['title'],
        'body' => $body,
        'content_sha256' => hash('sha256', $body),
        'status' => 'published',
        'acceptance_mode' => 'disabled',
        'audiences' => json_encode(['all'], JSON_THROW_ON_ERROR),
        'effective_at' => $publishedAt,
        'approval_reference' => $fixtureMarker,
        'created_by_user_id' => null,
        'approved_by_user_id' => null,
        'published_by_user_id' => null,
        'retired_by_user_id' => null,
        'prepared_at' => $publishedAt,
        'approved_at' => $publishedAt,
        'published_at' => $publishedAt,
        'retired_at' => null,
        'created_at' => $publishedAt,
        'updated_at' => $publishedAt,
    ];
}

try {
    $connection->transaction(function () use ($connection, $fixtureMarker, $rows): void {
        foreach ($rows as $row) {
            $existing = $connection->table('legal_document_versions')
                ->where('uuid', $row['uuid'])
                ->orWhere(function ($query) use ($row): void {
                    $query->where('document_type', $row['document_type'])
                        ->where('locale', $row['locale'])
                        ->where('version', $row['version']);
                })
                ->lockForUpdate()
                ->first();

            if ($existing !== null
                && ($existing->uuid !== $row['uuid'] || $existing->approval_reference !== $fixtureMarker)
            ) {
                throw new RuntimeException('A fixture identity collides with non-fixture data.');
            }
        }

        foreach ($rows as $row) {
            $connection->table('legal_document_versions')->updateOrInsert(
                [
                    'document_type' => $row['document_type'],
                    'locale' => $row['locale'],
                    'version' => $row['version'],
                ],
                $row
            );
        }

        $catalog = app(LegalDocumentCatalog::class);
        $now = Carbon::now('UTC');

        foreach ($rows as $row) {
            $document = $catalog->current($row['document_type'], 'en', $now);

            if ($document === null
                || $document->uuid !== $row['uuid']
                || $document->status !== 'published'
                || $document->published_at === null
                || $document->effective_at === null
                || $document->effective_at->gt($now)
                || $document->approval_reference !== $fixtureMarker
            ) {
                throw new RuntimeException('The legal catalog did not resolve the synthetic fixture.');
            }

            if ($row['document_type'] === 'dpa'
                && in_array('all', $document->audiences ?? [], true) === false
            ) {
                throw new RuntimeException('The synthetic DPA does not satisfy the public audience contract.');
            }
        }
    });
} catch (Throwable $exception) {
    fwrite(STDERR, 'Synthetic legal fixture failed safely ('.get_class($exception).'). No credentials were logged.\n');
    exit(1);
}

fwrite(STDOUT, 'Verified four synthetic published legal documents for the disposable CI smoke test.\n');
