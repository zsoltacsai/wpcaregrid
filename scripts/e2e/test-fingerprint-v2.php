<?php

declare(strict_types=1);

require __DIR__.'/m8-fingerprint-v2.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$strictOptions = [
    'active_plugins', 'blog_public', 'blogdescription', 'blogname', 'gmt_offset',
    'home', 'page_for_posts', 'page_on_front', 'permalink_structure', 'show_on_front',
    'siteurl', 'stylesheet', 'template', 'timezone_string', 'wp_page_for_privacy_policy',
];
$intent = [
    'intent_version' => 1, 'url' => 'https://m8-e2e.staging.wpcaregrid.com/',
    'site_uuid' => '01a12784-ffc5-735b-9064-b2ccb2039ff0',
    'strict_options' => $strictOptions,
    'expected_option_values' => ['wp_page_for_privacy_policy' => '3'],
    'expected_content' => [
        ['id' => 3, 'type' => 'page', 'status' => 'draft', 'slug' => 'privacy-policy', 'content_sha256' => hash('sha256', 'policy')],
        ['id' => 9, 'type' => 'post', 'status' => 'publish', 'slug' => 'b', 'content_sha256' => hash('sha256', 'b')],
    ],
    'expected_media' => [
        ['path' => 'fixture/a.txt', 'sha256' => hash('sha256', 'a')],
        ['path' => 'fixture/b.txt', 'sha256' => hash('sha256', 'b')],
    ],
    'connector_version' => '0.4.2', 'theme' => 'twentytwentyfive', 'theme_version' => '1.5',
    'expected_plugins' => [['slug' => 'a/plugin.php', 'version' => '1'], ['slug' => 'z/plugin.php', 'version' => '2']],
    'expected_tables' => ['m8_options', 'm8_posts'],
];
$snapshot = [
    'url' => $intent['url'], 'site_uuid' => $intent['site_uuid'],
    'posts' => [
        ['id' => 9, 'type' => 'post', 'status' => 'publish', 'parent' => 0, 'slug' => 'b', 'title' => 'B', 'content' => 'b', 'excerpt' => ''],
        ['id' => 3, 'type' => 'page', 'status' => 'draft', 'parent' => 0, 'slug' => 'privacy-policy', 'title' => 'Privacy Policy', 'content' => 'policy', 'excerpt' => ''],
        ['id' => 10, 'type' => 'post', 'status' => 'auto-draft', 'parent' => 0, 'slug' => '', 'title' => '', 'content' => '', 'excerpt' => ''],
    ],
    'options' => [
        'wp_page_for_privacy_policy' => ['value' => '3', 'autoload' => 'yes'],
        'active_plugins' => ['value' => serialize(['z/plugin.php', 'a/plugin.php']), 'autoload' => 'yes'],
        'blogname' => ['value' => 'Fixture', 'autoload' => 'yes'],
        '_transient_volatile' => ['value' => 'one', 'autoload' => 'no'],
        'wp_caregrid_credential' => ['value' => 'sensitive-test-marker', 'autoload' => 'no'],
    ],
    'media' => [['path' => 'fixture/b.txt', 'size' => 1, 'sha256' => hash('sha256', 'b')], ['path' => 'fixture/a.txt', 'size' => 1, 'sha256' => hash('sha256', 'a')]],
    'plugins' => [['slug' => 'z/plugin.php', 'version' => '2'], ['slug' => 'a/plugin.php', 'version' => '1']],
    'theme' => ['slug' => 'twentytwentyfive', 'version' => '1.5'],
    'prefix' => 'm8_', 'tables' => ['m8_posts', 'm8_options'],
    'connector_version' => '0.4.2', 'paired' => true, 'synthetic' => true,
    'controlled_execution' => true, 'credential_present' => true,
    'integrity_failures' => [],
];
foreach ($strictOptions as $name) {
    $snapshot['options'][$name] ??= ['value' => 'fixture-value', 'autoload' => 'yes'];
}

