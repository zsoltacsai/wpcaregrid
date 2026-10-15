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
final class S3Storage implements IncompleteUploadListable, InspectableBackupStorage, ListableBackupStorage
{
    use HashesStreams;

    private ?S3Client $client = null;

    /** @param  array<string, mixed>  $config  config('backup.providers.s3') */
    public function __construct(private readonly array $config)
    {
        $issue = S3EncryptionConfig::issue($this->config, app()->environment('production'));
        if ($issue !== null) {
            throw new \InvalidArgumentException($issue);
        }
    }

    public function name(): string
    {
        return 's3';
    }

    public function put(string $key, $stream, int $size, string $sha256): void
    {
        $params = ['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), 'ContentType' => 'application/octet-stream'];
        $params += $this->encryptionParameters();

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
                'params' => array_diff_key($params, array_flip(['Bucket', 'Key', 'ChecksumSHA256'])),
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
            $head = $this->client()->headObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), 'ChecksumMode' => 'ENABLED'] + $this->encryptionParameters());
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
            $object = $this->client()->getObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), '@http' => ['stream' => true]] + $this->encryptionParameters());
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
            $object = $this->client()->getObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key), 'Range' => 'bytes='.$offset.'-'.($offset + $length - 1)] + $this->encryptionParameters());
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

    public function presence(string $key): ObjectPresence
    {
        try {
            $this->client()->headObject(['Bucket' => $this->config['bucket'], 'Key' => $this->key($key)] + $this->encryptionParameters());

            return ObjectPresence::Present;
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                // This is logical-key absence only. Versioning/delete-marker and
                // Object Lock facts require independent provider verification.
                return ObjectPresence::Absent;
            }

            throw $this->unavailable($e);
        }
    }

    public function listKeys(): iterable
    {
        $providerPrefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        $params = ['Bucket' => $this->config['bucket']];
        if ($providerPrefix !== '') {
            $params['Prefix'] = $providerPrefix.'/';
        }
        $keys = [];
        try {
            foreach ($this->client()->getPaginator('ListObjectsV2', $params) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $key = (string) ($object['Key'] ?? '');
                    if ($providerPrefix !== '') {
                        $key = substr($key, strlen($providerPrefix) + 1);
                    }
                    try {
                        $keys[] = ObjectKey::assert($key);
                    } catch (\InvalidArgumentException) {
                        // Unrecognized keys are not treated as backup objects and are never modified.
                    }
                }
            }
        } catch (AwsException $e) {
            throw $this->unavailable($e);
        }

        return $keys;
    }

    public function listIncompleteUploads(): iterable
    {
        $providerPrefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        $params = ['Bucket' => $this->config['bucket']];
        if ($providerPrefix !== '') {
            $params['Prefix'] = $providerPrefix.'/';
        }
        $uploads = [];
        try {
            foreach ($this->client()->getPaginator('ListMultipartUploads', $params) as $page) {
                foreach ($page['Uploads'] ?? [] as $upload) {
                    $key = (string) ($upload['Key'] ?? '');
                    if ($providerPrefix !== '') {
                        $key = substr($key, strlen($providerPrefix) + 1);
                    }
                    $uploads[] = ['object_key' => $key, 'initiated_at' => isset($upload['Initiated']) ? (string) $upload['Initiated'] : null];
                }
            }
        } catch (AwsException $e) {
            throw $this->unavailable($e);
        }

        return $uploads;
    }

    private function key(string $key): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');

        return ($prefix !== '' ? $prefix.'/' : '').ObjectKey::assert($key);
    }

    /** @return array<string, string> */
    private function encryptionParameters(): array
    {
        $mode = $this->config['encryption_mode'] ?? 'legacy';
        if ($mode === 'sse-c') {
            $key = hex2bin($this->config['sse_customer_key']);

            return [
                'SSECustomerAlgorithm' => 'AES256',
                // The AWS SDK base64-encodes the raw key and raw MD5 digest.
                'SSECustomerKey' => $key,
                'SSECustomerKeyMD5' => md5($key, true),
            ];
        }

        $sse = match ($mode) {
            'none' => null,
            'sse-s3' => $this->config['server_side_encryption'] ?? 'AES256',
            default => $this->config['server_side_encryption'] ?? null,
        };

        return is_string($sse) && $sse !== '' ? ['ServerSideEncryption' => $sse] : [];
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
