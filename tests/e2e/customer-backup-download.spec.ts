import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import { mkdir, readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

type SyntheticState = {
    creates: number;
    revokes: number;
    requests: number[];
};

const states = new Map<string, SyntheticState>();
let server: Server;
let baseUrl: string;
const evidenceDirectory = resolve(process.cwd(), 'storage/e2e/backup-download/evidence');

async function captureEvidence(page: Page, name: string): Promise<void> {
    await mkdir(evidenceDirectory, { recursive: true });
    await page.screenshot({ path: resolve(evidenceDirectory, `${name}.png`), fullPage: true });
}

function stateFor(scenario: string): SyntheticState {
    if (!states.has(scenario)) states.set(scenario, { creates: 0, revokes: 0, requests: [] });

    return states.get(scenario)!;
}

function sendJson(response: ServerResponse, status: number, body: unknown): void {
    const bytes = Buffer.from(JSON.stringify(body));
    response.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': String(bytes.length) });
    response.end(bytes);
}

function scenarioFromPath(pathname: string): { scenario: string; action: string } | null {
    const match = /^\/api\/([a-z-]+)\/(create|content|status|grant-\d+)$/.exec(pathname);
    return match ? { scenario: match[1], action: match[2] } : null;
}

async function handleRequest(request: IncomingMessage, response: ServerResponse): Promise<void> {
    const url = new URL(request.url ?? '/', baseUrl);
    const scenario = url.searchParams.get('scenario') ?? 'blob';

    if (url.pathname === '/') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(`<!doctype html><html><head><meta charset="utf-8"><title>Synthetic backup download</title></head><body>
            <main data-customer-backup-download data-create-url="/api/${scenario}/create" data-revoke-url-template="/api/${scenario}/__GRANT__" data-csrf-token="synthetic" data-filename="synthetic-backup.bin" data-total-size="4" data-sha256="${'a'.repeat(64)}" data-range-limit="2">
                <button type="button" data-download-start>Start</button>
                <button type="button" data-download-retry hidden>Retry</button>
                <button type="button" data-download-cancel hidden>Cancel</button>
                <progress data-download-progress max="4" value="0" hidden></progress>
                <p data-download-status role="status" aria-live="polite"></p>
                <p data-download-limit hidden></p>
                <a data-download-save hidden>Save file</a>
                <div hidden>
                    ${Object.entries({
                        preparing: 'preparing', smallFileOnly: 'small', unsupported: 'unsupported', browserLimit: 'browser limit',
                        diskLimit: 'disk limit', preparationSlow: 'slow', ready: 'ready', downloading: 'downloading :percent',
                        waiting: 'waiting :seconds', interrupted: 'interrupted', pickerCancelled: 'picker cancelled; start again', completeDisk: 'complete to disk',
                        completeBlob: 'complete blob', cancelled: 'cancelled, revoked', cancelledUnconfirmed: 'cancelled, grant unconfirmed',
                        cancelRevokeFailed: 'cancelled, revoke failed', writerFailed: 'writer failed; restart',
                        writerFailedRevokeFailed: 'writer failed; revoke failed', revokeUnconfirmed: 'revoke unconfirmed',
                        restartAction: 'Restart from beginning', retry: 'Retry', expired: 'expired', revoked: 'revoked',
                        failed: 'failed', 'default-error': 'default error',
                    }).map(([key, value]) => `<span data-download-message="${key}">${value}</span>`).join('')}
                </div>
                <div hidden>
                    ${Object.entries({
                        OBJECT_IDENTITY_CHANGED: 'object identity changed', REVOCATION_UNCONFIRMED: 'revocation unconfirmed',
                        FILE_WRITER_FAILED: 'writer failed', RANGE_INVALID: 'invalid content range', GRANT_EXPIRED: 'grant expired',
                    }).map(([key, value]) => `<span data-download-error="${key}">${value}</span>`).join('')}
                </div>
            </main>
            <script type="module">
                import { bindDownload } from '/customer-backup-download.js';
                bindDownload(document.querySelector('[data-customer-backup-download]'));
            </script>
        </body></html>`);

        return;
    }

    if (url.pathname === '/customer-backup-download.js') {
        const source = await readFile(resolve(process.cwd(), 'resources/js/components/customer-backup-download.js'));
        response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Content-Length': String(source.length) });
        response.end(source);

        return;
    }

    const route = scenarioFromPath(url.pathname);
    if (!route) {
        response.writeHead(404).end();

        return;
    }

    const state = stateFor(route.scenario);
    if (route.action === 'create' && request.method === 'POST') {
        state.creates++;
        const grant = `grant-${state.creates}`;
        sendJson(response, 200, {
            grant, status: 'ready', status_url: `/api/${route.scenario}/status?grant=${grant}`,
            content_url: `/api/${route.scenario}/content?grant=${grant}`,
        });

        return;
    }

    if (route.action.startsWith('grant-') && request.method === 'DELETE') {
        state.revokes++;
        if (route.scenario === 'failed-revoke' && state.revokes <= 2) {
            sendJson(response, 503, { status: 'unavailable' });

            return;
        }
        sendJson(response, 200, { status: 'revoked' });

        return;
    }

    if (route.action === 'status') {
        sendJson(response, 200, { status: 'ready', content_url: `/api/${route.scenario}/content?grant=${url.searchParams.get('grant')}` });

        return;
    }

    if (route.action === 'content' && request.method === 'GET') {
        const range = /^bytes=(\d+)-(\d+)$/.exec(String(request.headers.range ?? ''));
        if (!range) {
            sendJson(response, 416, { code: 'RANGE_INVALID' });

            return;
        }
        const start = Number(range[1]);
        const end = Number(range[2]);
        const grant = url.searchParams.get('grant') ?? '';
        state.requests.push(start);

        if (route.scenario === 'expired' && start === 2 && grant === 'grant-1') {
            sendJson(response, 410, { code: 'GRANT_EXPIRED' });

            return;
        }

        if (['cancel', 'failed-revoke'].includes(route.scenario) && state.requests.length === 1) {
            response.writeHead(206, {
                'Content-Range': `bytes ${start}-${end}/4`, 'Content-Length': String(end - start + 1),
                ETag: `"${'a'.repeat(64)}"`, 'Content-Type': 'application/octet-stream',
            });
            request.on('close', () => response.destroy());

            return;
        }

        if (route.scenario === 'invalid-range') {
            const bytes = Buffer.from([start, start + 1]);
            response.writeHead(206, {
                'Content-Range': `bytes ${start + 1}-${end + 1}/4`, 'Content-Length': String(bytes.length),
                ETag: `"${'a'.repeat(64)}"`, 'Content-Type': 'application/octet-stream',
            });
            response.end(bytes);

            return;
        }

        const bytes = Buffer.from(Array.from({ length: end - start + 1 }, (_, index) => start + index));
        response.writeHead(206, {
            'Content-Range': `bytes ${start}-${end}/4`, 'Content-Length': String(bytes.length),
            ETag: `"${'a'.repeat(64)}"`, 'Content-Type': 'application/octet-stream',
        });
        response.end(bytes);

        return;
    }

    response.writeHead(405).end();
}

