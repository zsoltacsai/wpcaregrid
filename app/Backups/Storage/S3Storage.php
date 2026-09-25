<?php

namespace App\Backups\Storage;

use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

/**
 * Any S3-compatible object storage.
 *
 * Integrity:
 * - Objects up to `multipart_threshold` are sent in one PUT carrying their
 *   SHA-256 (x-amz-checksum-sha256); S3 recomputes it and rejects the upload
 *   on mismatch. Verification reads that checksum back (HeadObject with
 *   ChecksumMode) and compares it: a native check, no download.
 * - Larger objects are uploaded in parts. S3 then only knows a checksum of
 *   checksums, so verification streams the object back and hashes it.
 */
final class S3Storage implements BackupStorage
{
    use HashesStreams;

    private ?S3Client $client = null;

    /** @param  array<string, mixed>  $config  config('backup.providers.s3') */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 's3';
    }

    public function put(string $key, $stream, int $size, string $sha256): void
    {
        $params = ['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), 'ContentType' => 'application/octet-stream'];
        if (! empty($this->config['server_side_encryption'])) {
            $params['ServerSideEncryption'] = $this->config['server_side_encryption'];
        }

        try {
            if ($size <= (int) $this->config['multipart_threshold']) {
                $this->client()->putObject($params + [
                    'Body' => $stream,
                    'ContentLength' => $size,
                    'ChecksumSHA256' => base64_encode((string) hex2bin($sha256)),
                ]);

                return;
            }

            (new MultipartUploader($this->client(), $stream, [
                'bucket' => $params['Bucket'],
                'key' => $params['Key'],
                'part_size' => max(5 * 1024 * 1024, (int) $this->config['part_size']),
                'params' => array_diff_key($params, array_flip(['Bucket', 'Key'])),
            ]))->upload();
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['BadDigest', 'XAmzContentSHA256Mismatch', 'InvalidDigest'], true)) {
                throw new ChecksumMismatch('The storage rejected the data: checksum mismatch.');
            }

            throw $this->unavailable($e);
        } catch (MultipartUploadException $e) {
            throw new StorageUnavailable('Multipart upload to S3 storage failed.');
        }
    }

    public function verify(string $key, int $size, string $sha256): Verification
    {
        try {
            $head = $this->client()->headObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), 'ChecksumMode' => 'ENABLED']);
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return Verification::failed('MISSING');
            }

            throw $this->unavailable($e);
        }

        $remoteSize = (int) $head['ContentLength'];
        if ($remoteSize !== $size) {
            return Verification::failed('SIZE_MISMATCH', $remoteSize);
        }

        $native = (string) ($head['ChecksumSHA256'] ?? '');
        if ($native !== '' && ! str_contains($native, '-')) {
            return hash_equals(base64_encode((string) hex2bin($sha256)), $native)
                ? Verification::passed(Verification::NATIVE, $remoteSize)
                : Verification::failed('CHECKSUM_MISMATCH', $remoteSize, Verification::NATIVE);
        }

        if (! config('backup.transfer.readback')) {
            return Verification::failed('UNVERIFIABLE', $remoteSize);
        }

        try {
            $object = $this->client()->getObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), '@http' => ['stream' => true]]);
            $body = $object['Body']->detach();
            $actual = $this->streamHash($body);
        } catch (AwsException $e) {
            throw $this->unavailable($e);
        }

        return hash_equals($sha256, $actual)
            ? Verification::passed(Verification::READBACK, $remoteSize)
            : Verification::failed('CHECKSUM_MISMATCH', $remoteSize, Verification::READBACK);
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        try {
            $object = $this->client()->getObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), 'Range' => 'bytes='.$offset.'-'.($offset + $length - 1)]);
        } catch (AwsException $e) {
            throw $this->unavailable($e);
        }

        return (string) $object['Body'];
    }

    public function delete(string $key): void
    {
        try {
            $this->client()->deleteObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key)]);
        } catch (AwsException $e) {
            throw $this->unavailable($e);
        }
    }

    private function key(string $key): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');

        return ($prefix !== '' ? $prefix.'/' : '').ObjectKey::assert($key);
    }

    private function client(): S3Client
    {
        return $this->client ??= new S3Client(array_filter([
            'version' => 'latest',
            'region' => $this->config['region'] ?: 'us-east-1',
            'endpoint' => $this->config['endpoint'] ?: null,
            'use_path_style_endpoint' => (bool) $this->config['use_path_style_endpoint'],
            'credentials' => ['key' => $this->config['key'], 'secret' => $this->config['secret']],
            'http' => ['connect_timeout' => 10, 'timeout' => (int) config('backup.transfer.timeout')],
            // Checksums are sent explicitly (SHA-256), not the SDK's default CRC.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ], fn ($value) => $value !== null));
    }

    private function unavailable(AwsException $e): StorageUnavailable
    {
        // The AWS error code only: messages can echo request details.
        return new StorageUnavailable('S3 storage error: '.($e->getAwsErrorCode() ?: 'HTTP '.$e->getStatusCode()));
    }
}
