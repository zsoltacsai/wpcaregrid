<?php

declare(strict_types=1);

// Creates only the deterministic M16 visual-QA customer in the isolated local
// SQLite database. This helper must fail closed outside that worktree fixture.
$projectRoot = realpath(dirname(__DIR__, 3));
$databasePath = getenv('M16_LOCAL_SQLITE_PATH');
$baseUrl = getenv('E2E_BASE_URL');
$savedLocale = getenv('M16_FIXTURE_LOCALE');

if (PHP_SAPI !== 'cli' || getenv('M16_LOCAL_E2E') !== '1' || ! $projectRoot || ! is_string($databasePath) || ! is_string($baseUrl) || ! in_array($savedLocale, ['hu', 'en'], true)) {
    fwrite(STDERR, "M16_FIXTURE=REJECTED\n");
    exit(2);
}

$databaseRealPath = realpath($databasePath);
$baseHost = parse_url($baseUrl, PHP_URL_HOST);
$baseScheme = parse_url($baseUrl, PHP_URL_SCHEME);
$localDatabaseRoot = $projectRoot.DIRECTORY_SEPARATOR.'.local-dev'.DIRECTORY_SEPARATOR;

if (
    is_link($databasePath)
    || ! $databaseRealPath
    || ! is_file($databaseRealPath)
    || ! is_readable($databaseRealPath)
    || ! str_starts_with($databaseRealPath, $localDatabaseRoot)
    || basename($databaseRealPath) !== 'm16-e2e.sqlite'
    || $baseScheme !== 'http'
    || ! in_array($baseHost, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)
) {
    fwrite(STDERR, "M16_FIXTURE=REJECTED\n");
    exit(2);
}

$pdo = new PDO('sqlite:'.$databaseRealPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$requiredColumns = [
    'users' => ['id', 'name', 'email', 'email_verified_at', 'password', 'created_at', 'updated_at', 'locale'],
    'customers' => ['id', 'uuid', 'user_id', 'email', 'first_name', 'last_name', 'country_code', 'customer_type', 'billing_address', 'active', 'created_at', 'updated_at'],
];

foreach ($requiredColumns as $table => $expected) {
    $actual = array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (array_diff($expected, $actual) !== []) {
        fwrite(STDERR, "M16_FIXTURE=SCHEMA_REJECTED\n");
        exit(2);
    }
}

$email = 'm16-locale-customer@caregrid.test';
$password = 'M16-Synthetic-Only-2026!';
$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$now = gmdate('Y-m-d H:i:s');

$pdo->beginTransaction();
$user = $pdo->prepare(<<<'SQL'
    INSERT INTO users (name, email, email_verified_at, password, created_at, updated_at, locale)
    VALUES (:name, :email, :verified, :password, :created, :updated, :locale)
    ON CONFLICT(email) DO UPDATE SET
        name = excluded.name,
        email_verified_at = excluded.email_verified_at,
        password = excluded.password,
        updated_at = excluded.updated_at,
        locale = excluded.locale
    SQL);
$user->execute([
    'name' => 'M16 Synthetic Customer',
    'email' => $email,
    'verified' => $now,
    'password' => $passwordHash,
    'created' => $now,
    'updated' => $now,
    'locale' => $savedLocale,
]);

$userId = (int) $pdo->query("SELECT id FROM users WHERE email = 'm16-locale-customer@caregrid.test'")->fetchColumn();
$customer = $pdo->prepare(<<<'SQL'
    INSERT INTO customers (uuid, user_id, email, first_name, last_name, country_code, customer_type, billing_address, active, created_at, updated_at)
    VALUES ('00000000-0000-4000-8000-000000000016', :user_id, :email, 'M16', 'Synthetic', 'HU', 'individual', '{"line1":"Synthetic Test Road 1","postal_code":"1011","city":"Budapest"}', 1, :created, :updated)
    ON CONFLICT(email) DO UPDATE SET
        user_id = excluded.user_id,
        first_name = excluded.first_name,
        last_name = excluded.last_name,
        country_code = excluded.country_code,
        customer_type = excluded.customer_type,
        billing_address = excluded.billing_address,
        active = 1,
        updated_at = excluded.updated_at
    SQL);
$customer->execute(['user_id' => $userId, 'email' => $email, 'created' => $now, 'updated' => $now]);
$pdo->commit();

echo "M16_FIXTURE=READY\n";
