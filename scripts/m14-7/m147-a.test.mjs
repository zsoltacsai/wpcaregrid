import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
    FIXTURE_RELATIVE_PATH,
    FixtureBlockedError,
    allowedLocalEgress,
    cleanupFixture,
    createFakeSideEffectAdapters,
    preflight,
    prepareFixture,
    writeEvidence,
} from './m147-a.mjs';

async function makeRepo({ dependencies = true } = {}) {
    const root = await mkdtemp(path.join(os.tmpdir(), 'wpcaregrid-m147-a-'));
    const git = (args) => {
        const result = spawnSync('git', ['-C', root, ...args], { encoding: 'utf8', windowsHide: true });
        if (result.status !== 0) throw new Error(result.stderr || 'git fixture initialization failed');
    };
    git(['init', '-b', 'codex/m14-7-baseline-harness']);
    git(['config', 'user.email', 'm147-test@example.invalid']);
    git(['config', 'user.name', 'M147 synthetic test']);
    await mkdir(path.join(root, 'wordpress', 'wp-caregrid-connector'), { recursive: true });
    await writeFile(path.join(root, 'wordpress', 'wp-caregrid-connector', 'release.json'), JSON.stringify({ version: '0.4.7', package_schema: 1 }));
    await writeFile(path.join(root, 'wordpress', 'wp-caregrid-connector', 'wp-caregrid-connector.php'), '<?php\n/*\n * Version: 0.4.7\n */\n');
    await writeFile(path.join(root, 'tracked.txt'), 'synthetic');
    git(['add', '.']);
    git(['commit', '-m', 'synthetic fixture repository']);
    if (dependencies) {
        const core = path.join(root, 'wordpress', 'vendor', 'johnpbloch', 'wordpress-core');
        const sqlite = path.join(root, 'wordpress', 'vendor', 'aaemnnosttv', 'wp-sqlite-db', 'src');
        await mkdir(path.join(core, 'wp-includes'), { recursive: true });
        await mkdir(sqlite, { recursive: true });
        await writeFile(path.join(core, 'wp-load.php'), '<?php // synthetic test core');
        await writeFile(path.join(core, 'wp-includes', 'version.php'), "$wp_version = '7.1.3';\n");
        await writeFile(path.join(sqlite, 'db.php'), '<?php // synthetic sqlite drop-in');
    }
    return root;
}

test('preflight blocks staging, production, unknown, and unmarked environments', async (t) => {
    const root = await makeRepo();
    t.after(() => rm(root, { recursive: true, force: true }));
    for (const environment of ['staging', 'production', 'unknown', '']) {
        const result = await preflight(root, environment);
        assert.equal(result.classification, 'BLOCKED');
        assert.ok(result.reasons.includes('ENVIRONMENT_NOT_LOCAL_DISPOSABLE'));
    }
});

test('missing local prerequisites produce an explicit BLOCKED result without creating a fixture', async (t) => {
    const root = await makeRepo({ dependencies: false });
    t.after(() => rm(root, { recursive: true, force: true }));
    const result = await preflight(root);
    assert.equal(result.classification, 'BLOCKED');
    assert.ok(result.reasons.includes('WORDPRESS_CORE_NOT_INSTALLED'));
    assert.equal(await import('node:fs/promises').then(({ access }) => access(path.join(root, FIXTURE_RELATIVE_PATH)).then(() => true, () => false)), false);
});

