import test from 'node:test';
import assert from 'node:assert/strict';
import {
    CUSTOMER_DOWNLOAD_LIMITS,
    bindDownload,
    parseContentRange,
    readBoundedBody,
    selectDownloadMode,
    validateRangeResponseMetadata,
} from '../../resources/js/components/customer-backup-download.js';

function makeDownloadFixture(t, overrides = {}) {
    const originalWindow = globalThis.window;
    const originalFetch = globalThis.fetch;
    const stats = { pickerCalls: 0, writableCalls: 0, writes: [], aborted: 0, closed: 0, creates: 0, revokes: 0, ranges: [] };
    const root = {
        dataset: {
            createUrl: '/download/create', revokeUrlTemplate: '/download/__GRANT__', csrfToken: 'csrf',
            filename: 'backup.bin', totalSize: '4', sha256: 'a'.repeat(64), rangeLimit: '2',
        },
        children: {},
        querySelector(selector) { return this.children[selector]; },
        querySelectorAll(selector) {
            if (selector === '[data-customer-backup-download]') return [this];
            if (selector === '[data-download-message]') return Object.entries({
                preparing: 'preparing', smallFileOnly: 'small', unsupported: 'unsupported', browserLimit: 'browser limit',
                diskLimit: 'disk limit', preparationSlow: 'preparation slow', ready: 'ready', downloading: 'downloading :percent',
                waiting: 'waiting :seconds', interrupted: 'interrupted', complete: 'complete', cancelled: 'revoked confirmed',
                cancelledUnconfirmed: 'cancelled, grant unconfirmed', cancelRevokeFailed: 'cancelled, revoke failed',
                writerFailed: 'writer failed; restart', writerFailedRevokeFailed: 'writer failed; revoke failed',
                revokeUnconfirmed: 'revoke unconfirmed', restartAction: 'Restart from beginning', retry: 'Retry',
                expired: 'expired', revoked: 'revoked', failed: 'failed', 'default-error': 'default error',
            }).map(([downloadMessage, textContent]) => ({ dataset: { downloadMessage }, textContent }));
            if (selector === '[data-download-error]') return Object.entries({
                OBJECT_IDENTITY_CHANGED: 'object changed', REVOCATION_UNCONFIRMED: 'revoke unconfirmed',
                FILE_WRITER_FAILED: 'writer failed',
            }).map(([downloadError, textContent]) => ({ dataset: { downloadError }, textContent }));
            return [];
        },
    };
    for (const [name, selector] of Object.entries({
        startButton: '[data-download-start]', retryButton: '[data-download-retry]', cancelButton: '[data-download-cancel]',
        progress: '[data-download-progress]', status: '[data-download-status]', limit: '[data-download-limit]', saveLink: '[data-download-save]',
    })) {
        root.children[selector] = {
            hidden: name === 'retryButton' || name === 'cancelButton' || name === 'progress' || name === 'limit' || name === 'saveLink',
            disabled: false, value: 0, textContent: '', listeners: {},
            addEventListener(type, callback) { this.listeners[type] = callback; },
            click() { return this.listeners.click?.(); },
        };
    }

    const makeWriter = () => ({
        async write(command) {
            if (overrides.write) return overrides.write(command, stats);
            stats.writes.push({ position: command.position, bytes: command.data.byteLength, writer: stats.writableCalls });
        },
        async close() { stats.closed++; },
        async abort() { stats.aborted++; },
    });
    globalThis.window = {
        isSecureContext: true,
        async showSaveFilePicker() {
            stats.pickerCalls++;
            return { async createWritable() { stats.writableCalls++; return makeWriter(); } };
        },
    };
    globalThis.fetch = async (url, options = {}) => {
        if (options.method === 'POST') {
            stats.creates++;
            return new Response(JSON.stringify({
                grant: `grant-${stats.creates}`, status: 'ready', status_url: `/status/${stats.creates}`, content_url: `/content/${stats.creates}`,
            }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        if (options.method === 'DELETE') {
            stats.revokes++;
            return overrides.revoke ? overrides.revoke(url, options, stats) : new Response(JSON.stringify({ status: 'revoked' }), { status: 200 });
        }
        const range = options.headers?.Range ?? '';
        const start = Number(/^bytes=(\d+)-/.exec(range)?.[1] ?? -1);
        stats.ranges.push(start);
        if (overrides.content) return overrides.content(start, url, options, stats);
        return rangeResponse(start);
    };
    bindDownload(root);
    t.after(() => {
        globalThis.window = originalWindow;
        globalThis.fetch = originalFetch;
    });

    return { root, stats, ...root.children };
}

function rangeResponse(start) {
    const end = Math.min(3, start + 1);
    const length = end - start + 1;
    return new Response(Uint8Array.from({ length }, (_, index) => start + index), {
        status: 206,
        headers: {
            'Content-Range': `bytes ${start}-${end}/4`, 'Content-Length': String(length), ETag: `"${'a'.repeat(64)}"`,
        },
    });
}

test('download mode is bounded for direct-to-disk, small-file and unsupported browsers', () => {
    assert.equal(selectDownloadMode(CUSTOMER_DOWNLOAD_LIMITS.diskBytes, true), 'disk');
    assert.equal(selectDownloadMode(CUSTOMER_DOWNLOAD_LIMITS.diskBytes + 1, true), 'unsupported');
    assert.equal(selectDownloadMode(CUSTOMER_DOWNLOAD_LIMITS.blobBytes, false), 'blob');
    assert.equal(selectDownloadMode(CUSTOMER_DOWNLOAD_LIMITS.blobBytes + 1, false), 'unsupported');
    assert.equal(selectDownloadMode(0, true), 'unsupported');
    assert.equal(selectDownloadMode(Number.MAX_SAFE_INTEGER + 1, true), 'unsupported');
    assert.equal(CUSTOMER_DOWNLOAD_LIMITS.rangeBytes, 8 * 1024 * 1024);
});

test('response body reader accepts exactly one bounded range and rejects short or oversized bodies', async () => {
    const response = (pieces) => new Response(new ReadableStream({
        start(controller) {
            pieces.forEach((piece) => controller.enqueue(new Uint8Array(piece)));
            controller.close();
        },
    }));

    assert.deepEqual([...new Uint8Array(await readBoundedBody(response([[1, 2], [3, 4]]), 4))], [1, 2, 3, 4]);
    await assert.rejects(readBoundedBody(response([[1, 2]]), 3), { code: 'STREAM_INCOMPLETE' });
    await assert.rejects(readBoundedBody(response([[1, 2, 3, 4, 5]]), 4), { code: 'STREAM_INCOMPLETE' });
    await assert.rejects(readBoundedBody(response([[1]]), CUSTOMER_DOWNLOAD_LIMITS.rangeBytes + 1), { code: 'STREAM_INCOMPLETE' });
});

test('content range parser rejects malformed, unsafe and inconsistent ranges', () => {
    assert.deepEqual(parseContentRange('bytes 8-15/32'), { start: 8, end: 15, total: 32, length: 8 });
    assert.equal(parseContentRange('bytes 8-15/*'), null);
    assert.deepEqual(parseContentRange('bytes 8-15/16'), { start: 8, end: 15, total: 16, length: 8 });
    assert.equal(parseContentRange('bytes 15-8/32'), null);
    assert.equal(parseContentRange('bytes 0-9007199254740992/9007199254740993'), null);
    assert.equal(parseContentRange('items 8-15/32'), null);
});

test('range response requires exact status, range, length and verified object ETag', () => {
    const expected = { start: 8, end: 15, total: 32, length: 8, sha256: 'a'.repeat(64) };
    const valid = { status: 206, contentRange: 'bytes 8-15/32', contentLength: '8', etag: `"${'a'.repeat(64)}"` };
    assert.equal(validateRangeResponseMetadata(valid, expected), null);
    assert.equal(validateRangeResponseMetadata({ ...valid, status: 200 }, expected), 'RANGE_INVALID');
    assert.equal(validateRangeResponseMetadata({ ...valid, contentRange: 'bytes 0-15/32' }, expected), 'RANGE_INVALID');
    assert.equal(validateRangeResponseMetadata({ ...valid, contentLength: null }, expected), 'RANGE_INVALID');
    assert.equal(validateRangeResponseMetadata({ ...valid, contentLength: '7' }, expected), 'RANGE_INVALID');
    assert.equal(validateRangeResponseMetadata({ ...valid, etag: 'untrusted-provider-value' }, expected), 'OBJECT_INTEGRITY_MISMATCH');
});

test('Cancel then Start aborts and revokes the old session, then opens a fresh writer at offset zero', async (t) => {
    let markContentStarted;
    const contentStarted = new Promise((resolve) => { markContentStarted = resolve; });
    let firstRequest = true;
    const fixture = makeDownloadFixture(t, {
        content(start, url, options) {
            if (firstRequest) {
                firstRequest = false;
                markContentStarted();
                return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true }));
            }
            return rangeResponse(start);
        },
    });
    const start = fixture.root.children['[data-download-start]'];
    const cancel = fixture.root.children['[data-download-cancel]'];
    const firstStart = start.click();
    await contentStarted;
    await cancel.click();
    await firstStart;

    assert.equal(fixture.stats.aborted, 1);
    assert.equal(fixture.stats.revokes, 1);
    assert.equal(fixture.stats.writes.length, 0);
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'revoked confirmed');
    assert.equal(start.hidden, false);

    await start.click();
    assert.equal(fixture.stats.pickerCalls, 2);
    assert.equal(fixture.stats.writableCalls, 2);
    assert.deepEqual(fixture.stats.ranges, [0, 0, 2]);
    assert.deepEqual(fixture.stats.writes.map(({ position }) => position), [0, 2]);
    assert.equal(fixture.stats.closed, 1);
});

