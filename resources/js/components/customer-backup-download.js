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

function sameObjectIdentity(state) {
    return state.root.dataset.createUrl === state.objectIdentity.createUrl
        && Number(state.root.dataset.totalSize) === state.objectIdentity.totalSize
        && String(state.root.dataset.sha256 ?? '').toLowerCase() === state.objectIdentity.sha256;
}

async function revokeGrant(state, grantId) {
    if (!grantId) return true;

    try {
        const revokeUrl = state.revokeTemplate.replace('__GRANT__', encodeURIComponent(grantId));
        const response = await fetch(revokeUrl, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': state.csrfToken },
        });
        if (!response.ok) return false;

        return (await parseJson(response)).status === 'revoked';
    } catch {
        return false;
    }
}

async function revokePendingGrant(state) {
    if (!state.pendingRevokeGrantId) return true;
    if (!await revokeGrant(state, state.pendingRevokeGrantId)) return false;

    state.pendingRevokeGrantId = null;

    return true;
}

async function discardDiskWriter(state) {
    const writer = state.writer;
    state.writer = null;
    state.handle = null;
    if (!writer?.abort) return;

    try {
        await writer.abort();
    } catch { /* A failed write or prior abort can leave the writer already closed. */ }
}

function resetTransfer(state) {
    state.offset = 0;
    state.parts = [];
    state.rateLimitRetries = 0;
    if (state.progress) state.progress.value = 0;
    if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
    state.objectUrl = null;
}

function clearCurrentGrant(state) {
    state.grantId = null;
    state.statusUrl = null;
    state.contentUrl = null;
    state.grantStatus = null;
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
    if (!sameObjectIdentity(state)) throw apiError('OBJECT_IDENTITY_CHANGED');

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
        if (!sameObjectIdentity(state)) throw apiError('OBJECT_IDENTITY_CHANGED');
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
            try {
                await state.writer.write({ type: 'write', position: start, data: bytes });
            } catch {
                if (state.abortController.signal.aborted) throw new DOMException('Aborted', 'AbortError');
                throw apiError('FILE_WRITER_FAILED');
            }
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

export function bindDownload(root) {
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
        objectIdentity: Object.freeze({
            createUrl: root.dataset.createUrl,
            totalSize: size,
            sha256: String(root.dataset.sha256 ?? '').toLowerCase(),
        }),
        mode,
        offset: 0,
        grantId: null,
        statusUrl: null,
        contentUrl: null,
        grantStatus: null,
        handle: null,
        writer: null,
        pendingRevokeGrantId: null,
        parts: [],
        requestTimes: [],
        objectUrl: null,
        abortController: null,
        active: false,
        activePromise: null,
        cancelInProgress: false,
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
        if (refs.active || refs.cancelInProgress) return;
        refs.retryButton.textContent = refs.messages.retry;
        refs.active = true;
        refs.rateLimitRetries = 0;
        refs.pickerCancelled = false;
        refs.abortController = new AbortController();
        refs.startButton.disabled = true;
        setVisible(refs.startButton, false);
        setVisible(refs.retryButton, false);
        setVisible(refs.progress, true);
        setVisible(refs.cancelButton, Boolean(refs.grantId));

        try {
            if (refs.mode === 'disk' && !refs.writer) {
                // Must run directly from the user gesture before network work.
                try {
                    if (!refs.handle) refs.handle = await window.showSaveFilePicker({ suggestedName: refs.filename });
                    refs.writer = await refs.handle.createWritable();
                } catch (error) {
                    if (error?.name === 'AbortError') {
                        refs.pickerCancelled = true;
                        throw error;
                    }
                    throw apiError('FILE_WRITER_FAILED');
                }
            }

            if (!await revokePendingGrant(refs)) throw apiError('REVOCATION_UNCONFIRMED');

            await createOrResumeGrant(refs);
            await transferRanges(refs);
            if (refs.mode === 'disk') {
                try {
                    await refs.writer.close();
                } catch {
                    throw apiError('FILE_WRITER_FAILED');
                }
                refs.writer = null;
            } else {
                showBlobDownload(refs);
            }
            refs.status.textContent = refs.mode === 'disk'
                ? refs.messages.completeDisk
                : refs.messages.completeBlob;
            setVisible(refs.progress, false);
            setVisible(refs.cancelButton, false);
            setVisible(refs.retryButton, false);
        } catch (error) {
            if (error?.name === 'AbortError') {
                if (refs.pickerCancelled) {
                    refs.status.textContent = refs.messages.pickerCancelled;
                    setVisible(refs.progress, false);
                    setVisible(refs.cancelButton, false);
                    setVisible(refs.retryButton, false);
                    setVisible(refs.startButton, true);
                } else if (!refs.cancelling) {
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
            } else if (error?.code === 'FILE_WRITER_FAILED') {
                refs.abortController.abort();
                await discardDiskWriter(refs);
                resetTransfer(refs);
                if (refs.grantId) refs.pendingRevokeGrantId = refs.grantId;
                clearCurrentGrant(refs);
                refs.retryButton.textContent = refs.messages.restartAction;
                refs.status.textContent = await revokePendingGrant(refs)
                    ? refs.messages.writerFailed
                    : refs.messages.writerFailedRevokeFailed;
                setVisible(refs.retryButton, true);
            } else if (error?.code === 'REVOCATION_UNCONFIRMED') {
                if (refs.mode === 'disk') await discardDiskWriter(refs);
                refs.retryButton.textContent = refs.messages.restartAction;
                refs.status.textContent = refs.messages.revokeUnconfirmed;
                setVisible(refs.retryButton, true);
            } else if (error?.code === 'OBJECT_IDENTITY_CHANGED') {
                refs.status.textContent = fixedErrorMessage(refs, error.code);
                setVisible(refs.retryButton, false);
                setVisible(refs.startButton, false);
                setVisible(refs.cancelButton, false);
            } else {
                refs.status.textContent = error instanceof TypeError
                    ? refs.messages.interrupted
                    : fixedErrorMessage(refs, error?.code);
                setVisible(refs.retryButton, true);
            }
            setVisible(refs.cancelButton, Boolean(refs.grantId));
        } finally {
            refs.active = false;
            refs.cancelling = false;
            refs.startButton.disabled = false;
        }
    }

    const begin = () => {
        const attempt = start();
        refs.activePromise = attempt;

        return attempt;
    };

    refs.startButton.addEventListener('click', begin);
    refs.retryButton.addEventListener('click', begin);
    refs.cancelButton.addEventListener('click', async () => {
        if (refs.cancelInProgress) return;
        refs.cancelInProgress = true;
        refs.cancelButton.disabled = true;
        refs.startButton.disabled = true;
        refs.retryButton.disabled = true;

        if (refs.active) {
            refs.cancelling = true;
            refs.abortController?.abort();
        }

        try {
            await discardDiskWriter(refs);
            if (refs.activePromise) await refs.activePromise;
            if (refs.grantId) refs.pendingRevokeGrantId = refs.grantId;
            clearCurrentGrant(refs);
            resetTransfer(refs);
            refs.retryButton.textContent = refs.messages.retry;
            refs.status.textContent = refs.pendingRevokeGrantId
                ? (await revokePendingGrant(refs) ? refs.messages.cancelled : refs.messages.cancelRevokeFailed)
                : refs.messages.cancelledUnconfirmed;
        } finally {
            refs.cancelling = false;
            refs.cancelInProgress = false;
            refs.cancelButton.disabled = false;
            refs.retryButton.disabled = false;
        }

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
