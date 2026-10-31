<?php

namespace App\Console\Commands;

use App\Deployment\TransitionPersistenceBridge;
use App\Models\TransitionBridgeRequest;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/** One-shot unprivileged Laravel client for the root-owned AF_UNIX listener. */
final class DeploymentTransitionBridge extends Command
{
    protected $signature = 'deployment:transition-bridge {--connect-socket=}';

    protected $description = 'Handle one typed root transition persistence RPC.';

    private const MAX_LINE_BYTES = 65536;

    private const TEST_DIAGNOSTIC_PHASES = [
        'handler_entry',
        'unprivileged_identity_pass',
        'socket_option_pass',
        'connect_attempt',
        'connect_success',
    ];

    public function handle(TransitionPersistenceBridge $bridge): int
    {
        $connection = null;
        $request = null;

        try {
            $this->emitTestDiagnosticPhase('handler_entry');
            $this->requireUnprivilegedCli();
            $this->emitTestDiagnosticPhase('unprivileged_identity_pass');
            $socketPath = $this->validatedSocketOption();
            $this->emitTestDiagnosticPhase('socket_option_pass');
            $this->emitTestDiagnosticPhase('connect_attempt');
            $connection = $this->connectToRootListener($socketPath);
            $this->emitTestDiagnosticPhase('connect_success');
            stream_set_timeout($connection, 10);

            $requestBytes = $this->readLine($connection);
            try {
                $requestObject = json_decode($requestBytes, false, 32, JSON_THROW_ON_ERROR);
                $request = json_decode($requestBytes, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The transition RPC request is invalid.', previous: $exception);
            }

            if (! $requestObject instanceof \stdClass
                || ! is_array($request)
                || ! hash_equals(TransitionBridgeRequest::canonicalRpcJson($requestObject), $requestBytes)) {
                throw new RuntimeException('The transition RPC request is not canonical JSON.');
            }

            $response = $bridge->handleRpc($request);
            $this->writeLine($connection, $response);

            return self::SUCCESS;
        } catch (Throwable) {
            // Keep DB errors, tuples, request data, socket paths and release metadata private.
            if (is_resource($connection) && is_array($request)) {
                $response = $this->refusedEnvelope($request);
                if ($response !== null) {
                    try {
                        $this->writeLine($connection, $response);

                        return self::SUCCESS;
                    } catch (Throwable) {
                        // The root listener treats a lost mutation reply as ambiguous and reads its receipt.
                    }
                }
            }

            $this->error('Transition RPC request refused or failed.');

            return self::FAILURE;
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
    }

    private function requireUnprivilegedCli(): void
    {
        if (! app()->runningInConsole()
            || ! function_exists('posix_geteuid')
            || posix_geteuid() === 0) {
            throw new RuntimeException('The transition RPC requires its unprivileged CLI identity.');
        }
    }

    private function emitTestDiagnosticPhase(string $phase): void
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('CAREGRID_TRANSITION_RPC_TEST_DIAGNOSTICS') !== '1'
            || ! in_array($phase, self::TEST_DIAGNOSTIC_PHASES, true)) {
            return;
        }

        fwrite(STDERR, 'phase='.$phase."\n");
        fflush(STDERR);
    }

    private function validatedSocketOption(): string
    {
        $path = $this->option('connect-socket');
        if (! is_string($path)
            || $path === ''
            || $path[0] !== DIRECTORY_SEPARATOR
            || strlen($path) >= 108
            || str_contains($path, "\0")
            || in_array('..', explode(DIRECTORY_SEPARATOR, $path), true)) {
            throw new RuntimeException('The transition RPC socket option is invalid.');
        }

        return $path;
    }

    /** @return resource */
    private function connectToRootListener(string $socketPath)
    {
        $directory = dirname($socketPath);
        clearstatcache(true, $directory);
        clearstatcache(true, $socketPath);
        $directoryInfo = @lstat($directory);
        $socketInfo = @lstat($socketPath);

        if (! is_array($directoryInfo)
            || (($directoryInfo['mode'] & 0170000) !== 0040000)
            || $directoryInfo['uid'] !== 0
            || (($directoryInfo['mode'] & 0022) !== 0)
            || is_link($directory)
            || ! is_array($socketInfo)
            || (($socketInfo['mode'] & 0170000) !== 0140000)
            || $socketInfo['uid'] !== 0
            || (($socketInfo['mode'] & 0002) !== 0)
            || is_link($socketPath)) {
            throw new RuntimeException('The root transition RPC listener path is absent or untrusted.');
        }

        $errorCode = 0;
        $errorMessage = '';
        $connection = @stream_socket_client(
            'unix://'.$socketPath,
            $errorCode,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($connection)) {
            throw new RuntimeException('The root transition RPC listener is unavailable.');
        }

        clearstatcache(true, $socketPath);
        $connectedInfo = @lstat($socketPath);
        if (! is_array($connectedInfo)
            || $connectedInfo['dev'] !== $socketInfo['dev']
            || $connectedInfo['ino'] !== $socketInfo['ino']
            || (($connectedInfo['mode'] & 0170000) !== 0140000)
            || is_link($socketPath)) {
            fclose($connection);
            throw new RuntimeException('The root transition RPC socket changed during connect.');
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
                $metadata = stream_get_meta_data($connection);
                throw new RuntimeException(($metadata['timed_out'] ?? false)
                    ? 'The transition RPC request timed out.'
                    : 'The transition RPC request ended before a complete line.');
            }

            $buffer .= $chunk;
            $newline = strpos($buffer, "\n");
            if ($newline !== false) {
                if ($newline !== strlen($buffer) - 1 || substr_count($buffer, "\n") !== 1) {
                    throw new RuntimeException('The transition RPC request contains multiple frames.');
                }
                if (strlen($buffer) > self::MAX_LINE_BYTES) {
                    throw new RuntimeException('The transition RPC request exceeds its bound.');
                }

                return $buffer;
            }
        }

        throw new RuntimeException('The transition RPC request exceeds its bound.');
    }

    /** @param resource $connection @param array<string, mixed> $response */
    private function writeLine($connection, array $response): void
    {
        $line = TransitionBridgeRequest::canonicalJson($response);
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new RuntimeException('The transition RPC response exceeds its bound.');
        }

        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($connection, substr($line, $offset));
            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('The transition RPC response could not be written completely.');
            }
            $offset += $written;
        }
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    private function refusedEnvelope(array $request): ?array
    {
        $requestId = $request['request_id'] ?? null;
        $requestDigest = $request['request_digest'] ?? null;
        if (($request['version'] ?? null) !== TransitionPersistenceBridge::PROTOCOL_VERSION
            || ! is_string($requestId)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $requestId) !== 1
            || ! is_string($requestDigest)
            || preg_match('/\A[0-9a-f]{64}\z/D', $requestDigest) !== 1) {
            return null;
        }

        return [
            'version' => TransitionPersistenceBridge::PROTOCOL_VERSION,
            'request_id' => $requestId,
            'request_digest' => $requestDigest,
            'status' => 'REFUSED',
            'result' => ['error' => 'request_refused'],
        ];
    }
}
