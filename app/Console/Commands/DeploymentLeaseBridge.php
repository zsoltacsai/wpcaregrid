<?php

namespace App\Console\Commands;

use App\Deployment\Phase2B\TransitionLeasePersistenceBridge;
use App\Models\TransitionLeaseReceipt;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/** One-shot unprivileged CLI endpoint for the separate inert Package B RPC v2. */
final class DeploymentLeaseBridge extends Command
{
    protected $signature = 'deployment:lease-bridge {--connect-socket=}';

    protected $description = 'Handle one typed, inert transition lease persistence RPC v2.';

    private const MAX_LINE_BYTES = 65536;

    public function handle(TransitionLeasePersistenceBridge $bridge): int
    {
        $connection = null;
        $request = null;
        try {
            $this->requireUnprivilegedCli();
            $socketPath = $this->validatedSocketOption();
            $connection = $this->connectToRootListener($socketPath);
            stream_set_timeout($connection, 10);
            $bytes = $this->readLine($connection);
            try {
                $object = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
                $request = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The lease RPC v2 request is invalid.', previous: $exception);
            }
            if (! $object instanceof \stdClass
                || ! is_array($request)
                || ! hash_equals(TransitionLeaseReceipt::canonicalJson($object), $bytes)) {
                throw new RuntimeException('The lease RPC v2 request is not canonical JSON.');
            }

            $this->writeLine($connection, $bridge->handleRpc($request));

            return self::SUCCESS;
        } catch (Throwable) {
            if (is_resource($connection) && is_array($request)) {
                $refused = $this->refusedEnvelope($request);
                if ($refused !== null) {
                    try {
                        $this->writeLine($connection, $refused);

                        return self::SUCCESS;
                    } catch (Throwable) {
                        // Root adapter treats a lost mutation response as ambiguous and only reads its receipt.
                    }
                }
            }
            $this->error('Lease RPC v2 request refused or failed.');

            return self::FAILURE;
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
    }

    private function requireUnprivilegedCli(): void
    {
        if (! app()->runningInConsole() || ! function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new RuntimeException('The lease RPC v2 requires an unprivileged CLI identity.');
        }
    }

    private function validatedSocketOption(): string
    {
        $path = $this->option('connect-socket');
        if (! is_string($path) || $path === '' || $path[0] !== DIRECTORY_SEPARATOR || strlen($path) >= 108
            || str_contains($path, "\0") || in_array('..', explode(DIRECTORY_SEPARATOR, $path), true)) {
            throw new RuntimeException('The lease RPC v2 socket option is invalid.');
        }

        return $path;
    }

    /** @return resource */
    private function connectToRootListener(string $path)
    {
        $directory = dirname($path);
        clearstatcache(true, $directory);
        clearstatcache(true, $path);
        $dir = @lstat($directory);
        $socket = @lstat($path);
        if (! is_array($dir) || (($dir['mode'] & 0170000) !== 0040000) || $dir['uid'] !== 0
            || (($dir['mode'] & 0022) !== 0) || is_link($directory)
            || ! is_array($socket) || (($socket['mode'] & 0170000) !== 0140000) || $socket['uid'] !== 0
            || (($socket['mode'] & 0002) !== 0) || is_link($path)) {
            throw new RuntimeException('The root lease RPC v2 listener is absent or untrusted.');
        }
        $errorCode = 0;
        $errorMessage = '';
        $connection = @stream_socket_client('unix://'.$path, $errorCode, $errorMessage, 2, STREAM_CLIENT_CONNECT);
        if (! is_resource($connection)) {
            throw new RuntimeException('The root lease RPC v2 listener is unavailable.');
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (! is_array($after) || $after['dev'] !== $socket['dev'] || $after['ino'] !== $socket['ino']
            || (($after['mode'] & 0170000) !== 0140000) || is_link($path)) {
            fclose($connection);
            throw new RuntimeException('The root lease RPC v2 socket changed during connect.');
        }

        return $connection;
    }

    /** @param resource $connection */
    private function readLine($connection): string
    {
        $buffer = '';
        while (strlen($buffer) <= self::MAX_LINE_BYTES) {
            $chunk = fread($connection, min(4096, self::MAX_LINE_BYTES + 1 - strlen($buffer)));
            if (! is_string($chunk) || $chunk === '') {
                $meta = stream_get_meta_data($connection);
                throw new RuntimeException(($meta['timed_out'] ?? false)
                    ? 'The lease RPC v2 request timed out.'
                    : 'The lease RPC v2 request ended before a complete frame.');
            }
            $buffer .= $chunk;
            $newline = strpos($buffer, "\n");
            if ($newline !== false) {
                if ($newline !== strlen($buffer) - 1 || substr_count($buffer, "\n") !== 1 || strlen($buffer) > self::MAX_LINE_BYTES) {
                    throw new RuntimeException('The lease RPC v2 request framing is invalid.');
                }

                return $buffer;
            }
        }
        throw new RuntimeException('The lease RPC v2 request exceeds its bound.');
    }

    /** @param resource $connection @param array<string, mixed> $response */
    private function writeLine($connection, array $response): void
    {
        $line = TransitionLeaseReceipt::canonicalJson($response);
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new RuntimeException('The lease RPC v2 response exceeds its bound.');
        }
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($connection, substr($line, $offset));
            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('The lease RPC v2 response could not be written completely.');
            }
            $offset += $written;
        }
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    private function refusedEnvelope(array $request): ?array
    {
        if (($request['version'] ?? null) !== TransitionLeasePersistenceBridge::PROTOCOL_VERSION
            || ! is_string($request['request_id'] ?? null)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $request['request_id']) !== 1
            || ! is_string($request['request_digest'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $request['request_digest']) !== 1) {
            return null;
        }

        return [
            'version' => TransitionLeasePersistenceBridge::PROTOCOL_VERSION,
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'status' => 'REFUSED',
            'result' => ['error' => 'request_refused'],
        ];
    }
}
