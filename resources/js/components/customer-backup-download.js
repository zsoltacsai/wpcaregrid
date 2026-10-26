const ABSOLUTE_RANGE_LIMIT = 8 * 1024 * 1024;
const RATE_WINDOW_MS = 60_000;
const CONTENT_REQUESTS_PER_WINDOW = 6;

export const CUSTOMER_DOWNLOAD_LIMITS = Object.freeze({
    rangeBytes: ABSOLUTE_RANGE_LIMIT,
    diskBytes: 256 * 1024 * 1024,
    blobBytes: 16 * 1024 * 1024,
});

export function selectDownloadMode(size, supportsFileSystemAccess) {
    if (!Number.isSafeInteger(size) || size < 1) return 'unsupported';
    if (supportsFileSystemAccess && size <= CUSTOMER_DOWNLOAD_LIMITS.diskBytes) return 'disk';
    if (size <= CUSTOMER_DOWNLOAD_LIMITS.blobBytes) return 'blob';

    return 'unsupported';
}

export function parseContentRange(value) {
    const match = /^bytes (\d+)-(\d+)\/(\d+)$/i.exec(value ?? '');
    if (!match) return null;

    const start = Number(match[1]);
    const end = Number(match[2]);
    const total = Number(match[3]);
    if (![start, end, total].every(Number.isSafeInteger) || start < 0 || end < start || total <= end) return null;

    return { start, end, total, length: end - start + 1 };
}

export function validateRangeResponseMetadata({ status, contentRange, contentLength, etag }, expected) {
    const parsed = parseContentRange(contentRange);
    const length = Number(contentLength);
    const normalizedEtag = String(etag ?? '').replace(/^"|"$/g, '').toLowerCase();

    if (status !== 206) return 'RANGE_INVALID';
    if (!parsed || parsed.start !== expected.start || parsed.end !== expected.end
        || parsed.total !== expected.total || parsed.length !== expected.length
        || !Number.isSafeInteger(length) || length !== expected.length) return 'RANGE_INVALID';
    if (!/^[a-f0-9]{64}$/.test(normalizedEtag) || normalizedEtag !== expected.sha256.toLowerCase()) {
        return 'OBJECT_INTEGRITY_MISMATCH';
    }

    return null;
}

export async function readBoundedBody(response, expectedLength) {
    const reader = response.body?.getReader?.();
    if (!reader || !Number.isSafeInteger(expectedLength) || expectedLength < 1 || expectedLength > ABSOLUTE_RANGE_LIMIT) {
        throw apiError('STREAM_INCOMPLETE');
    }

    const chunks = [];
    let received = 0;
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            received += value.byteLength;
            if (received > expectedLength) {
                await reader.cancel();
                throw apiError('STREAM_INCOMPLETE');
            }
            chunks.push(value);
        }
    } finally {
        reader.releaseLock();
    }
    if (received !== expectedLength) throw apiError('STREAM_INCOMPLETE');

    const bytes = new Uint8Array(received);
    let offset = 0;
    for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.byteLength;
    }

    return bytes.buffer;
}

function parseJson(response) {
    return response.json().catch(() => ({}));
}

function messagesFor(root) {
    const messages = {};
    root.querySelectorAll('[data-download-message]').forEach((element) => {
        messages[element.dataset.downloadMessage] = element.textContent.trim();
    });

    const errors = {};
    root.querySelectorAll('[data-download-error]').forEach((element) => {
        errors[element.dataset.downloadError] = element.textContent.trim();
    });

    return { messages, errors };
}

function setVisible(element, visible) {
    if (element) element.hidden = !visible;
}

function updateProgress(state, key, values = {}) {
    const template = state.messages[key] ?? state.messages.failed ?? '';
    const message = Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`:${name}`, String(value)), template);
    state.status.textContent = message;
}

function fixedErrorMessage(state, code) {
    return state.errors[code] ?? state.messages['default-error'] ?? state.messages.failed ?? '';
}

function apiError(code) {
    const error = new Error('customer_backup_download_failed');
    error.code = code || 'DOWNLOAD_UNAVAILABLE';

    return error;
}

async function jsonOrError(response) {
    const body = await parseJson(response);
    if (!response.ok) throw apiError(body.code ?? body.failure_code);

    return body;
}

function sleep(milliseconds, signal) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(resolve, milliseconds);
        signal?.addEventListener('abort', () => {
            clearTimeout(timeout);
            reject(new DOMException('Aborted', 'AbortError'));
        }, { once: true });
    });
}

async function respectContentRateLimit(state) {
    const now = Date.now();
    state.requestTimes = state.requestTimes.filter((started) => now - started < RATE_WINDOW_MS);
    if (state.requestTimes.length >= CONTENT_REQUESTS_PER_WINDOW) {
        const delay = Math.max(0, RATE_WINDOW_MS - (now - state.requestTimes[0]));
        updateProgress(state, 'waiting', { seconds: Math.ceil(delay / 1000) });
        await sleep(delay, state.abortController.signal);
        state.requestTimes = state.requestTimes.filter((started) => Date.now() - started < RATE_WINDOW_MS);
    }
    state.requestTimes.push(Date.now());
}

