<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$status = match ($path) {
    '/up', '/hu', '/en' => 200,
    '/register', '/checkout', '/api' => 404,
    default => 404,
};

try {
    $database = getenv('WPCG_MYSQL_DATABASE');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', getenv('WPCG_MYSQL_HOST'), $database),
        getenv('WPCG_MYSQL_USER'),
        getenv('WPCG_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
    );
    $row = $pdo->query('SELECT DATABASE() AS db, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    if (($row['db'] ?? null) !== $database || !str_starts_with((string) ($row['version'] ?? ''), '8.4.')) {
        $status = 503;
    }
    $failureFlag = getenv('WPCG_FIXTURE_FAIL_FILE');
    if (is_string($failureFlag) && $failureFlag !== '' && is_file($failureFlag) && $status === 200) {
        $status = 503;
    }
} catch (Throwable) {
    $status = 503;
}

http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
echo $status === 200 ? 'ok' : 'unavailable';
