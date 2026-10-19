<?php

namespace Tests\Feature\Security;

use App\Console\Commands\Security\SyncVulnerabilities;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[RequiresPhpExtension('curl')]
class WordfenceGuzzleRequestOptionsTest extends TestCase
{
    public function test_stream_mode_conflicts_with_the_curl_dns_pin_in_guzzle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing the "curl" request option to the stream handler is not supported');

        (new Client)->get('http://www.wordfence.com:443/', [
            'allow_redirects' => false,
            'stream' => true,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => ['www.wordfence.com:443:127.0.0.1']],
        ]);
    }

    public function test_production_options_use_curl_dns_pinning_with_a_bounded_local_sink(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        $process = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('tests/Fixtures/wordfence-http-router.php')]);
        $process->start();
        $sink = tempnam(sys_get_temp_dir(), 'wpcg-guzzle-');
        $this->assertNotFalse($sink);

        try {
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    break;
                }
                usleep(20_000);
            } while (! $process->isTerminated() && microtime(true) < $deadline);

            $this->assertFalse($process->isTerminated(), 'The local HTTP fixture server failed to start.');

            $options = $this->productionOptions([
                "www.wordfence.com:{$port}:127.0.0.1",
            ], $sink, 1024);
            $response = (new Client)->get("http://www.wordfence.com:{$port}/", $options + [
                'connect_timeout' => 1,
                'timeout' => 3,
                'headers' => ['Authorization' => 'Bearer synthetic-test-token'],
            ]);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('{"fixture":true}', file_get_contents($sink));
            $this->assertSame('{"fixture":true}', (string) $response->getBody());
            $this->assertFalse(array_key_exists('stream', $options));
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame(["www.wordfence.com:{$port}:127.0.0.1"], $options['curl'][CURLOPT_RESOLVE]);
        } finally {
            $process->stop();
            @unlink($sink);
        }
    }

    /** @param list<string> $resolve @return array<string, mixed> */
    private function productionOptions(array $resolve, string $sink, int $limit): array
    {
        $method = new \ReflectionMethod(SyncVulnerabilities::class, 'wordfenceHttpOptions');

        return $method->invoke((new \ReflectionClass(SyncVulnerabilities::class))->newInstanceWithoutConstructor(), $resolve, $sink, $limit);
    }
}
