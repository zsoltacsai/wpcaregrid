<?php

namespace App\Backups;

use App\Connector\ConnectorError;
use App\Jobs\TransferBackup;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Models\Connector;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Receives backup data from connectors, in chunks, into a private staging
 * directory on this server. Nothing is read into memory whole: chunks are
 * streamed to disk and hashed as they arrive.
 *
 * Resuming: the connector asks for an object's received_bytes and continues
 * from there. A chunk must start exactly at received_bytes; offset 0
 * restarts the object.
 */
class BackupUploads
{
    public function __construct(private readonly BackupManifest $manifests) {}

    /** The backup, if this connector may upload to it now. */
    public function uploadable(Connector $connector, string $uuid): Backup
    {
        $backup = $this->find($connector, $uuid);

        if ($backup->connector_id !== $connector->id || ! $backup->status->acceptsUploads()) {
            throw new ConnectorError('BACKUP_NOT_ACCEPTING', 'This backup does not accept uploads.', 409);
        }

        return $backup;
    }

    /** Scoped to the connector's own website: another website's backup is "not found". */
    public function find(Connector $connector, string $uuid): Backup
    {
        $backup = preg_match('/^[0-9a-f-]{36}$/', $uuid)
            ? Backup::where('uuid', $uuid)->where('website_id', $connector->website_id)->first()
            : null;

        return $backup ?? throw new ConnectorError('BACKUP_NOT_FOUND', 'Backup not found.', 404);
    }

    public function object(Backup $backup, string $name): BackupObject
    {
        $objectName = BackupObjectName::tryFrom($name);

        if (! $objectName || ! in_array($name, $backup->type->objects(), true)) {
            throw new ConnectorError('INVALID_OBJECT', 'This backup has no such object.', 404);
        }

        return $backup->objects()->firstOrCreate(
            ['name' => $name],
            ['filename' => $objectName->filename(), 'status' => BackupObject::PENDING],
        );
    }