async function createOrResumeGrant(state) {
    if (!state.grantId) {
        const created = await jsonOrError(await fetch(state.createUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': state.csrfToken,
            },
            signal: state.abortController.signal,
        }));
        state.grantId = created.grant;
        state.statusUrl = created.status_url;
        state.contentUrl = created.content_url;
        state.grantStatus = created.status;
        setVisible(state.cancelButton, true);
    }

    const startedAt = Date.now();
    while (!state.contentUrl) {
        if (state.abortController.signal.aborted) throw new DOMException('Aborted', 'AbortError');
        if (Date.now() - startedAt > 5 * 60_000) throw apiError('PREPARATION_PENDING');
        updateProgress(state, 'preparing');
        await sleep(5_000, state.abortController.signal);
        const status = await jsonOrError(await fetch(state.statusUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: state.abortController.signal,
        }));
        state.grantStatus = status.status;
        if (status.status === 'failed') throw apiError(status.failure_code ?? 'PREPARATION_FAILED');
        if (status.status === 'revoked') throw apiError('GRANT_REVOKED');
        if (status.status === 'ready' && status.content_url) state.contentUrl = status.content_url;
        if (!['preparing', 'ready'].includes(status.status)) throw apiError('GRANT_UNAVAILABLE');
    }
}

async function transferRanges(state) {
    const rangeLimit = Math.min(ABSOLUTE_RANGE_LIMIT, Math.max(1, Number(state.root.dataset.rangeLimit) || ABSOLUTE_RANGE_LIMIT));
    const expectedSha = state.root.dataset.sha256.toLowerCase();

    while (state.offset < state.totalSize) {
        if (state.abortController.signal.aborted) throw new DOMException('Aborted', 'AbortError');
        await respectContentRateLimit(state);

        const start = state.offset;
        const end = Math.min(state.totalSize - 1, start + rangeLimit - 1);
        const expectedLength = end - start + 1;
        updateProgress(state, 'downloading', { percent: Math.floor((state.offset / state.totalSize) * 100) });

        const response = await fetch(state.contentUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/octet-stream', Range: `bytes=${start}-${end}` },
            signal: state.abortController.signal,
        });
        if (response.status === 429) {
            state.rateLimitRetries = (state.rateLimitRetries ?? 0) + 1;
            if (state.rateLimitRetries > 2) throw apiError('RATE_LIMITED');
            const retryAfter = Number(response.headers.get('Retry-After'));
            const delaySeconds = Number.isFinite(retryAfter) && retryAfter > 0 ? Math.min(60, Math.ceil(retryAfter)) : 60;
            updateProgress(state, 'waiting', { seconds: delaySeconds });
            await sleep(delaySeconds * 1000, state.abortController.signal);
            continue;
        }
        if (response.status === 410 || !response.ok) {
            const body = await parseJson(response);
            throw apiError(body.code ?? body.failure_code ?? 'DOWNLOAD_UNAVAILABLE');
        }
        if (response.status !== 206) throw apiError('RANGE_INVALID');

        const validationError = validateRangeResponseMetadata({
            status: response.status,
            contentRange: response.headers.get('Content-Range'),
            contentLength: response.headers.get('Content-Length'),
            etag: response.headers.get('ETag'),
        }, { start, end, total: state.totalSize, length: expectedLength, sha256: expectedSha });
        if (validationError) throw apiError(validationError);

        const bytes = await readBoundedBody(response, expectedLength);

        if (state.mode === 'disk') {
            await state.writer.write({ type: 'write', position: start, data: bytes });
        } else {
            state.parts.push(bytes);
        }

        state.offset += bytes.byteLength;
        state.rateLimitRetries = 0;
        state.progress.value = state.offset;
        state.progress.textContent = `${state.offset} / ${state.totalSize}`;
    }
}

function showBlobDownload(state) {
    const blob = new Blob(state.parts, { type: 'application/octet-stream' });
    state.objectUrl = URL.createObjectURL(blob);
    state.saveLink.href = state.objectUrl;
    state.saveLink.download = state.filename;
    setVisible(state.saveLink, true);
    state.parts = [];
}

