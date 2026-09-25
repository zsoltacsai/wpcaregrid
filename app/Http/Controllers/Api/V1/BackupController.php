<?php

namespace App\Http\Controllers\Api\V1;

use App\Backups\BackupObjectName;
use App\Backups\BackupStatus;
use App\Backups\BackupUploads;
use App\Backups\RestoreStatus;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StorageUnavailable;
use App\Connector\ConnectorError;
use App\Http\Controllers\Controller;
use App\Models\Connector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Backup data from and to connectors. Every route is scoped to the
 * authenticated connector's own website. Storage credentials are never
 * involved: data goes to and comes from this server only.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupUploads $uploads) {}

    /** Resume point for an object. */
    public function status(Request $request, string $backup, string $name): JsonResponse
    {
        $record = $this->uploads->uploadable($this->connector($request), $backup);
        $object = $this->uploads->object($record, $name);

        return response()->json(['object' => $object->name, 'status' => $object->status, 'received_bytes' => $object->received_bytes]);
    }

    /** PUT one chunk as the raw request body, starting at ?offset=. */
    public function chunk(Request $request, string $backup, string $name): JsonResponse
    {
        $record = $this->uploads->uploadable($this->connector($request), $backup);
        $offset = filter_var($request->query('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $sha = $request->header('X-Chunk-SHA256');

        if ($offset === false || ($sha !== null && ! preg_match('/^[a-f0-9]{64}$/', $sha))) {
            throw new ConnectorError('INVALID_CHUNK', 'A valid offset and chunk checksum are required.', 422);
        }

        $object = $this->uploads->receiveChunk($record, $name, $offset, $request->getContent(true), (int) $request->header('Content-Length'), $sha);

        return response()->json(['object' => $object->name, 'received_bytes' => $object->received_bytes], 202);
    }

    public function complete(Request $request, string $backup, string $name): JsonResponse
    {
        $record = $this->uploads->uploadable($this->connector($request), $backup);
        $data = $request->validate([
            'size' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $object = $this->uploads->complete($record, $name, (int) $data['size'], $data['sha256']);

        return response()->json(['object' => $object->name, 'status' => $object->status]);
    }

    public function finalize(Request $request, string $backup): JsonResponse
    {
        $record = $this->uploads->uploadable($this->connector($request), $backup);
        $manifest = $request->input('manifest');

        if (! is_array($manifest)) {
            throw new ConnectorError('INVALID_MANIFEST', 'A manifest is required.', 422);
        }

        $record = $this->uploads->finalize($record, $manifest);

        return response()->json(['backup' => $record->uuid, 'status' => $record->status->value], 202);
    }

    /**
     * A byte range of a backup object, for a restore in progress on this
     * website. There is no other way to read backup data back.
     */
    public function content(Request $request, string $backup, string $name, StorageManager $storages): Response
    {
        $connector = $this->connector($request);
        $record = $this->uploads->find($connector, $backup);
        $objectName = BackupObjectName::tryFrom($name);

        $restoring = $record->website->restores()
            ->where('backup_id', $record->id)
            ->where('status', RestoreStatus::Restoring)
            ->whereHas('job', fn ($q) => $q->where('connector_id', $connector->id))
            ->exists();

        if (! $restoring || $record->status !== BackupStatus::Verified || ! $objectName || ! in_array($name, $record->type->objects(), true)) {
            throw new ConnectorError('DOWNLOAD_NOT_ALLOWED', 'This backup is not being restored to this website.', 403);
        }

        $object = $record->objects()->where('name', $name)->firstOrFail();
        $max = (int) config('backup.upload.download_chunk_size');
        $offset = max(0, (int) $request->query('offset', 0));
        $length = min($max, max(1, (int) $request->query('length', $max)), max(0, $object->size - $offset));

        if ($length < 1) {
            throw new ConnectorError('INVALID_RANGE', 'The requested range is outside the object.', 416);
        }

        try {
            $data = $storages->provider((string) $record->storage_provider)->readRange($record->objectKey($objectName), $offset, $length);
        } catch (StorageUnavailable) {
            throw new ConnectorError('STORAGE_UNAVAILABLE', 'Backup storage is temporarily unavailable.', 503);
        }

        return response($data, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Object-Size' => (string) $object->size,
            'X-Chunk-SHA256' => hash('sha256', $data),
        ]);
    }

    private function connector(Request $request): Connector
    {
        return $request->attributes->get('connector');
    }
}