    /**
     * Append one chunk.
     *
     * @param  resource  $body
     */
    public function receiveChunk(Backup $backup, string $name, int $offset, $body, int $length, ?string $chunkSha256): BackupObject
    {
        $max = (int) config('backup.upload.max_chunk_size');
        if ($length < 1 || $length > $max) {
            throw new ConnectorError('INVALID_CHUNK', "Chunks must be between 1 byte and {$max} bytes.", 422);
        }

        return DB::transaction(function () use ($backup, $name, $offset, $body, $length, $chunkSha256) {
            $object = BackupObject::whereKey($this->object($backup, $name)->id)->lockForUpdate()->first();

            if (! in_array($object->status, [BackupObject::PENDING, BackupObject::RECEIVING], true)) {
                throw new ConnectorError('OBJECT_COMPLETE', 'This object has already been received.', 409);
            }

            if ($offset !== 0 && $offset !== $object->received_bytes) {
                throw new ConnectorError('OFFSET_MISMATCH', 'Continue from the received offset.', 409, ['expected_offset' => $object->received_bytes]);
            }

            if ($offset + $length > (int) config('backup.upload.max_object_size')) {
                throw new ConnectorError('OBJECT_TOO_LARGE', 'The object exceeds the maximum size.', 413);
            }

            $path = $object->stagingPath();
            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0700, true)) {
                throw new ConnectorError('STAGING_UNAVAILABLE', 'The upload cannot be stored right now.', 503);
            }

            $file = fopen($path, $offset === 0 ? 'wb' : 'r+b');
            fseek($file, $offset);
            $hash = hash_init('sha256');
            $written = 0;

            while (! feof($body) && $written <= $length) {
                $data = fread($body, 1024 * 1024);
                if ($data === false || $data === '') {
                    break;
                }
                hash_update($hash, $data);
                $written += fwrite($file, $data);
            }

            if ($written !== $length || ($chunkSha256 !== null && ! hash_equals($chunkSha256, hash_final($hash)))) {
                ftruncate($file, $offset);
                fclose($file);

                throw new ConnectorError('CHUNK_REJECTED', 'The chunk was incomplete or did not match its checksum.', 422, ['expected_offset' => $offset]);
            }

            ftruncate($file, $offset + $length);
            fclose($file);
            chmod($path, 0600);

            $object->forceFill(['status' => BackupObject::RECEIVING, 'received_bytes' => $offset + $length])->save();

            if ($backup->status === BackupStatus::Running) {
                $backup->transitionTo(BackupStatus::Uploading);
            }

            return $object;
        });
    }

    /** The connector says the object is complete: size and SHA-256 must match what arrived. */
    public function complete(Backup $backup, string $name, int $size, string $sha256): BackupObject
    {
        return DB::transaction(function () use ($backup, $name, $size, $sha256) {
            $object = BackupObject::whereKey($this->object($backup, $name)->id)->lockForUpdate()->first();

            if ($object->status === BackupObject::RECEIVED && $object->sha256 === $sha256 && $object->size === $size) {
                return $object; // Repeated request.
            }

            $path = $object->stagingPath();
            $actual = is_file($path) ? hash_file('sha256', $path) : null;

            if ($object->received_bytes !== $size || ! $actual || ! hash_equals($sha256, $actual)) {
                // Start over: nothing unverified is kept.
                @unlink($path);
                $object->forceFill(['status' => BackupObject::PENDING, 'received_bytes' => 0])->save();

                throw new ConnectorError('CHECKSUM_MISMATCH', 'The received data does not match the declared size and checksum. Upload it again.', 422);
            }

            $object->forceFill(['status' => BackupObject::RECEIVED, 'size' => $size, 'sha256' => $sha256])->save();

            return $object;
        });
    }

    /**
     * All objects are in: store the manifest and hand the backup to TransferBackup.
     *
     * @param  array<string, mixed>  $manifest  As sent by the connector.
     */
    public function finalize(Backup $backup, array $manifest): Backup
    {
        return DB::transaction(function () use ($backup, $manifest) {
            $backup = Backup::whereKey($backup->id)->lockForUpdate()->first();

            if ($backup->status !== BackupStatus::Uploading) {
                throw new ConnectorError('BACKUP_NOT_ACCEPTING', 'This backup cannot be finalized now.', 409);
            }

            $objects = $backup->objects()->get()->keyBy('name');
            foreach ($backup->type->objects() as $name) {
                if (($objects[$name]->status ?? null) !== BackupObject::RECEIVED) {
                    throw new ConnectorError('OBJECTS_MISSING', "The {$name} object has not been completely received.", 409);
                }
            }

            $document = $this->manifests->build($backup, $manifest, $objects->all());
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $manifestObject = $backup->objects()->updateOrCreate(
                ['name' => BackupObjectName::Manifest->value],
                ['filename' => BackupObjectName::Manifest->filename(), 'status' => BackupObject::RECEIVED, 'size' => strlen($json), 'received_bytes' => strlen($json), 'sha256' => hash('sha256', $json)],
            );
            $manifestObject->setRelation('backup', $backup);
            @mkdir(dirname($manifestObject->stagingPath()), 0700, true);
            file_put_contents($manifestObject->stagingPath(), $json);
            chmod($manifestObject->stagingPath(), 0600);

            $files = $objects[BackupObjectName::Files->value] ?? null;
            $database = $objects[BackupObjectName::Database->value] ?? null;

            $backup->transitionTo(BackupStatus::Uploaded, [
                'manifest' => $document,
                'checksums' => $document['checksums'],
                'files_size' => $files?->size,
                'database_size' => $database?->size,
                'total_size' => (int) $files?->size + (int) $database?->size,
                'connector_version' => $document['connector_version'],
                'wordpress_version' => $document['wordpress_version'],
                'php_version' => $document['php_version'],
                'uploaded_at' => now(),
            ]);

            AuditLog::record($backup->website, 'backup.uploaded', $backup, data: ['size' => $backup->total_size]);

            TransferBackup::dispatch($backup->id)->afterCommit();

            return $backup;
        });
    }
}
