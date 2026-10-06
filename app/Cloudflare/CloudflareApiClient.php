<?php

namespace App\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Small, fixed-host API adapter. Provider response bodies and credentials never escape this class. */
final class CloudflareApiClient
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function verifyToken(): void
    {
        $result = $this->request('GET', '/user/tokens/verify');
        if (($result['status'] ?? null) !== 'active') {
            throw new CloudflareDnsException('authentication');
        }
    }

    /** @return list<array<string, mixed>> */
    public function zones(array $query = []): array
    {
        return $this->paginate('/zones', $query);
    }

    /** @return list<array<string, mixed>> */
    public function records(string $zoneId, bool $firstPageOnly = false): array
    {
        $path = '/zones/'.rawurlencode($zoneId).'/dns_records';
        if (! $firstPageOnly) {
            $records = $this->paginate($path);
            $this->assertRecords($records);

            return $records;
        }
        $payload = $this->send('GET', $path, query: ['per_page' => 1, 'page' => 1]);
        $result = $payload['result'] ?? null;
        if (! is_array($result) || count(array_filter($result, 'is_array')) !== count($result)) {
            throw new CloudflareDnsException('invalid_response');
        }

        $records = array_values($result);
        $this->assertRecords($records);

        return $records;
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function createRecord(string $zoneId, array $record): array
    {
        $result = $this->request('POST', '/zones/'.rawurlencode($zoneId).'/dns_records', body: $record);
        $this->assertRecords([$result]);

        return $result;
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function updateRecord(string $zoneId, string $recordId, array $record): array
    {
        $result = $this->request('PUT', '/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($recordId), body: $record);
        $this->assertRecords([$result]);

        return $result;
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->request('DELETE', '/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($recordId), allowEmptyResult: true);
    }

    /** @return list<array<string, mixed>> */
    private function paginate(string $path, array $query = []): array
    {
        $items = [];
        $page = 1;
        do {
            $payload = $this->send('GET', $path, query: array_merge($query, ['per_page' => 100, 'page' => $page]));
            $result = $payload['result'] ?? null;
            if (! is_array($result)) {
                throw new CloudflareDnsException('invalid_response');
            }
            $validItems = array_values(array_filter($result, 'is_array'));
            if (count($validItems) !== count($result)) {
                throw new CloudflareDnsException('invalid_response');
            }
            $items = array_merge($items, $validItems);
            $totalPages = filter_var($payload['result_info']['total_pages'] ?? null, FILTER_VALIDATE_INT);
            if ($totalPages === false) {
                $totalPages = count($result) === 100 ? $page + 1 : $page;
            }
            $page++;
            if ($page > 101) {
                throw new CloudflareDnsException('invalid_response');
            }
        } while ($page <= max(1, (int) $totalPages));

        return $items;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query = [], array $body = [], bool $allowEmptyResult = false): array
    {
        $payload = $this->send($method, $path, $query, $body);
        $result = $payload['result'] ?? null;
        if (! is_array($result) && ! $allowEmptyResult) {
            throw new CloudflareDnsException('invalid_response');
        }

        return is_array($result) ? $result : [];
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $query = [], array $body = []): array
    {
        $token = config('services.cloudflare.api_token');
        if (! is_string($token) || trim($token) === '') {
            throw new CloudflareDnsException('not_configured');
        }

        try {
            $request = $this->pendingRequest($token);
            $options = $method === 'GET' ? ['query' => $query] : ($body === [] ? [] : ['json' => $body]);
            $response = $request->send($method, self::BASE_URL.$path, $options);
        } catch (ConnectionException) {
            throw new CloudflareDnsException('unavailable');
        } catch (CloudflareDnsException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CloudflareDnsException('unavailable');
        }

        $this->assertSuccessful($response, $path);
        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new CloudflareDnsException('invalid_response');
        }

        return $payload;
    }

    private function pendingRequest(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($token)
            ->withUserAgent('WP-CareGrid-DNS/1.0')
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(3)
            ->timeout(8);
    }

    /** @param list<array<string, mixed>> $records */
    private function assertRecords(array $records): void
    {
        foreach ($records as $record) {
            if (! is_string($record['id'] ?? null) || $record['id'] === ''
                || ! is_string($record['type'] ?? null) || ($record['type'] ?? '') === ''
                || ! is_string($record['name'] ?? null) || ($record['name'] ?? '') === ''
                || ! is_string($record['content'] ?? null) || ! is_int($record['ttl'] ?? null)
                || (! is_bool($record['proxied'] ?? null) && ($record['proxied'] ?? null) !== null)) {
                throw new CloudflareDnsException('invalid_response');
            }
            if (isset($record['priority']) && ! is_int($record['priority'])) {
                throw new CloudflareDnsException('invalid_response');
            }
            if (isset($record['comment']) && ! is_string($record['comment'])) {
                throw new CloudflareDnsException('invalid_response');
            }
        }
    }

    private function assertSuccessful(Response $response, string $path): void
    {
        match (true) {
            $response->status() === 401 => throw new CloudflareDnsException('authentication'),
            $response->status() === 403 => throw new CloudflareDnsException('permission'),
            $response->status() === 429 => throw new CloudflareDnsException('rate_limited'),
            $response->status() === 409 => throw new CloudflareDnsException('record_conflict'),
            $response->status() === 404 => throw new CloudflareDnsException(str_contains($path, '/dns_records/') ? 'record_not_found' : 'zone_not_found'),
            $response->serverError() => throw new CloudflareDnsException('unavailable'),
            ! $response->successful() => throw new CloudflareDnsException('invalid_response'),
            default => null,
        };
    }
}