async function installDiskWriterStub(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const state = {
            pickerCalls: 0, writableCalls: 0, writes: [] as Array<{ writer: number; position: number; bytes: number }>,
            files: {} as Record<number, number[]>, aborted: [] as number[], closed: [] as number[],
        };
        (window as typeof window & { __downloadDisk: typeof state }).__downloadDisk = state;
        window.showSaveFilePicker = async () => {
            state.pickerCalls++;
            if (location.search.includes('picker-cancel')) throw new DOMException('Synthetic picker cancellation', 'AbortError');
            const writerId = state.writableCalls++;
            const data = new Uint8Array(4);
            let writeFailed = false;

            return {
                async createWritable() {
                    return {
                        async write(command: { position: number; data: ArrayBuffer }) {
                            const bytes = new Uint8Array(command.data);
                            state.writes.push({ writer: writerId, position: command.position, bytes: bytes.length });
                            if (location.search.includes('writer-failure') && writerId === 0 && !writeFailed) {
                                writeFailed = true;
                                throw new Error('synthetic writer failure');
                            }
                            if (location.search.includes('identity-change') && command.position === 0) {
                                await new Promise((resolve) => setTimeout(resolve, 350));
                            }
                            data.set(bytes, command.position);
                        },
                        async close() {
                            state.closed.push(writerId);
                            state.files[writerId] = Array.from(data);
                        },
                        async abort() { state.aborted.push(writerId); },
                    };
                },
            } as unknown as FileSystemFileHandle;
        };
    });
}