test('interrupted network range retries from the same offset with the still-valid writer', async (t) => {
    let failOnce = true;
    const fixture = makeDownloadFixture(t, {
        content(start) {
            if (failOnce) {
                failOnce = false;
                throw new TypeError('network disconnected');
            }
            return rangeResponse(start);
        },
    });
    const start = fixture.root.children['[data-download-start]'];
    const retry = fixture.root.children['[data-download-retry]'];
    await start.click();
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'interrupted');
    assert.equal(retry.hidden, false);
    await retry.click();

    assert.equal(fixture.stats.pickerCalls, 1);
    assert.equal(fixture.stats.writableCalls, 1);
    assert.deepEqual(fixture.stats.ranges, [0, 0, 2]);
    assert.deepEqual(fixture.stats.writes.map(({ position }) => position), [0, 2]);
    assert.equal(fixture.stats.closed, 1);
});

test('expired grant obtains a new grant and resumes only the next range for the same object', async (t) => {
    let expiredOnce = true;
    const fixture = makeDownloadFixture(t, {
        async content(start) {
            if (start === 2 && expiredOnce) {
                expiredOnce = false;
                return new Response(JSON.stringify({ code: 'GRANT_EXPIRED' }), { status: 410, headers: { 'Content-Type': 'application/json' } });
            }
            return rangeResponse(start);
        },
    });
    const start = fixture.root.children['[data-download-start]'];
    const retry = fixture.root.children['[data-download-retry]'];
    await start.click();
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'expired');
    await retry.click();

    assert.equal(fixture.stats.creates, 2);
    assert.equal(fixture.stats.pickerCalls, 1);
    assert.deepEqual(fixture.stats.ranges, [0, 2, 2]);
    assert.deepEqual(fixture.stats.writes.map(({ position }) => position), [0, 2]);
});

