<?php

declare(strict_types=1);

/**
 * Read-only, fixture-specific fingerprint. No WordPress bootstrap is performed
 * when this file is included by the standalone contract tests.
 */
final class M8FingerprintV2
{
    public const SCHEMA = 'wpcg-m8-fixture/2';

    public const GENERATOR = '2.0.0';

    private const CONTENT_STATUSES = ['publish', 'draft', 'private', 'pending', 'future', 'trash'];

    private const FIXTURE_URL = 'https://m8-e2e.staging.wpcaregrid.com/';

    private const FIXTURE_UUID = '01a12784-ffc5-735b-9064-b2ccb2039ff0';

    private const SAFE_OPTIONS = [
        'active_plugins', 'blog_public', 'blogdescription', 'blogname', 'gmt_offset',
        'home', 'page_for_posts', 'page_on_front', 'permalink_structure',
        'show_on_front', 'siteurl', 'stylesheet', 'template', 'timezone_string',
        'wp_page_for_privacy_policy',
    ];

    public static function build(array $snapshot, array $intent): array
    {
        if (($intent['intent_version'] ?? null) !== 1
            || ($intent['url'] ?? null) !== self::FIXTURE_URL
            || ($intent['site_uuid'] ?? null) !== self::FIXTURE_UUID
            || ($snapshot['url'] ?? null) !== ($intent['url'] ?? null)
            || ($snapshot['site_uuid'] ?? null) !== ($intent['site_uuid'] ?? null)) {
            throw new RuntimeException('Fixture identity or intent version mismatch');
        }
        $names = $intent['strict_options'] ?? [];
        sort($names, SORT_STRING);
        $safe = self::SAFE_OPTIONS;
        sort($safe, SORT_STRING);
        if ($names !== $safe) {
            throw new RuntimeException('Unsafe or incomplete persistent option contract');
        }

        $content = [];
        foreach ($snapshot['posts'] ?? [] as $post) {
            if (! in_array($post['type'] ?? '', ['post', 'page'], true)
                || ! in_array($post['status'] ?? '', self::CONTENT_STATUSES, true)) {
                continue;
            }
            $content[] = [
                'id' => (int) $post['id'],
                'type' => (string) $post['type'],
                'status' => (string) $post['status'],
                'parent' => (int) $post['parent'],
                'slug' => (string) $post['slug'],
                'title_sha256' => hash('sha256', (string) $post['title']),
                'content_sha256' => hash('sha256', (string) $post['content']),
                'excerpt_sha256' => hash('sha256', (string) $post['excerpt']),
            ];
        }
        usort($content, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        $options = [];
        foreach ($intent['strict_options'] as $name) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $name) || ! isset($snapshot['options'][$name])) {
                throw new RuntimeException('Missing or invalid strict option: '.$name);
            }
            $raw = (string) $snapshot['options'][$name]['value'];
            if ($name === 'active_plugins') {
                $decoded = @unserialize($raw, ['allowed_classes' => false]);
                if (! is_array($decoded) || count(array_filter($decoded, 'is_string')) !== count($decoded)) {
                    throw new RuntimeException('Invalid active_plugins encoding');
                }
                sort($decoded, SORT_STRING);
                $raw = json_encode(array_values($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            $options[] = [
                'name' => $name,
                'autoload' => self::autoloadClass((string) $snapshot['options'][$name]['autoload']),
                'sha256' => hash('sha256', $raw),
            ];
        }
        usort($options, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $media = array_values($snapshot['media'] ?? []);
        usort($media, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $plugins = array_values($snapshot['plugins'] ?? []);
        usort($plugins, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        $tables = array_values($snapshot['tables'] ?? []);
        sort($tables, SORT_STRING);
        $failures = array_values($snapshot['integrity_failures'] ?? []);
        sort($failures, SORT_STRING);

        $intentFailures = [];
        $byId = array_column($content, null, 'id');
        foreach ($intent['expected_content'] as $expected) {
            $observed = $byId[$expected['id']] ?? null;
            foreach (['type', 'status', 'slug', 'content_sha256'] as $field) {
                if (($observed[$field] ?? null) !== $expected[$field]) {
                    $intentFailures[] = 'content:'.$expected['id'].':'.$field;
                }
            }
        }
        $expectedIds = array_map(static fn (array $item): int => (int) $item['id'], $intent['expected_content']);
        foreach ($content as $item) {
            if (! in_array($item['id'], $expectedIds, true)) {
                $intentFailures[] = 'content:unexpected:'.$item['id'];
            }
        }
        $byPath = array_column($media, null, 'path');
        foreach ($intent['expected_media'] as $expected) {
            if (($byPath[$expected['path']]['sha256'] ?? null) !== $expected['sha256']) {
                $intentFailures[] = 'media:'.$expected['path'];
            }
        }
        $expectedPaths = array_column($intent['expected_media'], 'path');
        foreach ($media as $item) {
            if (! in_array($item['path'], $expectedPaths, true)) {
                $intentFailures[] = 'media:unexpected:'.$item['path'];
            }
        }
        foreach ($intent['expected_option_values'] as $name => $expected) {
            if (($snapshot['options'][$name]['value'] ?? null) !== $expected) {
                $intentFailures[] = 'option:'.$name;
            }
        }
        if (($snapshot['connector_version'] ?? null) !== $intent['connector_version']) {
            $intentFailures[] = 'connector:version';
        }
        if (($snapshot['theme']['slug'] ?? null) !== $intent['theme']) {
            $intentFailures[] = 'theme:slug';
        }
        if (($snapshot['theme']['version'] ?? null) !== ($intent['theme_version'] ?? null)) {
            $intentFailures[] = 'theme:version';
        }
        if ($plugins !== ($intent['expected_plugins'] ?? [])) {
            $intentFailures[] = 'plugins:active';
        }
        if ($tables !== ($intent['expected_tables'] ?? [])) {
            $intentFailures[] = 'database:tables';
        }
        sort($intentFailures, SORT_STRING);

        $result = [
            'schema_version' => self::SCHEMA,
            'generator_version' => self::GENERATOR,
            'intent_version' => 1,
            'site' => ['url' => $snapshot['url'], 'site_uuid' => $snapshot['site_uuid']],
            'content' => $content,
            'persistent_options' => $options,
            'media' => $media,
            'active_plugins' => $plugins,
            'theme' => $snapshot['theme'],
            'database' => ['prefix' => $snapshot['prefix'], 'tables' => $tables, 'strict_content_count' => count($content)],
            'connector' => [
                'version' => $snapshot['connector_version'],
                'paired' => (bool) $snapshot['paired'],
                'synthetic' => (bool) $snapshot['synthetic'],
                'controlled_execution' => (bool) $snapshot['controlled_execution'],
                'credential_present' => (bool) $snapshot['credential_present'],
            ],
            'integrity' => ['dangling_reference_count' => count($failures), 'failures' => $failures],
            'fixture_intent' => ['mismatch_count' => count($intentFailures), 'failures' => $intentFailures],
        ];
        self::assertSecretFree($result);

        return $result;
    }

    public static function encode(array $fingerprint): string
    {
        return json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function assertComparable(array $left, array $right): void
    {
        if (($left['schema_version'] ?? null) !== self::SCHEMA
            || ($right['schema_version'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('NON-COMPARABLE FINGERPRINT SCHEMA');
        }
    }

    private static function autoloadClass(string $raw): string
    {
        return match ($raw) {
            'yes', 'on', 'auto-on', 'auto' => 'on',
            'no', 'off', 'auto-off' => 'off',
            default => throw new RuntimeException('Unknown autoload classification'),
        };
    }

    private static function assertSecretFree(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/(?:password|credential(?!_present)|token|secret|salt|session|private_key|auth_cookie)/i', $key)) {
                throw new RuntimeException('Secret-bearing output field refused');
            }
            if (is_array($value)) {
                self::assertSecretFree($value);
            }
        }
    }
}

/** Collect only known fixture fields; never emit raw option values or post bodies. */
function m8CollectWordPress(array $intent, string $root): array
{
    if (! is_file($root.'/wp-load.php')) {
        throw new RuntimeException('WordPress root missing');
    }
    define('DISABLE_WP_CRON', true);
    define('WP_USE_THEMES', false);
    require $root.'/wp-load.php';
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    global $wpdb;
    if (! preg_match('/^[a-zA-Z0-9_]+$/', $wpdb->prefix)) {
        throw new RuntimeException('Unsafe WordPress table prefix');
    }
    if ($wpdb->prefix !== 'm8_') {
        throw new RuntimeException('Wrong fixture table prefix');
    }
    $url = home_url('/');
    if ($url !== $intent['url']) {
        throw new RuntimeException('Wrong fixture URL');
    }
    $stateRaw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", 'wp_caregrid_state'
    ));
    $state = @unserialize((string) $stateRaw, ['allowed_classes' => false]);
    if (! is_array($state) || ($state['website_uuid'] ?? null) !== $intent['site_uuid']) {
        throw new RuntimeException('Wrong Connector pairing identity');
    }
    $rows = $wpdb->get_results(
        "SELECT ID, post_type, post_status, post_parent, post_name, post_title, post_content, post_excerpt
         FROM `{$wpdb->posts}` WHERE post_type IN ('post','page') ORDER BY ID", ARRAY_A
    );
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('Post query failed');
    }
    $posts = array_map(static fn (array $r): array => [
        'id' => (int) $r['ID'], 'type' => $r['post_type'], 'status' => $r['post_status'],
        'parent' => (int) $r['post_parent'], 'slug' => $r['post_name'], 'title' => $r['post_title'],
        'content' => $r['post_content'], 'excerpt' => $r['post_excerpt'],
    ], $rows);
    $options = [];
    foreach ($intent['strict_options'] as $name) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value, autoload FROM `{$wpdb->options}` WHERE option_name = %s", $name
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Option query failed');
        }
        if ($row !== null) {
            $options[$name] = ['value' => $row['option_value'], 'autoload' => $row['autoload']];
        }
    }
    $media = [];
    $uploads = realpath(WP_CONTENT_DIR.'/uploads');
    if ($uploads === false) {
        throw new RuntimeException('Uploads directory missing');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Symlink in fixture uploads');
        }
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getRealPath();
        if ($path === false || ! str_starts_with($path, $uploads.'/')) {
            throw new RuntimeException('Unsafe media path');
        }
        $relative = substr($path, strlen($uploads) + 1);
        $media[] = ['path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
    }
    $installed = get_plugins();
    $active = get_option('active_plugins', []);
    $plugins = [];
    foreach ((array) $active as $slug) {
        $plugins[] = ['slug' => (string) $slug, 'version' => (string) ($installed[$slug]['Version'] ?? 'unknown')];
    }
    $theme = wp_get_theme();
    $tables = array_values(array_filter((array) $wpdb->get_col('SHOW TABLES'), static fn ($t) => str_starts_with((string) $t, $wpdb->prefix)));

