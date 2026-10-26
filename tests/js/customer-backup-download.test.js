import test from 'node:test';
import assert from 'node:assert/strict';
import {
    CUSTOMER_DOWNLOAD_LIMITS,
    parseContentRange,
    readBoundedBody,
    selectDownloadMode,
    validateRangeResponseMetadata,
} from '../../resources/js/components/customer-backup-download.js';

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