async function installPartialStreamFailure(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const nativeFetch = window.fetch.bind(window);
        let injected = false;
        window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
            const response = await nativeFetch(input, init);
            if (injected || new Headers(init?.headers).get('Range') !== 'bytes=2-3') return response;
            injected = true;

            const reader = response.body!.getReader();
            const { value } = await reader.read();
            await reader.cancel();
            const partial = value!.slice(0, 1);
            const body = new ReadableStream<Uint8Array>({
                start(controller) {
                    controller.enqueue(partial);
                    controller.error(new TypeError('synthetic response stream interrupted'));
                },
            });

            return new Response(body, { status: response.status, statusText: response.statusText, headers: response.headers });
        };
    });
}

async function openScenario(page: Page, scenario: string, disk = true): Promise<void> {
    if (disk) await installDiskWriterStub(page);
    else {
        await page.addInitScript(() => {
            Object.defineProperty(window, 'showSaveFilePicker', { value: undefined, configurable: true });
        });
    }
    if (scenario === 'partial-retry') await installPartialStreamFailure(page);
    await page.goto(`${baseUrl}/?scenario=${scenario}`);
}

test.beforeAll(async () => {
    server = createServer((request, response) => {
        void handleRequest(request, response).catch(() => response.destroy());
    });
    await new Promise<void>((resolveListen, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => resolveListen());
    });
    const address = server.address();
    if (!address || typeof address === 'string') throw new Error('Synthetic browser server failed to bind.');
    baseUrl = `http://127.0.0.1:${address.port}`;
});

test.afterAll(async () => {
    await new Promise<void>((resolveClose, reject) => server.close((error) => error ? reject(error) : resolveClose()));
});

test.beforeEach(() => states.clear());

test('real browser Blob fallback completes only after a user click and saves exact synthetic bytes', async ({ page }) => {
    await openScenario(page, 'blob', false);
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('complete blob');
    const link = page.locator('[data-download-save]');
    await expect(link).toBeVisible();
    const downloadPromise = page.waitForEvent('download');
    await link.click();
    const download = await downloadPromise;
    const path = await download.path();
    expect(path).toBeTruthy();
    expect(Array.from(await readFile(path!))).toEqual([0, 1, 2, 3]);
    await captureEvidence(page, 'blob-download-complete');
});

