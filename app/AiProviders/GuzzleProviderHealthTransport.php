<?php

namespace App\AiProviders;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class GuzzleProviderHealthTransport implements ProviderHealthTransport
{
    public const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProviderRegistry $registry,
    ) {}

    public function get(string $providerId, string $credential): ProviderProbeResponse
    {
        $provider = $this->registry->get($providerId);
        $headers = [
            'Accept' => 'application/json',
            'Accept-Encoding' => 'identity',
        ];

        match ($provider['auth']) {
            'bearer' => $headers['Authorization'] = 'Bearer '.$credential,
            'x-api-key' => $headers['x-api-key'] = $credential,
            'x-goog-api-key' => $headers['x-goog-api-key'] = $credential,
            default => abort(500),
        };

        if ($providerId === 'anthropic') {
            $headers['anthropic-version'] = '2023-06-01';
        }

        $uri = $provider['endpoint'];
        if ($provider['query'] !== []) {
            $uri .= '?'.http_build_query($provider['query'], '', '&', PHP_QUERY_RFC3986);
        }

        $startedAt = hrtime(true);
        try {
            $response = $this->client->request('GET', $uri, [
                'headers' => $headers,
                'allow_redirects' => false,
                'connect_timeout' => 3,
                'timeout' => 8,
                'http_errors' => false,
                'stream' => false,
                'sink' => new BoundedResponseStream(self::MAX_RESPONSE_BYTES),
                'on_headers' => static function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                        throw new ProviderTransportException(ProviderHealthStatus::Error, 'RESPONSE_TOO_LARGE');
                    }
                },
                'proxy' => '',
                'verify' => true,
                'decode_content' => false,
            ]);

            $body = $this->readBoundedBody($response);

            return new ProviderProbeResponse(
                $response->getStatusCode(),
                $body,
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
            );
        } catch (ProviderTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof ProviderTransportException) {
                    throw new ProviderTransportException($cause->healthStatus, $cause->errorCategory);
                }
                if ($cause instanceof ResponseLimitExceeded) {
                    throw new ProviderTransportException(ProviderHealthStatus::Error, 'RESPONSE_TOO_LARGE');
                }
            }

            $message = strtolower($exception->getMessage());
            $timeout = $exception instanceof ConnectException
                ? (($exception->getHandlerContext()['errno'] ?? null) === 28 || str_contains($message, 'timed out'))
                : str_contains($message, 'timed out');

            throw new ProviderTransportException(
                $timeout ? ProviderHealthStatus::Timeout : ProviderHealthStatus::Unavailable,
                $timeout ? 'TIMEOUT' : 'NETWORK_UNAVAILABLE',
            );
        }
    }

    private function readBoundedBody(ResponseInterface $response): string
    {
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
            throw new ProviderTransportException(ProviderHealthStatus::Error, 'RESPONSE_TOO_LARGE');
        }

        $body = $response->getBody();
        $buffer = '';
        while (! $body->eof()) {
            $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($buffer);
            $chunk = $body->read(min(8192, $remaining));
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > self::MAX_RESPONSE_BYTES) {
                throw new ProviderTransportException(ProviderHealthStatus::Error, 'RESPONSE_TOO_LARGE');
            }
        }

        return $buffer;
    }
}
