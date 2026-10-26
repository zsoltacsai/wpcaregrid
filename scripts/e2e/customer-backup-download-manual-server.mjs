import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const host = '127.0.0.1';
const port = Number(process.env.PORT ?? 8765);
let nextGrant = 0;
let delayedOnce = false;

function json(response, status, value) {
    const body = Buffer.from(JSON.stringify(value));
    response.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': String(body.length) });
    response.end(body);
}

const server = createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', `http://${host}:${port}`);
    if (url.pathname === '/') {
        const slow = url.searchParams.get('slow') === '1' ? '1' : '0';
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(`<!doctype html><html><head><meta charset="utf-8"><title>Synthetic backup picker QA</title></head><body>
            <main data-customer-backup-download data-create-url="/api/create?slow=${slow}" data-revoke-url-template="/api/__GRANT__" data-csrf-token="synthetic" data-filename="synthetic-backup.bin" data-total-size="4" data-sha256="${'a'.repeat(64)}" data-range-limit="2">
                <h1>Synthetic backup picker QA</h1><p>Only bytes 00 01 02 03 are served locally.</p>
                <button type="button" data-download-start>Start</button><button type="button" data-download-retry hidden>Retry</button><button type="button" data-download-cancel hidden>Cancel</button>
                <progress data-download-progress max="4" value="0" hidden></progress><p data-download-status role="status" aria-live="polite"></p><p data-download-limit hidden></p><a data-download-save hidden>Save file</a>
                <div hidden>
                    ${Object.entries({preparing:'Preparing…',smallFileOnly:'Blob fallback',unsupported:'Unsupported',browserLimit:'Browser limit',diskLimit:'Disk limit',preparationSlow:'Preparing',ready:'Ready',downloading:'Downloading :percent%',waiting:'Wait :seconds',interrupted:'Interrupted',pickerCancelled:'No save location selected. Choose Start to try again.',completeDisk:'Saved to selected location',completeBlob:'Ready; use browser download prompt',cancelled:'Cancelled; grant revoked',cancelledUnconfirmed:'Cancelled; revoke unconfirmed',cancelRevokeFailed:'Cancelled; revoke failed',writerFailed:'Writer failed; restart',writerFailedRevokeFailed:'Writer failed; revoke failed',revokeUnconfirmed:'Revoke unconfirmed',restartAction:'Restart from beginning',retry:'Retry',expired:'Expired',revoked:'Revoked',failed:'Failed','default-error':'Download failed'}).map(([key,value])=>`<span data-download-message="${key}">${value}</span>`).join('')}
                </div><div hidden>${Object.entries({OBJECT_IDENTITY_CHANGED:'Object changed',REVOCATION_UNCONFIRMED:'Revoke unconfirmed',FILE_WRITER_FAILED:'Writer failed',RANGE_INVALID:'Invalid range',GRANT_EXPIRED:'Expired'}).map(([key,value])=>`<span data-download-error="${key}">${value}</span>`).join('')}</div>
            </main><script type="module">import { bindDownload } from '/customer-backup-download.js'; bindDownload(document.querySelector('[data-customer-backup-download]'));</script>
        </body></html>`);
        return;
    }
    if (url.pathname === '/customer-backup-download.js') {
        const source = await readFile(resolve(process.cwd(), 'resources/js/components/customer-backup-download.js'));
        response.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Content-Length': String(source.length) });
        response.end(source);
        return;
    }
    if (url.pathname === '/api/create' && request.method === 'POST') {
        const grant = `synthetic-${++nextGrant}`;
        json(response, 200, {grant,status:'ready',status_url:`/api/${grant}/status`,content_url:`/api/${grant}/content?slow=${url.searchParams.get('slow') ?? '0'}`});
        return;
    }
    if (/^\/api\/synthetic-\d+$/.test(url.pathname) && request.method === 'DELETE') {
        json(response, 200, {status:'revoked'});
        return;
    }
    if (/^\/api\/synthetic-\d+\/status$/.test(url.pathname)) {
        json(response, 200, {status:'ready',content_url:`${url.pathname.replace(/\/status$/, '/content')}?slow=0`});
        return;
    }
    if (/^\/api\/synthetic-\d+\/content$/.test(url.pathname) && request.method === 'GET') {
        const range = /^bytes=(\d+)-(\d+)$/.exec(String(request.headers.range ?? ''));
        if (!range) return json(response, 416, {code:'RANGE_INVALID'});
        const start = Number(range[1]);
        const end = Number(range[2]);
        if (![0, 2].includes(start) || end !== start + 1) return json(response, 416, {code:'RANGE_INVALID'});
        const bytes = Buffer.from(Array.from({length:end-start+1},(_,index)=>start+index));
        const send = () => {
            if (response.destroyed) return;
            response.writeHead(206, {'Content-Range':`bytes ${start}-${end}/4`,'Content-Length':String(bytes.length),ETag:`"${'a'.repeat(64)}"`,'Content-Type':'application/octet-stream'});
            response.end(bytes);
        };
        if (url.searchParams.get('slow') === '1' && !delayedOnce) {
            delayedOnce = true;
            const timer = setTimeout(send, 5_000);
            request.once('close', () => clearTimeout(timer));
            return;
        }
        send();
        return;
    }
    response.writeHead(404).end();
});

server.listen(port, host, () => {
    console.log(`Synthetic backup QA only: http://${host}:${port}/`);
    console.log(`Cancel test URL: http://${host}:${port}/?slow=1`);
    console.log('Bytes served: 00 01 02 03. No external storage or application access.');
});