test('canceling the native-picker promise restores Start without creating a grant', async ({ page }) => {
    await openScenario(page, 'picker-cancel');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('picker cancelled; start again');
    await expect(page.getByRole('button', { name: 'Start' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retry' })).toBeHidden();
    expect(stateFor('picker-cancel').creates).toBe(0);
    const disk = await page.evaluate(() => (window as typeof window & { __downloadDisk: { writes: unknown[]; pickerCalls: number } }).__downloadDisk);
    expect(disk.pickerCalls).toBe(1);
    expect(disk.writes).toEqual([]);
    await captureEvidence(page, 'picker-cancelled-no-grant');
});

test('completed first range plus partial second response retries only the incomplete range', async ({ page }) => {
    await openScenario(page, 'partial-retry');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('interrupted');
    await expect.poll(() => page.evaluate(() => (window as typeof window & { __downloadDisk: { writes: Array<{position: number}> } }).__downloadDisk.writes.map((write) => write.position))).toEqual([0]);
    await captureEvidence(page, 'partial-second-range-interrupted');

    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('complete to disk');
    const disk = await page.evaluate(() => (window as typeof window & { __downloadDisk: { writes: Array<{writer: number; position: number; bytes: number}>; files: Record<number, number[]> } }).__downloadDisk);
    expect(disk.writes).toEqual([{ writer: 0, position: 0, bytes: 2 }, { writer: 0, position: 2, bytes: 2 }]);
    expect(disk.files[0]).toEqual([0, 1, 2, 3]);
    expect(stateFor('partial-retry').requests).toEqual([0, 2, 2]);
    await captureEvidence(page, 'partial-second-range-retried');
});

test('Cancel then Start obtains a fresh writer and downloads from byte zero', async ({ page }) => {
    await openScenario(page, 'cancel');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect.poll(() => stateFor('cancel').requests.length).toBe(1);
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('cancelled, revoked');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('complete to disk');

    const disk = await page.evaluate(() => (window as typeof window & { __downloadDisk: { pickerCalls: number; writableCalls: number; writes: Array<{writer: number; position: number}>; files: Record<number, number[]> } }).__downloadDisk);
    expect(disk.pickerCalls).toBe(2);
    expect(disk.writableCalls).toBe(2);
    expect(disk.writes.map((write) => [write.writer, write.position])).toEqual([[1, 0], [1, 2]]);
    expect(disk.files[1]).toEqual([0, 1, 2, 3]);
    expect(stateFor('cancel').requests).toEqual([0, 0, 2]);
    await captureEvidence(page, 'cancel-fresh-start');
});

test('expired grant is replaced and resumes after the completed range on the same writer', async ({ page }) => {
    await openScenario(page, 'expired');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('expired');
    await page.getByRole('button', { name: 'Retry' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('complete to disk');

    const disk = await page.evaluate(() => (window as typeof window & { __downloadDisk: { writableCalls: number; writes: Array<{writer: number; position: number}>; files: Record<number, number[]> } }).__downloadDisk);
    expect(disk.writableCalls).toBe(1);
    expect(disk.writes.map((write) => [write.writer, write.position])).toEqual([[0, 0], [0, 2]]);
    expect(disk.files[0]).toEqual([0, 1, 2, 3]);
    expect(stateFor('expired').requests).toEqual([0, 2, 2]);
    expect(stateFor('expired').creates).toBe(2);
    await captureEvidence(page, 'expired-grant-resumed');
});

test('writer failure forces a new picker and a byte-zero restart', async ({ page }) => {
    await openScenario(page, 'writer-failure');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('writer failed; restart');
    await page.getByRole('button', { name: 'Restart from beginning' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('complete to disk');

    const disk = await page.evaluate(() => (window as typeof window & { __downloadDisk: { pickerCalls: number; writes: Array<{writer: number; position: number}>; files: Record<number, number[]>; aborted: number[] } }).__downloadDisk);
    expect(disk.pickerCalls).toBe(2);
    expect(disk.aborted).toContain(0);
    expect(disk.writes.map((write) => [write.writer, write.position])).toEqual([[0, 0], [1, 0], [1, 2]]);
    expect(disk.files[1]).toEqual([0, 1, 2, 3]);
    expect(stateFor('writer-failure').requests).toEqual([0, 0, 2]);
    await captureEvidence(page, 'writer-failure-restart');
});

test('failed revoke is not shown as success and blocks a replacement grant until confirmed', async ({ page }) => {
    await openScenario(page, 'failed-revoke');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect.poll(() => stateFor('failed-revoke').requests.length).toBe(1);
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('cancelled, revoke failed');
    await captureEvidence(page, 'revoke-not-confirmed');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('revoke unconfirmed');
    expect(stateFor('failed-revoke').creates).toBe(1);

    await page.getByRole('button', { name: 'Restart from beginning' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('complete to disk');
    expect(stateFor('failed-revoke').creates).toBe(2);
    await captureEvidence(page, 'revoke-retried-and-download-complete');
});

test('invalid Content-Range fails closed without writing response bytes', async ({ page }) => {
    await openScenario(page, 'invalid-range');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.locator('[data-download-status]')).toHaveText('invalid content range');
    const disk = await page.evaluate(() => (window as typeof window & { __downloadDisk: { writes: unknown[] } }).__downloadDisk);
    expect(disk.writes).toEqual([]);
    await captureEvidence(page, 'invalid-range-failed-closed');
});

test('object identity change after a completed range prevents any subsequent range', async ({ page }) => {
    await openScenario(page, 'identity-change');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect.poll(() => page.evaluate(() => (window as typeof window & { __downloadDisk: { writes: Array<{position: number}> } }).__downloadDisk.writes.length)).toBe(1);
    await page.locator('[data-customer-backup-download]').evaluate((root) => { (root as HTMLElement).dataset.sha256 = 'b'.repeat(64); });
    await expect(page.locator('[data-download-status]')).toHaveText('object identity changed');
    expect(stateFor('identity-change').requests).toEqual([0]);
    await captureEvidence(page, 'object-identity-change-failed-closed');
});
