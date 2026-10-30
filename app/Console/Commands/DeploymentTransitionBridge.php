<?php

namespace App\Console\Commands;

use App\Deployment\TransitionPersistenceBridge as PersistenceBridge;
use App\Models\TransitionBridgeRequest;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/** One-shot Laravel DB child for the root-owned AF_UNIX bridge listener. */
final class DeploymentTransitionBridge extends Command
{
    protected $signature = 'deployment:transition-bridge';

    protected $description = 'Handle one authenticated root transition persistence request.';

    private const ROOT_SOCKET_PATH = '/run/wpcaregrid/.transition-bridge.sock';

    private const MAX_FRAME_BYTES = 65536;

    public function handle(PersistenceBridge $bridge): int
    {
        $connection = null;

        try {
            $this->requireUnprivilegedCli();
            $connection = $this->connectToRootListener();
            stream_set_timeout($connection, 5);

            $requestBytes = $this->readFrame($connection);
            try {
                $request = json_decode($requestBytes, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The bridge frame is invalid.', previous: $exception);
            }

            if (! is_array($request)
                || ! hash_equals(TransitionBridgeRequest::canonicalJson($request), $requestBytes)) {
                throw new RuntimeException('The bridge frame is not canonical JSON.');
            }

            $response = $bridge->handle($request);
            $this->writeFrame($connection, $response);

            return self::SUCCESS;
        } catch (Throwable) {
            // Do not disclose tuple, request, DB, path, or release data on this channel.
            $this->error('Transition bridge request refused or failed.');

            return self::FAILURE;
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
    }

    /** @return resource */
    private function connectToRootListener()
    {
        $directory = dirname(self::ROOT_SOCKET_PATH);
        clearstatcache(true, $directory);
        clearstatcache(true, self::ROOT_SOCKET_PATH);
        $directoryInfo = @lstat($directory);
        $socketInfo = @lstat(self::ROOT_SOCKET_PATH);

        if (! is_array($directoryInfo)
            || (($directoryInfo['mode'] & 0170000) !== 0040000)
            || $directoryInfo['uid'] !== 0
            || (($directoryInfo['mode'] & 0022) !== 0)
            || is_link($directory)
            || ! is_array($socketInfo)
            || (($socketInfo['mode'] & 0170000) !== 0140000)
            || $socketInfo['uid'] !== 0
            || (($socketInfo['mode'] & 0002) !== 0)
            || is_link(self::ROOT_SOCKET_PATH)) {
            throw new RuntimeException('The root bridge listener path is absent or untrusted.');
        }

        $errorCode = 0;
        $errorMessage = '';
        $connection = @stream_socket_client(
            'unix://'.self::ROOT_SOCKET_PATH,
            $errorCode,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($connection)) {
            throw new RuntimeException('The root bridge listener is unavailable.');
        }

        return $connection;
    }

    private function requireUnprivilegedCli(): void
    {
        if (! app()->runningInConsole()
            || ! function_exists('posix_geteuid')
            || posix_geteuid() === 0) {
            throw new RuntimeException('The transition bridge requires its unprivileged CLI identity.');
        }
    }

    /** @param resource $connection */
    private function readFrame($connection): string
    {
        $header = $this->readExact($connection, 4);
        $length = unpack('Nlength', $header)['length'] ?? null;
        if (! is_int($length) || $length < 2 || $length > self::MAX_FRAME_BYTES) {
            throw new RuntimeException('The bridge frame length is invalid.');
        }

        return $this->readExact($connection, $length);
    }

    /** @param resource $connection */
    private function writeFrame($connection, array $response): void
    {
        $payload = TransitionBridgeRequest::canonicalJson($response);
        if (strlen($payload) > self::MAX_FRAME_BYTES) {
            throw new RuntimeException('The bridge response exceeds the frame limit.');
        }

        $this->writeAll($connection, pack('N', strlen($payload)).$payload);
    }

    /** @param resource $connection */
    private function readExact($connection, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($connection, $length - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                $metadata = stream_get_meta_data($connection);
                throw new RuntimeException(($metadata['timed_out'] ?? false)
                    ? 'The root bridge request timed out.'
                    : 'The root bridge request ended before the frame was complete.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @param resource $connection */
    private function writeAll($connection, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($connection, substr($bytes, $offset));
            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('The bridge response could not be written completely.');
            }
            $offset += $written;
        }
    }
}