function bindDownload(root) {
    const size = Number(root.dataset.totalSize);
    const supportsDisk = window.isSecureContext && typeof window.showSaveFilePicker === 'function';
    const mode = selectDownloadMode(size, supportsDisk);
    const refs = {
        root,
        createUrl: root.dataset.createUrl,
        revokeTemplate: root.dataset.revokeUrlTemplate,
        csrfToken: root.dataset.csrfToken,
        filename: root.dataset.filename,
        totalSize: size,
        mode,
        offset: 0,
        grantId: null,
        statusUrl: null,
        contentUrl: null,
        grantStatus: null,
        handle: null,
        writer: null,
        parts: [],
        requestTimes: [],
        objectUrl: null,
        abortController: null,
        active: false,
        messages: null,
        errors: null,
        startButton: root.querySelector('[data-download-start]'),
        retryButton: root.querySelector('[data-download-retry]'),
        cancelButton: root.querySelector('[data-download-cancel]'),
        progress: root.querySelector('[data-download-progress]'),
        status: root.querySelector('[data-download-status]'),
        limit: root.querySelector('[data-download-limit]'),
        saveLink: root.querySelector('[data-download-save]'),
    };
    Object.assign(refs, messagesFor(root));

    if (mode === 'unsupported') {
        refs.status.textContent = refs.messages.unsupported;
        refs.limit.textContent = supportsDisk ? refs.messages.diskLimit : refs.messages.browserLimit;
        setVisible(refs.limit, true);

        return;
    }

    refs.startButton.disabled = false;
    refs.status.textContent = mode === 'blob' ? refs.messages.smallFileOnly : refs.messages.ready;

    async function start() {
        if (refs.active) return;
        refs.active = true;
        refs.rateLimitRetries = 0;
        refs.abortController = new AbortController();
        refs.startButton.disabled = true;
        setVisible(refs.startButton, false);
        setVisible(refs.retryButton, false);
        setVisible(refs.progress, true);
        setVisible(refs.cancelButton, Boolean(refs.grantId));

        try {
            if (refs.mode === 'disk' && !refs.handle) {
                // Must run directly from the user gesture before network work.
                refs.handle = await window.showSaveFilePicker({ suggestedName: refs.filename });
                refs.writer = await refs.handle.createWritable();
            }

            await createOrResumeGrant(refs);
            await transferRanges(refs);
            if (refs.mode === 'disk') {
                await refs.writer.close();
                refs.writer = null;
            } else {
                showBlobDownload(refs);
            }
            refs.status.textContent = refs.messages.complete;
            setVisible(refs.progress, false);
            setVisible(refs.cancelButton, false);
            setVisible(refs.retryButton, false);
        } catch (error) {
            if (error?.name === 'AbortError') {
                if (!refs.cancelling) {
                    refs.status.textContent = refs.messages.interrupted;
                    setVisible(refs.retryButton, true);
                }
            } else if (error?.code === 'GRANT_EXPIRED') {
                refs.status.textContent = refs.messages.expired;
                refs.grantId = null;
                refs.statusUrl = null;
                refs.contentUrl = null;
                setVisible(refs.retryButton, true);
            } else if (error?.code === 'GRANT_REVOKED') {
                refs.status.textContent = refs.messages.revoked;
                refs.grantId = null;
                refs.statusUrl = null;
                refs.contentUrl = null;
                setVisible(refs.retryButton, true);
            } else if (error?.code === 'PREPARATION_PENDING') {
                refs.status.textContent = refs.messages.preparationSlow;
                setVisible(refs.retryButton, true);
            } else {
                refs.status.textContent = fixedErrorMessage(refs, error?.code);
                setVisible(refs.retryButton, true);
            }
            setVisible(refs.cancelButton, Boolean(refs.grantId));
        } finally {
            refs.active = false;
            refs.cancelling = false;
            refs.startButton.disabled = false;
        }
    }

    refs.startButton.addEventListener('click', start);
    refs.retryButton.addEventListener('click', start);
    refs.cancelButton.addEventListener('click', async () => {
        if (refs.active) {
            refs.cancelling = true;
            refs.abortController?.abort();
        }
        try {
            if (refs.writer?.abort) await refs.writer.abort();
        } catch { /* The in-progress file write may already have been aborted. */ }
        refs.writer = null;
        refs.parts = [];
        refs.offset = 0;
        if (refs.objectUrl) URL.revokeObjectURL(refs.objectUrl);
        refs.objectUrl = null;

        if (refs.grantId) {
            const revokeUrl = refs.revokeTemplate.replace('__GRANT__', encodeURIComponent(refs.grantId));
            await fetch(revokeUrl, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': refs.csrfToken },
            }).catch(() => {});
        }
        refs.grantId = null;
        refs.statusUrl = null;
        refs.contentUrl = null;
        refs.status.textContent = refs.messages.cancelled;
        refs.progress.value = 0;
        setVisible(refs.progress, false);
        setVisible(refs.cancelButton, false);
        setVisible(refs.retryButton, false);
        setVisible(refs.startButton, true);
        refs.startButton.disabled = false;
    });
}

export function mountCustomerBackupDownloads(root = document) {
    root.querySelectorAll('[data-customer-backup-download]').forEach(bindDownload);
}