test('retry refuses to continue when the page object identity changes', async (t) => {
    let failOnce = true;
    const fixture = makeDownloadFixture(t, {
        content(start) {
            if (failOnce) {
                failOnce = false;
                throw new TypeError('network disconnected');
            }
            return rangeResponse(start);
        },
    });
    const start = fixture.root.children['[data-download-start]'];
    const retry = fixture.root.children['[data-download-retry]'];
    await start.click();
    fixture.root.dataset.sha256 = 'b'.repeat(64);
    await retry.click();

    assert.equal(fixture.stats.creates, 1);
    assert.deepEqual(fixture.stats.ranges, [0]);
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'object changed');
    assert.equal(retry.hidden, true);
});

test('writer failure invalidates the old session and Restart creates a fresh file at offset zero', async (t) => {
    let failOnce = true;
    const fixture = makeDownloadFixture(t, {
        write(command, stats) {
            if (failOnce) {
                failOnce = false;
                throw new Error('disk full');
            }
            stats.writes.push({ position: command.position, bytes: command.data.byteLength, writer: stats.writableCalls });
        },
    });
    const start = fixture.root.children['[data-download-start]'];
    const retry = fixture.root.children['[data-download-retry]'];
    await start.click();
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'writer failed; restart');
    assert.equal(retry.textContent, 'Restart from beginning');
    assert.equal(fixture.stats.aborted, 1);
    assert.equal(fixture.stats.revokes, 1);
    await retry.click();

    assert.equal(fixture.stats.pickerCalls, 2);
    assert.equal(fixture.stats.writableCalls, 2);
    assert.deepEqual(fixture.stats.ranges, [0, 0, 2]);
    assert.deepEqual(fixture.stats.writes.map(({ position }) => position), [0, 2]);
    assert.equal(fixture.stats.closed, 1);
});

test('failed server revocation is never presented as successful and blocks new grant until retried', async (t) => {
    let revokeFails = true;
    let markContentStarted;
    const contentStarted = new Promise((resolve) => { markContentStarted = resolve; });
    const fixture = makeDownloadFixture(t, {
        content(start, url, options, stats) {
            if (stats.ranges.length === 1) {
                markContentStarted();
                return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true }));
            }
            return rangeResponse(start);
        },
        revoke() {
            if (revokeFails) return new Response('{}', { status: 503 });
            return new Response(JSON.stringify({ status: 'revoked' }), { status: 200 });
        },
    });
    const start = fixture.root.children['[data-download-start]'];
    const cancel = fixture.root.children['[data-download-cancel]'];
    const retry = fixture.root.children['[data-download-retry]'];
    const firstStart = start.click();
    await contentStarted;
    await cancel.click();
    await firstStart;
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'cancelled, revoke failed');
    assert.notEqual(fixture.root.children['[data-download-status]'].textContent, 'revoked confirmed');

    await start.click();
    assert.equal(fixture.stats.creates, 1, 'must confirm old grant revocation before creating another');
    assert.equal(fixture.root.children['[data-download-status]'].textContent, 'revoke unconfirmed');
    assert.equal(retry.hidden, false);

    revokeFails = false;
    await retry.click();
    assert.equal(fixture.stats.creates, 2);
    assert.equal(fixture.stats.revokes, 3);
    assert.deepEqual(fixture.stats.ranges, [0, 0, 2]);
    assert.deepEqual(fixture.stats.writes.map(({ position }) => position), [0, 2]);
});