test('prepare is idempotent and cleanup removes only the marked fixture', async (t) => {
    const root = await makeRepo();
    t.after(() => rm(root, { recursive: true, force: true }));
    const first = await prepareFixture(root);
    const second = await prepareFixture(root);
    assert.equal(first.classification, 'READY');
    assert.equal(second.reused, true);
    assert.equal(second.target_id, first.target_id);
    const target = path.join(root, FIXTURE_RELATIVE_PATH);
    const marker = JSON.parse(await readFile(path.join(target, '.wpcaregrid-m147-a-fixture.json'), 'utf8'));
    assert.equal(marker.environment, 'local-disposable');
    assert.match(marker.synthetic_domain, /\.example\.invalid$/);
    assert.equal(marker.connector_version, '0.4.7');
    const wpConfig = await readFile(path.join(target, 'wp-config.php'), 'utf8');
    assert.match(wpConfig, /WP_HTTP_BLOCK_EXTERNAL', true/);
    assert.match(wpConfig, /M147_SYNTHETIC_TARGET_ID/);
    const muPlugin = await readFile(path.join(target, 'wordpress', 'wp-content', 'mu-plugins', 'm147-local-http-only.php'), 'utf8');
    assert.match(muPlugin, /m147_external_http_blocked/);
    assert.doesNotMatch(muPlugin, /https?:\/\/[^\s'"`]+/i);

    const unrelated = path.join(root, 'storage', 'e2e', 'keep-me.txt');
    await writeFile(unrelated, 'unrelated synthetic sentinel');
    assert.deepEqual(await cleanupFixture(root), { classification: 'PASS', removed: true, reason: 'OWNED_FIXTURE_REMOVED' });
    assert.equal(await readFile(unrelated, 'utf8'), 'unrelated synthetic sentinel');
    assert.deepEqual(await cleanupFixture(root), { classification: 'PASS', removed: false, reason: 'ALREADY_ABSENT' });
});

test('setup and cleanup refuse unmarked or mismarked targets', async (t) => {
    const root = await makeRepo();
    t.after(() => rm(root, { recursive: true, force: true }));
    const target = path.join(root, FIXTURE_RELATIVE_PATH);
    await mkdir(target, { recursive: true });
    await writeFile(path.join(target, 'important.txt'), 'never delete');
    await assert.rejects(prepareFixture(root), (error) => error instanceof FixtureBlockedError && error.code === 'UNMARKED_FIXTURE_PATH_REFUSED');
    await assert.rejects(cleanupFixture(root), (error) => error instanceof FixtureBlockedError && error.code === 'UNMARKED_FIXTURE_PATH_REFUSED');
    assert.equal(await readFile(path.join(target, 'important.txt'), 'utf8'), 'never delete');
});

test('setup and cleanup refuse a fixture path redirected through a symlink', async (t) => {
    const root = await makeRepo();
    t.after(() => rm(root, { recursive: true, force: true }));
    const target = path.join(root, FIXTURE_RELATIVE_PATH);
    const unrelated = path.join(root, 'unrelated');
    await mkdir(path.dirname(target), { recursive: true });
    await mkdir(unrelated);
    await writeFile(path.join(unrelated, 'keep.txt'), 'unrelated synthetic sentinel');
    await symlink(unrelated, target, process.platform === 'win32' ? 'junction' : 'dir');
    await assert.rejects(prepareFixture(root), /SYMLINK_PATH_REJECTED/);
    await assert.rejects(cleanupFixture(root), /SYMLINK_PATH_REJECTED/);
    assert.equal(await readFile(path.join(unrelated, 'keep.txt'), 'utf8'), 'unrelated synthetic sentinel');
});

test('synthetic identities and fake queues/storage stay in memory and reject destructive operations', async () => {
    const adapters = createFakeSideEffectAdapters();
    adapters.queue.dispatch('m147.synthetic.incident', { fixture: 'synthetic-only' });
    adapters.storage.put('synthetic/evidence.json', '{"ok":true}');
    assert.equal(adapters.queue.snapshot().length, 1);
    assert.equal(adapters.storage.snapshot().size, 1);
    assert.throws(() => adapters.queue.dispatch('App\\Jobs\\RealBackup'), /NON_SYNTHETIC_JOB_REJECTED/);
    assert.throws(() => adapters.destructive.delete(), /DESTRUCTIVE_OPERATION_NOT_SUPPORTED/);
    assert.throws(() => adapters.destructive.restore(), /DESTRUCTIVE_OPERATION_NOT_SUPPORTED/);
    assert.throws(() => adapters.storage.put('../outside', 'x'), /UNSAFE_SYNTHETIC_STORAGE_KEY/);
});

test('local egress allowlist permits only explicit loopback fixture destinations', () => {
    assert.equal(allowedLocalEgress('http://127.0.0.1:8317/api/v1/connector'), true);
    assert.equal(allowedLocalEgress('http://localhost:8318/wp-json/'), true);
    for (const url of [
        'https://127.0.0.1:8317/',
        'http://127.0.0.1:8080/',
        'http://169.254.169.254/latest/meta-data/',
        'http://example.com/',
        'http://user:pass@localhost:8317/',
        'not a url',
    ]) assert.equal(allowedLocalEgress(url), false, url);
});

test('evidence output is allowlisted, bounded, and rejects secret/control injection', async (t) => {
    const root = await makeRepo();
    t.after(() => rm(root, { recursive: true, force: true }));
    const { target_id: targetId } = await prepareFixture(root);
    const target = path.join(root, FIXTURE_RELATIVE_PATH);
    const marker = JSON.parse(await readFile(path.join(target, '.wpcaregrid-m147-a-fixture.json'), 'utf8'));
    const record = {
        schema_version: 1,
        test_id: 'M147A.SAFETY.01',
        scope: 'fixture setup and cleanup',
        repository_sha: marker.repository_sha,
        connector_version: '0.4.7',
        connector_tree_sha256: marker.connector_source_tree_sha256,
        target_id: targetId,
        environment: 'local-disposable',
        executed_at: new Date().toISOString(),
        expected: 'PASS',
        actual: 'PASS',
        classification: 'PASS',
        scanner_coverage: 'NOT_RUN',
        cleanup: 'VERIFIED',
        ci_run_id: null,
        ci_run_url: null,
    };
    const output = await writeEvidence(root, targetId, record);
    const text = await readFile(output, 'utf8');
    assert.match(text, /"scanner_coverage": "NOT_RUN"/);
    assert.doesNotMatch(text, /\u001b|\r|\n::|api[_-]?key|password|Authorization/i);
    await assert.rejects(writeEvidence(root, targetId, { ...record, scope: 'token=secret-sentinel' }), /EVIDENCE_VALUE_REJECTED/);
    await assert.rejects(writeEvidence(root, targetId, { ...record, executed_at: `${record.executed_at}\n::add-mask::synthetic` }), /EVIDENCE_VALUE_REJECTED/);
    await assert.rejects(writeEvidence(root, targetId, { ...record, injected: 'extra payload' }), /EVIDENCE_SCHEMA_REJECTED/);
    await assert.rejects(writeEvidence(root, targetId, { ...record, repository_sha: 'c'.repeat(40) }), /EVIDENCE_SOURCE_MISMATCH/);
    await assert.rejects(writeEvidence(path.join(root, 'storage'), targetId, record), /UNMARKED_FIXTURE_PATH_REFUSED/);
});

test('evidence writer refuses a symlinked evidence directory', async (t) => {
    const root = await makeRepo();
    t.after(() => rm(root, { recursive: true, force: true }));
    const { target_id: targetId } = await prepareFixture(root);
    const target = path.join(root, FIXTURE_RELATIVE_PATH);
    const marker = JSON.parse(await readFile(path.join(target, '.wpcaregrid-m147-a-fixture.json'), 'utf8'));
    const outside = path.join(root, 'outside');
    await mkdir(outside);
    await symlink(outside, path.join(target, 'evidence'), process.platform === 'win32' ? 'junction' : 'dir');
    const record = {
        schema_version: 1, test_id: 'M147A.SYMLINK.01', scope: 'evidence path guard',
        repository_sha: marker.repository_sha, connector_version: '0.4.7',
        connector_tree_sha256: marker.connector_source_tree_sha256, target_id: targetId,
        environment: 'local-disposable', executed_at: new Date().toISOString(),
        expected: 'PASS', actual: 'PASS', classification: 'PASS', scanner_coverage: 'NOT_RUN',
        cleanup: 'PENDING', ci_run_id: null, ci_run_url: null,
    };
    await assert.rejects(writeEvidence(root, targetId, record), /EVIDENCE_PATH_SYMLINK_REJECTED/);
    assert.deepEqual(await import('node:fs/promises').then(({ readdir }) => readdir(outside)), []);
});
