<?php

namespace WPCareGrid\Connector\Tests\Support;

/**
 * The server side of the backup protocol (docs/BACKUP_ENGINE.md), in
 * memory: chunked uploads with offsets and checksums, completion,
 * finalize, and ranged downloads for restores. The real implementation is
 * tested in the Laravel suite and end to end; this one lets the connector
 * be tested against the same rules.
 */
final class FakeBackupServer
{
    /** @var array<string, string> object name => bytes received */
    public array $objects = [];

    /** @var array<string, array{size: int, sha256: string}> */
    public array $completed = [];

    public ?array $manifest = null;

    public int $finalized = 0;

    /** Chunk requests to answer with a temporary error first. */
    public int $failing_chunks = 0;

    /** Downloads served from these local files: name => path. */
    public array $downloads = [];

    public function __construct(private readonly FakeHttp $http) {}

    public function register(): self
    {
        $this->http->on('GET backups/*/objects/*', fn (array $request) => $this->status($request));
        $this->http->on('PUT backups/*/objects/*', fn (array $request) => $this->chunk($request));
        $this->http->on('POST backups/*/objects/*/complete', fn (array $request) => $this->complete($request));
        $this->http->on('POST backups/*/finalize', fn (array $request) => $this->finalize($request));
        $this->http->on('GET backups/*/objects/*/content', fn (array $request) => $this->content($request));

        return $this;
    }

    private function status(array $request): array
    {
        $name = self::name($request);

        return ['status' => 200, 'body' => ['object' => $name, 'status' => isset($this->completed[$name]) ? 'received' : 'receiving', 'received_bytes' => strlen($this->objects[$name] ?? '')]];
    }

    private function chunk(array $request): array
    {
        if ($this->failing_chunks > 0) {
            $this->failing_chunks--;

            return ['status' => 503, 'body' => []];
        }

        $name = self::name($request);
        $offset = (int) $request['query']['offset'];
        $have = strlen($this->objects[$name] ?? '');

        if ($offset !== 0 && $offset !== $have) {
            return ['status' => 409, 'body' => ['error' => ['code' => 'OFFSET_MISMATCH', 'message' => 'Continue from the received offset.', 'details' => ['expected_offset' => $have]]]];
        }
        if (! hash_equals((string) ($request['headers']['X-Chunk-SHA256'] ?? ''), hash('sha256', $request['raw']))) {
            return FakeHttp::error(422, 'CHUNK_REJECTED');
        }

        $this->objects[$name] = substr($this->objects[$name] ?? '', 0, $offset).$request['raw'];

        return ['status' => 202, 'body' => ['object' => $name, 'received_bytes' => strlen($this->objects[$name])]];
    }

    private function complete(array $request): array
    {
        $name = self::name($request);
        $data = $this->objects[$name] ?? '';

        if (strlen($data) !== $request['body']['size'] || hash('sha256', $data) !== $request['body']['sha256']) {
            unset($this->objects[$name]);

            return FakeHttp::error(422, 'CHECKSUM_MISMATCH');
        }

        $this->completed[$name] = ['size' => strlen($data), 'sha256' => hash('sha256', $data)];

        return ['status' => 200, 'body' => ['object' => $name, 'status' => 'received']];
    }

    private function finalize(array $request): array
    {
        $this->manifest = $request['body']['manifest'];
        $this->finalized++;

        return ['status' => 202, 'body' => ['status' => 'uploaded']];
    }

    private function content(array $request): array
    {
        $bytes = (string) file_get_contents($this->downloads[self::name($request)]);
        $piece = substr($bytes, (int) $request['query']['offset'], (int) $request['query']['length']);

        return ['status' => 200, 'raw' => $piece, 'headers' => ['x-chunk-sha256' => hash('sha256', $piece)]];
    }

    private static function name(array $request): string
    {
        return explode('/', $request['path'])[3];
    }
}
