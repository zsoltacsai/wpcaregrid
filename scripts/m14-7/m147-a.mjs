import { randomBytes, randomUUID, createHash } from 'node:crypto';
import { cp, lstat, mkdir, readFile, readdir, realpath, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const FIXTURE_RELATIVE_PATH = path.join('storage', 'e2e', 'm14-7-local-fixture');
export const FIXTURE_PURPOSE = 'm147-a-local-disposable-wordpress';
const APPROVED_BRANCH = /^codex\/m14-7-[a-z0-9-]+$/;
const MARKER_NAME = '.wpcaregrid-m147-a-fixture.json';
const SAFE_TEST_ID = /^[A-Z0-9][A-Z0-9._-]{0,63}$/;
const SAFE_SHA = /^[a-f0-9]{40}$/;
const OUTCOMES = new Set(['PASS', 'FAIL', 'BLOCKED', 'UNAVAILABLE']);
const COVERAGE = new Set(['NOT_RUN', 'UNAVAILABLE', 'PARTIAL', 'COMPLETE']);
const CLEANUP = new Set(['NOT_REQUIRED', 'PENDING', 'VERIFIED']);

export class FixtureBlockedError extends Error {
    constructor(code) {
        super(code);
        this.name = 'FixtureBlockedError';
        this.code = code;
    }
}

function runGit(root, args) {
    const result = spawnSync('git', ['-C', root, ...args], { encoding: 'utf8', windowsHide: true });
    if (result.status !== 0) throw new FixtureBlockedError('GIT_CONTEXT_UNAVAILABLE');
    return result.stdout.trim();
}

export function allowedLocalEgress(url) {
    let parsed;
    try { parsed = new URL(url); } catch { return false; }
    return parsed.protocol === 'http:'
        && ['127.0.0.1', 'localhost', '::1', '[::1]'].includes(parsed.hostname.toLowerCase())
        && [8317, 8318].includes(Number(parsed.port || 80))
        && parsed.username === '' && parsed.password === '';
}

export function createFakeSideEffectAdapters() {
    const jobs = [];
    const objects = new Map();
    return Object.freeze({
        queue: Object.freeze({
            dispatch(name, payload = {}) {
                if (typeof name !== 'string' || !name.startsWith('m147.synthetic.')) {
                    throw new FixtureBlockedError('NON_SYNTHETIC_JOB_REJECTED');
                }
                jobs.push({ name, payload: structuredClone(payload) });
            },
            snapshot: () => structuredClone(jobs),
        }),
        storage: Object.freeze({
            put(key, value) {
                if (typeof key !== 'string' || key.startsWith('/') || key.includes('..') || key.includes('\\')) {
                    throw new FixtureBlockedError('UNSAFE_SYNTHETIC_STORAGE_KEY');
                }
                objects.set(key, String(value));
            },
            snapshot: () => new Map(objects),
        }),
        destructive: Object.freeze({
            delete() { throw new FixtureBlockedError('DESTRUCTIVE_OPERATION_NOT_SUPPORTED'); },
            restore() { throw new FixtureBlockedError('DESTRUCTIVE_OPERATION_NOT_SUPPORTED'); },
        }),
    });
}

async function rejectSymlink(target, code = 'SYMLINK_PATH_REJECTED') {
    try {
        const info = await lstat(target);
        if (info.isSymbolicLink()) throw new FixtureBlockedError(code);
        return info;
    } catch (error) {
        if (error instanceof FixtureBlockedError) throw error;
        if (error.code === 'ENOENT') return null;
        throw error;
    }
}

async function assertNoSymlinks(root) {
    const pending = [root];
    while (pending.length) {
        const current = pending.pop();
        const info = await lstat(current);
        if (info.isSymbolicLink()) throw new FixtureBlockedError('SOURCE_SYMLINK_REJECTED');
        if (!info.isDirectory()) continue;
        for (const entry of await readdir(current, { withFileTypes: true })) {
            pending.push(path.join(current, entry.name));
        }
    }
}

async function fileTreeSha256(root) {
    const entries = [];
    const walk = async (directory) => {
        for (const entry of (await readdir(directory, { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name))) {
            const absolute = path.join(directory, entry.name);
            if (entry.isSymbolicLink()) throw new FixtureBlockedError('SOURCE_SYMLINK_REJECTED');
            if (entry.isDirectory()) await walk(absolute);
            else if (entry.isFile()) {
                const relative = path.relative(root, absolute).split(path.sep).join('/');
                const digest = createHash('sha256').update(await readFile(absolute)).digest('hex');
                entries.push(`${relative}\0${digest}`);
            }
        }
    };
    await walk(root);
    return createHash('sha256').update(entries.join('\n')).digest('hex');
}

export async function inspectContext(root, environment = 'local-disposable') {
    const absoluteRoot = await realpath(root);
    if (environment !== 'local-disposable') return { classification: 'BLOCKED', reasons: ['ENVIRONMENT_NOT_LOCAL_DISPOSABLE'] };
    let branch;
    let repositorySha;
    try {
        branch = runGit(absoluteRoot, ['branch', '--show-current']);
        repositorySha = runGit(absoluteRoot, ['rev-parse', 'HEAD']);
    } catch (error) {
        return { classification: 'BLOCKED', reasons: [error.code || 'GIT_CONTEXT_UNAVAILABLE'] };
    }
    if (!APPROVED_BRANCH.test(branch) || !SAFE_SHA.test(repositorySha)) {
        return { classification: 'BLOCKED', reasons: ['UNAPPROVED_WORKTREE'] };
    }

    const connectorDir = path.join(absoluteRoot, 'wordpress', 'wp-caregrid-connector');
    const releasePath = path.join(connectorDir, 'release.json');
    const pluginPath = path.join(connectorDir, 'wp-caregrid-connector.php');
    const coreDir = path.join(absoluteRoot, 'wordpress', 'vendor', 'johnpbloch', 'wordpress-core');
    const sqlitePath = path.join(absoluteRoot, 'wordpress', 'vendor', 'aaemnnosttv', 'wp-sqlite-db', 'src', 'db.php');
    const required = [
        ['CONNECTOR_RELEASE_MISSING', releasePath],
        ['CONNECTOR_PLUGIN_MISSING', pluginPath],
        ['WORDPRESS_CORE_NOT_INSTALLED', path.join(coreDir, 'wp-load.php')],
        ['SQLITE_DROPIN_NOT_INSTALLED', sqlitePath],
    ];
    const missing = [];
    for (const [code, file] of required) if (!(await rejectSymlink(file))) missing.push(code);
    if (missing.length) return { classification: 'BLOCKED', reasons: missing, branch, repositorySha };

    let release;
    try { release = JSON.parse(await readFile(releasePath, 'utf8')); }
    catch { return { classification: 'BLOCKED', reasons: ['CONNECTOR_RELEASE_INVALID'], branch, repositorySha }; }
    const plugin = await readFile(pluginPath, 'utf8');
    const header = plugin.match(/^\s*\*\s*Version:\s*([^\s]+)\s*$/m)?.[1];
    if (typeof release.version !== 'string' || header !== release.version) {
        return { classification: 'BLOCKED', reasons: ['CONNECTOR_VERSION_MISMATCH'], branch, repositorySha };
    }
    const coreVersionFile = await readFile(path.join(coreDir, 'wp-includes', 'version.php'), 'utf8').catch(() => '');
    const wordpressVersion = coreVersionFile.match(/\$wp_version\s*=\s*'([^']+)'/)?.[1];
    if (!wordpressVersion) return { classification: 'BLOCKED', reasons: ['WORDPRESS_CORE_VERSION_UNREADABLE'], branch, repositorySha };

    return {
        classification: 'READY', reasons: [], branch, repositorySha, wordpressVersion,
        connectorVersion: release.version, connectorPackageSchema: release.package_schema,
        connectorSourceTreeSha256: await fileTreeSha256(connectorDir),
        wordpressCoreTreeSha256: await fileTreeSha256(coreDir),
        coreDir, connectorDir, sqlitePath,
    };
}

function assertSafeEvidence(input, targetId) {
    const allowedKeys = ['schema_version', 'test_id', 'scope', 'repository_sha', 'connector_version', 'connector_tree_sha256', 'target_id', 'environment', 'executed_at', 'expected', 'actual', 'classification', 'scanner_coverage', 'cleanup', 'ci_run_id', 'ci_run_url'];
    const parsedTimestamp = typeof input?.executed_at === 'string' ? Date.parse(input.executed_at) : Number.NaN;
    if (!input || typeof input !== 'object' || Array.isArray(input) || Object.keys(input).some((key) => !allowedKeys.includes(key))) {
        throw new FixtureBlockedError('EVIDENCE_SCHEMA_REJECTED');
    }
    if (input.schema_version !== 1 || !SAFE_TEST_ID.test(input.test_id || '') || !SAFE_SHA.test(input.repository_sha || '')
        || !/^[a-f0-9]{64}$/.test(input.connector_tree_sha256 || '') || input.target_id !== targetId
        || input.environment !== 'local-disposable' || !OUTCOMES.has(input.expected) || !OUTCOMES.has(input.actual)
        || !OUTCOMES.has(input.classification) || !COVERAGE.has(input.scanner_coverage) || !CLEANUP.has(input.cleanup)
        || typeof input.scope !== 'string' || !/^[a-zA-Z0-9 .,_:/-]{1,160}$/.test(input.scope)
        || typeof input.connector_version !== 'string' || !/^\d+\.\d+\.\d+$/.test(input.connector_version)
        || Number.isNaN(parsedTimestamp) || input.executed_at !== new Date(parsedTimestamp).toISOString()
        || (input.ci_run_id !== null && !/^\d{1,20}$/.test(String(input.ci_run_id)))
        || (input.ci_run_url !== null && !/^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\/actions\/runs\/\d+$/.test(input.ci_run_url))) {
        throw new FixtureBlockedError('EVIDENCE_VALUE_REJECTED');
    }
    return Object.fromEntries(allowedKeys.map((key) => [key, input[key] ?? null]));
}

export async function writeEvidence(root, targetId, record) {
    const repo = await realpath(root);
    const branch = runGit(repo, ['branch', '--show-current']);
    if (!APPROVED_BRANCH.test(branch)) throw new FixtureBlockedError('UNAPPROVED_WORKTREE');
    const repositorySha = runGit(repo, ['rev-parse', 'HEAD']);
    const fixturePath = path.join(repo, FIXTURE_RELATIVE_PATH);
    const canonicalFixture = await realpath(fixturePath).catch(() => null);
    if (!canonicalFixture) throw new FixtureBlockedError('UNMARKED_FIXTURE_PATH_REFUSED');
    if (canonicalFixture !== fixturePath) throw new FixtureBlockedError('FIXTURE_PATH_CANONICALIZATION_FAILED');
    const fixtureInfo = await rejectSymlink(fixturePath);
    if (!fixtureInfo?.isDirectory()) throw new FixtureBlockedError('UNMARKED_FIXTURE_PATH_REFUSED');
    const markerPath = path.join(fixturePath, MARKER_NAME);
    if (!(await rejectSymlink(markerPath))?.isFile()) throw new FixtureBlockedError('UNMARKED_FIXTURE_PATH_REFUSED');
    let marker;
    try { marker = JSON.parse(await readFile(markerPath, 'utf8')); }
    catch { throw new FixtureBlockedError('FIXTURE_MARKER_INVALID'); }
    if (marker.purpose !== FIXTURE_PURPOSE || marker.repository_root !== repo || marker.branch !== branch
        || marker.repository_sha !== repositorySha || marker.environment !== 'local-disposable' || marker.target_id !== targetId
        || !/^m147-a-[a-f0-9-]{36}$/.test(marker.target_id || '')) {
        throw new FixtureBlockedError('FIXTURE_MARKER_MISMATCH');
    }
    const safe = assertSafeEvidence(record, targetId);
    if (safe.repository_sha !== marker.repository_sha || safe.connector_tree_sha256 !== marker.connector_source_tree_sha256) {
        throw new FixtureBlockedError('EVIDENCE_SOURCE_MISMATCH');
    }
    const evidenceDir = path.join(fixturePath, 'evidence');
    await rejectSymlink(evidenceDir, 'EVIDENCE_PATH_SYMLINK_REJECTED');
    await mkdir(evidenceDir, { recursive: true });
    if (await realpath(evidenceDir) !== evidenceDir) throw new FixtureBlockedError('EVIDENCE_PATH_CANONICALIZATION_FAILED');
    const output = path.join(evidenceDir, `${safe.test_id}.json`);
    await rejectSymlink(output, 'EVIDENCE_FILE_SYMLINK_REJECTED');
    await writeFile(output, `${JSON.stringify(safe, null, 2)}\n`, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
    return output;
}

function escapePhp(value) {
    return value.replaceAll('\\', '\\\\').replaceAll("'", "\\'");
}

function createWpConfig(targetId) {
    const salts = Array.from({ length: 8 }, () => randomBytes(32).toString('hex'));
    const keyNames = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
    const saltDefinitions = keyNames.map((name, index) => `define('${name}', '${salts[index]}');`).join('\n');
    return `<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
define('WP_HOME', 'http://127.0.0.1:8318');
define('WP_SITEURL', 'http://127.0.0.1:8318');
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', WP_SITEURL . '/wp-content');
define('DB_DIR', __DIR__ . '/database/');
define('DB_FILE', 'wp.sqlite');
define('DB_NAME', 'm147_synthetic');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
${saltDefinitions}
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_CAREGRID_API_URL', 'http://127.0.0.1:8317/api/v1/connector');
define('WP_CAREGRID_ALLOW_INSECURE_LOCALHOST', true);
define('M147_SYNTHETIC_TARGET_ID', '${escapePhp(targetId)}');
if (!function_exists('wp_mail')) { function wp_mail($to, $subject, $message, $headers = '', $attachments = []) { return true; } }
require_once ABSPATH . 'wp-settings.php';
`;
}

function createMuPlugin() {
    return `<?php
/** M14.7 local synthetic fixture: only loopback Connector HTTP is permitted. */
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($pre !== false) return $pre;
    $parts = wp_parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $local = $scheme === 'http'
        && in_array($host, ['127.0.0.1', 'localhost'], true)
        && in_array($port, [8317, 8318], true);
    return $local ? false : new WP_Error('m147_external_http_blocked', 'External HTTP is disabled for the M14.7 local fixture.');
}, PHP_INT_MAX, 3);
`;
}

export async function preflight(root, environment = 'local-disposable') {
    return inspectContext(root, environment);
}

export async function prepareFixture(root, environment = 'local-disposable') {
    const context = await preflight(root, environment);
    if (context.classification !== 'READY') throw new FixtureBlockedError(context.reasons[0] || 'FIXTURE_PREFLIGHT_BLOCKED');
    const repo = await realpath(root);
    const parent = path.join(repo, 'storage', 'e2e');
    const storage = path.join(repo, 'storage');
    await rejectSymlink(storage);
    await rejectSymlink(parent);
    await mkdir(parent, { recursive: true });
    const target = path.join(repo, FIXTURE_RELATIVE_PATH);
    const existing = await rejectSymlink(target);
    if (existing) {
        const markerPath = path.join(target, MARKER_NAME);
        const markerInfo = await rejectSymlink(markerPath, 'FIXTURE_MARKER_INVALID');
        if (!markerInfo?.isFile()) throw new FixtureBlockedError('UNMARKED_FIXTURE_PATH_REFUSED');
        let marker;
        try { marker = JSON.parse(await readFile(markerPath, 'utf8')); }
        catch { throw new FixtureBlockedError('FIXTURE_MARKER_INVALID'); }
        if (marker.purpose !== FIXTURE_PURPOSE || marker.repository_root !== repo || marker.repository_sha !== context.repositorySha
            || marker.branch !== context.branch || marker.environment !== 'local-disposable' || !marker.target_id) throw new FixtureBlockedError('FIXTURE_MARKER_MISMATCH');
        for (const file of [
            path.join(target, 'wordpress', 'wp-load.php'),
            path.join(target, 'wp-config.php'),
            path.join(target, 'synthetic-identities.json'),
            path.join(target, 'wordpress', 'wp-content', 'plugins', 'wp-caregrid-connector', 'release.json'),
        ]) {
            if (!(await rejectSymlink(file))?.isFile()) throw new FixtureBlockedError('FIXTURE_INCOMPLETE_REFUSED');
        }
        return { classification: 'READY', target_id: marker.target_id, reused: true, connector_version: context.connectorVersion };
    }

    const targetId = `m147-a-${randomUUID()}`;
    const marker = {
        schema_version: 1,
        purpose: FIXTURE_PURPOSE,
        repository_root: repo,
        repository_sha: context.repositorySha,
        branch: context.branch,
        environment: 'local-disposable',
        target_id: targetId,
        synthetic_tenant_id: `synthetic-tenant-${randomUUID()}`,
        synthetic_website_id: `synthetic-website-${randomUUID()}`,
        synthetic_user_id: `synthetic-owner-${randomUUID()}`,
        synthetic_domain: `${targetId}.example.invalid`,
        wordpress_version: context.wordpressVersion,
        connector_version: context.connectorVersion,
        connector_source_tree_sha256: context.connectorSourceTreeSha256,
        wordpress_core_tree_sha256: context.wordpressCoreTreeSha256,
        created_at: new Date().toISOString(),
    };

    await mkdir(target, { recursive: false });
    await writeFile(path.join(target, MARKER_NAME), `${JSON.stringify(marker, null, 2)}\n`, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
    await assertNoSymlinks(context.coreDir);
    await assertNoSymlinks(context.connectorDir);
    await cp(context.coreDir, path.join(target, 'wordpress'), { recursive: true, errorOnExist: true, force: false, dereference: false });
    const content = path.join(target, 'wordpress', 'wp-content');
    await mkdir(path.join(content, 'plugins'), { recursive: true });
    await mkdir(path.join(content, 'mu-plugins'), { recursive: true });
    await mkdir(path.join(target, 'database'), { recursive: true });
    await cp(context.connectorDir, path.join(content, 'plugins', 'wp-caregrid-connector'), { recursive: true, errorOnExist: true, force: false, dereference: false });
    await cp(context.sqlitePath, path.join(content, 'db.php'), { errorOnExist: true, force: false });
    await writeFile(path.join(content, 'mu-plugins', 'm147-local-http-only.php'), createMuPlugin(), { encoding: 'utf8', flag: 'wx', mode: 0o600 });
    await writeFile(path.join(target, 'wp-config.php'), createWpConfig(targetId), { encoding: 'utf8', flag: 'wx', mode: 0o600 });
    await writeFile(path.join(target, 'synthetic-identities.json'), `${JSON.stringify({
        target_id: targetId,
        environment: 'local-disposable',
        tenant_id: marker.synthetic_tenant_id,
        website_id: marker.synthetic_website_id,
        user_id: marker.synthetic_user_id,
        website_url: `http://${targetId}.example.invalid`,
        wp_admin_login: `m147-${randomUUID().slice(0, 8)}`,
        wp_admin_email: `${targetId}@example.invalid`,
    }, null, 2)}\n`, { encoding: 'utf8', flag: 'wx', mode: 0o600 });

    return { classification: 'READY', target_id: targetId, reused: false, connector_version: context.connectorVersion };
}

export async function cleanupFixture(root) {
    const repo = await realpath(root);
    const target = path.join(repo, FIXTURE_RELATIVE_PATH);
    const info = await rejectSymlink(target);
    if (!info) return { classification: 'PASS', removed: false, reason: 'ALREADY_ABSENT' };
    if (!info.isDirectory()) throw new FixtureBlockedError('FIXTURE_TARGET_NOT_DIRECTORY');
    const markerPath = path.join(target, MARKER_NAME);
    const markerInfo = await rejectSymlink(markerPath, 'FIXTURE_MARKER_INVALID');
    if (!markerInfo?.isFile()) throw new FixtureBlockedError('UNMARKED_FIXTURE_PATH_REFUSED');
    let marker;
    try { marker = JSON.parse(await readFile(markerPath, 'utf8')); }
    catch { throw new FixtureBlockedError('FIXTURE_MARKER_INVALID'); }
    if (marker.purpose !== FIXTURE_PURPOSE || marker.repository_root !== repo || marker.environment !== 'local-disposable'
        || !APPROVED_BRANCH.test(marker.branch || '') || !SAFE_SHA.test(marker.repository_sha || '') || !/^m147-a-[a-f0-9-]{36}$/.test(marker.target_id || '')) {
        throw new FixtureBlockedError('FIXTURE_MARKER_MISMATCH');
    }
    const canonical = await realpath(target);
    if (canonical !== target) throw new FixtureBlockedError('FIXTURE_PATH_CANONICALIZATION_FAILED');
    await rm(target, { recursive: true, force: false, maxRetries: 0 });
    return { classification: 'PASS', removed: true, reason: 'OWNED_FIXTURE_REMOVED' };
}

export async function captureBaseline(root) {
    const repo = await realpath(root);
    const branch = runGit(repo, ['branch', '--show-current']);
    const repositorySha = runGit(repo, ['rev-parse', 'HEAD']);
    if (!APPROVED_BRANCH.test(branch) || !SAFE_SHA.test(repositorySha)) throw new FixtureBlockedError('UNAPPROVED_WORKTREE');
    const sourceCommit = runGit(repo, ['log', '-1', '--format=%H', '--', 'wordpress/wp-caregrid-connector']);
    const connectorDir = path.join(repo, 'wordpress', 'wp-caregrid-connector');
    const release = JSON.parse(await readFile(path.join(connectorDir, 'release.json'), 'utf8'));
    const plugin = await readFile(path.join(connectorDir, 'wp-caregrid-connector.php'), 'utf8');
    const pluginVersion = plugin.match(/^\s*\*\s*Version:\s*([^\s]+)\s*$/m)?.[1];
    if (release.version !== pluginVersion) throw new FixtureBlockedError('CONNECTOR_VERSION_MISMATCH');
    const composerLock = JSON.parse(await readFile(path.join(repo, 'wordpress', 'composer.lock'), 'utf8'));
    const corePackage = composerLock.packages.concat(composerLock['packages-dev'] || []).find((packageInfo) => packageInfo.name === 'johnpbloch/wordpress-core');
    const serverComposerLock = JSON.parse(await readFile(path.join(repo, 'composer.lock'), 'utf8'));
    const laravel = serverComposerLock.packages.concat(serverComposerLock['packages-dev'] || []).find((packageInfo) => packageInfo.name === 'laravel/framework');
    const files = [
        'config/security.php', 'config/privacy.php', 'routes/api.php', 'routes/web.php', 'routes/operator.php', 'routes/console.php',
        'app/Security/SecurityScanService.php', 'app/Security/Vulnerabilities/WordfenceFeed.php',
        'app/Security/Vulnerabilities/VulnerabilityScanner.php', 'app/Console/Commands/Security/SyncVulnerabilities.php',
        'app/Console/Commands/Security/ScheduleScans.php', 'app/Console/Commands/Security/MonitorScans.php',
        'app/Http/Controllers/Operator/SecurityController.php', 'app/Http/Controllers/Operator/SecurityFindingController.php',
        'app/Http/Controllers/Operator/SecurityIncidentController.php', 'tests/Feature/Security/SecurityScanTest.php',
        'tests/Feature/Security/VulnerabilityAndPostureTest.php', 'tests/Feature/Security/IncidentLifecycleTest.php',
        'tests/Feature/Security/RemediationTest.php', 'tests/Feature/AuthorizationMatrixTest.php',
        '.github/workflows/ci.yml', 'reports/m14-supply-chain/m14-6-findings.md',
    ];
    const missingFiles = [];
    for (const file of files) if (!(await rejectSymlink(path.join(repo, file)))?.isFile()) missingFiles.push(file);
    if (missingFiles.length) throw new FixtureBlockedError('BASELINE_SOURCE_MISSING');
    return {
        schema_version: 1,
        repository: { branch, commit: repositorySha },
        application: { laravel_framework: laravel?.version || null },
        wordpress: { core: corePackage?.version || null, connector_version: release.version, connector_source_commit: sourceCommit, connector_source_tree_sha256: await fileTreeSha256(connectorDir) },
        fixture: { target: 'storage/e2e/m14-7-local-fixture', environment: 'local-disposable', wordpress_http_api: 'external destinations denied; only loopback ports 8317 and 8318 allowed by fixture config', os_egress: 'not exercised; no server started by M147-A' },
        evidence_schema: 'm147-a-evidence-v1',
        security_inputs: files,
        m12_gate_keys: ['M12_DESTRUCTIVE_RETENTION_ENABLED', 'M12_PROFILE_ANONYMIZATION_ENABLED', 'M12_BACKUP_DELETION_ENABLED', 'M12_PROVIDER_DELETION_ENABLED', 'M12_BULK_RETENTION_EXECUTION_ENABLED', 'M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED'],
        m14_6_residuals: ['M146-F08: open pending adversarial log-integrity evidence', 'Single-maintainer review limitation: retained'],
        current_stage: 'M147-A only; scanner coverage NOT_RUN; no M14.7 scan claimed',
        captured_at: new Date().toISOString(),
    };
}

async function main() {
    const command = process.argv[2];
    const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
    try {
        let result;
        if (command === 'preflight') result = await preflight(root, process.argv[3] || 'local-disposable');
        else if (command === 'prepare') result = await prepareFixture(root, process.argv[3] || 'local-disposable');
        else if (command === 'cleanup') result = await cleanupFixture(root);
        else if (command === 'baseline') result = await captureBaseline(root);
        else throw new FixtureBlockedError('UNKNOWN_COMMAND');
        process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
        if (result.classification === 'BLOCKED') process.exitCode = 3;
    } catch (error) {
        const code = error instanceof FixtureBlockedError ? error.code : 'FIXTURE_OPERATION_FAILED';
        process.stderr.write(`${JSON.stringify({ classification: 'BLOCKED', reason: code })}\n`);
        process.exitCode = 3;
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