    $integrity = [];
    $checks = [
        'postmeta' => "SELECT pm.post_id FROM `{$wpdb->postmeta}` pm LEFT JOIN `{$wpdb->posts}` p ON p.ID=pm.post_id WHERE p.ID IS NULL",
        'terms' => "SELECT tr.object_id FROM `{$wpdb->term_relationships}` tr LEFT JOIN `{$wpdb->posts}` p ON p.ID=tr.object_id WHERE p.ID IS NULL",
        'comments' => "SELECT c.comment_post_ID FROM `{$wpdb->comments}` c LEFT JOIN `{$wpdb->posts}` p ON p.ID=c.comment_post_ID WHERE c.comment_post_ID<>0 AND p.ID IS NULL",
        'parent' => "SELECT c.ID FROM `{$wpdb->posts}` c LEFT JOIN `{$wpdb->posts}` p ON p.ID=c.post_parent WHERE c.post_parent<>0 AND p.ID IS NULL",
        'menu_post' => "SELECT m.ID FROM `{$wpdb->posts}` m JOIN `{$wpdb->postmeta}` t ON t.post_id=m.ID AND t.meta_key='_menu_item_type' AND t.meta_value='post_type' JOIN `{$wpdb->postmeta}` o ON o.post_id=m.ID AND o.meta_key='_menu_item_object_id' LEFT JOIN `{$wpdb->posts}` p ON p.ID=CAST(o.meta_value AS UNSIGNED) WHERE m.post_type='nav_menu_item' AND p.ID IS NULL",
    ];
    foreach ($checks as $name => $sql) {
        $ids = $wpdb->get_col($sql);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Integrity query failed');
        }
        foreach ((array) $ids as $id) {
            $integrity[] = $name.':'.(int) $id;
        }
    }
    foreach (['wp_page_for_privacy_policy', 'page_on_front', 'page_for_posts'] as $name) {
        $id = (int) ($options[$name]['value'] ?? 0);
        if ($id > 0) {
            $type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM `{$wpdb->posts}` WHERE ID=%d", $id));
            if ($type !== 'page') {
                $integrity[] = 'option:'.$name.':'.$id;
            }
        }
    }
    $credentialPresent = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT EXISTS(SELECT 1 FROM `{$wpdb->options}` WHERE option_name=%s)", 'wp_caregrid_credential'
    ));

    return [
        'url' => $url, 'site_uuid' => $intent['site_uuid'], 'posts' => $posts,
        'options' => $options, 'media' => $media, 'plugins' => $plugins,
        'theme' => ['slug' => get_stylesheet(), 'version' => (string) $theme->get('Version')],
        'prefix' => $wpdb->prefix, 'tables' => $tables, 'connector_version' => (string) ($installed['wp-caregrid-connector/wp-caregrid-connector.php']['Version'] ?? 'missing'),
        'paired' => ($state['status'] ?? null) === 'connected', 'synthetic' => ($state['synthetic_site'] ?? false) === true,
        'controlled_execution' => ($state['controlled_execution'] ?? false) === true,
        'credential_present' => $credentialPresent, 'integrity_failures' => $integrity,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || in_array('--run', $argv ?? [], true)) {
    try {
        $args = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^--([a-z0-9-]+)=(.*)$/s', $arg, $match)) {
                $args[$match[1]] = $match[2];
            }
        }
        if (isset($args['compare'])) {
            $other = json_decode(file_get_contents($args['compare']), true, 512, JSON_THROW_ON_ERROR);
            $current = json_decode(file_get_contents($args['current'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            M8FingerprintV2::assertComparable($current, $other);
            exit(M8FingerprintV2::encode($current) === M8FingerprintV2::encode($other) ? 0 : 1);
        }
        $encoded = $args['intent-base64'] ?? null;
        $intent = $encoded !== null
            ? json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR)
            : json_decode(file_get_contents($args['intent'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $snapshot = m8CollectWordPress($intent, $args['wp-root'] ?? '');
        echo M8FingerprintV2::encode(M8FingerprintV2::build($snapshot, $intent));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(2);
    }
}