$baseline = M8FingerprintV2::encode(M8FingerprintV2::build($snapshot, $intent));
check($baseline === M8FingerprintV2::encode(M8FingerprintV2::build($snapshot, $intent)), 'repeated output differs');
$parsed = json_decode($baseline, true, 512, JSON_THROW_ON_ERROR);
check($parsed['content'][0]['id'] === 3 && $parsed['content'][0]['status'] === 'draft', 'draft privacy page omitted');
check(count($parsed['content']) === 2, 'auto-draft not excluded');
check($parsed['integrity']['dangling_reference_count'] === 0, 'valid privacy reference reported dangling');
check($parsed['fixture_intent']['mismatch_count'] === 0, 'valid fixture intent mismatch');
check($parsed['content'][0]['id'] < $parsed['content'][1]['id'], 'posts not ordered');
check($parsed['media'][0]['path'] === 'fixture/a.txt', 'media not ordered');
check($parsed['active_plugins'][0]['slug'] === 'a/plugin.php', 'plugins not ordered');
check($parsed['persistent_options'][0]['name'] === 'active_plugins', 'options not ordered');
check(! str_contains($baseline, 'sensitive-test-marker'), 'secret value leaked');
check(! str_contains($baseline, '_transient_volatile'), 'transient leaked');
$changed = $snapshot;
$changed['options']['blogname']['autoload'] = 'auto-on';
check($baseline === M8FingerprintV2::encode(M8FingerprintV2::build($changed, $intent)), 'equivalent autoload state caused drift');

$changed = $snapshot;
$changed['options']['_transient_volatile']['value'] = 'two';
unset($changed['options']['_transient_volatile']);
check($baseline === M8FingerprintV2::encode(M8FingerprintV2::build($changed, $intent)), 'transient affected strict fingerprint');
$changed = $snapshot;
$changed['options']['blogname']['value'] = 'Other';
check($baseline !== M8FingerprintV2::encode(M8FingerprintV2::build($changed, $intent)), 'persistent option change not detected');
$changed = $snapshot;
$changed['options']['wp_page_for_privacy_policy']['value'] = '4';
check($baseline !== M8FingerprintV2::encode(M8FingerprintV2::build($changed, $intent)), 'privacy target change not detected');
$changed = $snapshot;
unset($changed['options']['wp_page_for_privacy_policy']);
try {
    M8FingerprintV2::build($changed, $intent);
    throw new RuntimeException('missing privacy option accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Missing or invalid strict option'), 'unexpected missing-option failure');
}
$changed = $snapshot;
$changed['integrity_failures'] = ['postmeta:3'];
$broken = M8FingerprintV2::build($changed, $intent);
check($broken['integrity']['dangling_reference_count'] === 1, 'dangling reference not detected');
$changed = $snapshot;
$changed['posts'][] = ['id' => 11, 'type' => 'page', 'status' => 'private', 'parent' => 0, 'slug' => 'extra', 'title' => 'Extra', 'content' => 'extra', 'excerpt' => ''];
check(in_array('content:unexpected:11', M8FingerprintV2::build($changed, $intent)['fixture_intent']['failures'], true), 'extra private page not detected');
$changed = $snapshot;
$changed['media'][] = ['path' => 'fixture/extra.txt', 'size' => 1, 'sha256' => hash('sha256', 'x')];
check(in_array('media:unexpected:fixture/extra.txt', M8FingerprintV2::build($changed, $intent)['fixture_intent']['failures'], true), 'extra media not detected');
$changed = $snapshot;
$changed['posts'] = array_reverse($changed['posts']);
$changed['media'] = array_reverse($changed['media']);
$changed['plugins'] = array_reverse($changed['plugins']);
$changed['options'] = array_reverse($changed['options'], true);
check($baseline === M8FingerprintV2::encode(M8FingerprintV2::build($changed, $intent)), 'input ordering changed fingerprint');
try {
    M8FingerprintV2::assertComparable($parsed, ['schema_version' => 'wpcg-m8-fixture/1']);
    throw new RuntimeException('schema mismatch accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'NON-COMPARABLE FINGERPRINT SCHEMA', 'wrong schema error');
}
$unsafeIntent = $intent;
$unsafeIntent['strict_options'][] = 'wp_caregrid_credential';
try {
    M8FingerprintV2::build($snapshot, $unsafeIntent);
    throw new RuntimeException('secret-bearing option accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Unsafe or incomplete'), 'unexpected unsafe-option failure');
}
$wrongSite = $snapshot;
$wrongSite['url'] = 'https://example.com/';
try {
    M8FingerprintV2::build($wrongSite, $intent);
    throw new RuntimeException('wrong site accepted');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Fixture identity'), 'unexpected wrong-site failure');
}

echo "M8 fingerprint v2 contract: PASS (draft, persistent options, volatility, integrity, ordering, secrets, schema)\n";
