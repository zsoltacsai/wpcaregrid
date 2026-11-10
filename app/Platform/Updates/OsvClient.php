<?php

namespace App\Platform\Updates;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads advisories from OSV (https://osv.dev, which aggregates the GitHub Advisory Database
 * and others). Two fixed HTTPS endpoints, never configuration or user input; no redirects;
 * bounded time and size; every field of every answer is validated before use. Any doubt is an
 * OsvUnavailable, so a broken source can never look like "no advisories".
 *
 * Privacy: the only data sent is the package ecosystem, name and installed version of the
 * components of this deployment (no customer data, no identifiers of this installation).
 */
final class OsvClient
{
    public const BATCH_URL = 'https://api.osv.dev/v1/querybatch';

    public const VULN_URL = 'https://api.osv.dev/v1/vulns/';

    public const PUBLIC_URL = 'https://osv.dev/vulnerability/';

    private const ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{2,62}$/';

    private const VERSION = '/^[0-9][0-9A-Za-z.+~-]{0,62}$/';

    /**
     * @param  list<DetectedComponent>  $components  checkable components only
     * @return array<string, list<string>> component key => advisory ids
     */
    public function affecting(array $components): array
    {
        $result = [];
        foreach (array_chunk($components, 500) as $chunk) {
            $payload = ['queries' => array_map(fn (DetectedComponent $c) => [
                'package' => ['ecosystem' => $c->ecosystem, 'name' => $c->name], 'version' => $c->version,
            ], $chunk)];
            $answers = $this->request('post', self::BATCH_URL, $payload)['results'] ?? null;
            if (! is_array($answers) || ! array_is_list($answers) || count($answers) !== count($chunk)) {
                throw new OsvUnavailable('osv_batch_shape_invalid');
            }
            foreach ($chunk as $index => $component) {
                $answer = $answers[$index];
                if (! is_array($answer) || isset($answer['next_page_token']) || (array_key_exists('vulns', $answer) && ! is_array($answer['vulns']))) {
                    throw new OsvUnavailable('osv_batch_entry_invalid');
                }
                $ids = [];
                foreach ($answer['vulns'] ?? [] as $vuln) {
                    $id = is_array($vuln) && is_string($vuln['id'] ?? null) ? $vuln['id'] : '';
                    if (preg_match(self::ID, $id) !== 1) {
                        throw new OsvUnavailable('osv_advisory_id_invalid');
                    }
                    $ids[$id] = true;
                }
                $result[$component->key] = array_keys($ids);
            }
        }

        return $result;
    }

    /**
     * @return array{aliases: list<string>, severity: string, summary: ?string, published_at: ?Carbon, fixed_versions: list<string>, withdrawn: bool}
     */
    public function details(string $id, DetectedComponent $component): array
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw new OsvUnavailable('osv_advisory_id_invalid');
        }
        $data = $this->request('get', self::VULN_URL.rawurlencode($id));
        if (($data['id'] ?? null) !== $id) {
            throw new OsvUnavailable('osv_advisory_mismatch');
        }

        $aliases = [];
        foreach (is_array($data['aliases'] ?? null) ? $data['aliases'] : [] as $alias) {
            if (is_string($alias) && preg_match(self::ID, $alias) === 1) {
                $aliases[] = $alias;
            }
        }
        $severity = match (strtoupper(is_string($data['database_specific']['severity'] ?? null) ? $data['database_specific']['severity'] : '')) {
            'CRITICAL' => 'critical', 'HIGH' => 'high', 'MODERATE', 'MEDIUM' => 'medium', 'LOW' => 'low',
            default => 'unknown',   // never derived or estimated
        };
        $summary = is_string($data['summary'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $data['summary'])) : '';
        $published = null;
        if (is_string($data['published'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T[0-9:.]+Z$/', $data['published']) === 1) {
            try {
                $published = Carbon::parse($data['published'])->utc();
            } catch (Throwable) {
                $published = null;
            }
        }

        return [
            'aliases' => array_values(array_unique($aliases)),
            'severity' => $severity,
            'summary' => $summary === '' ? null : mb_substr($summary, 0, 500),
            'published_at' => $published,
            'fixed_versions' => $this->fixedVersions($data, $component),
            'withdrawn' => isset($data['withdrawn']),   // a withdrawn advisory is not an advisory any more
        ];
    }

    /**
     * Fixed versions that belong to a range which CONTAINS the installed version. Only version
     * ranges (ECOSYSTEM, SEMVER) count: a GIT range names commits, not versions.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function fixedVersions(array $data, DetectedComponent $component): array
    {
        $fixed = [];
        foreach (is_array($data['affected'] ?? null) ? $data['affected'] : [] as $affected) {
            if (! is_array($affected) || ($affected['package']['ecosystem'] ?? null) !== $component->ecosystem
                || strcasecmp((string) ($affected['package']['name'] ?? ''), $component->name) !== 0) {
                continue;
            }
            foreach (is_array($affected['ranges'] ?? null) ? $affected['ranges'] : [] as $range) {
                if (! is_array($range) || ! in_array($range['type'] ?? null, ['ECOSYSTEM', 'SEMVER'], true)) {
                    continue;
                }
                $introduced = null;
                foreach (is_array($range['events'] ?? null) ? $range['events'] : [] as $event) {
                    if (! is_array($event)) {
                        continue;
                    }
                    if (is_string($event['introduced'] ?? null)) {
                        $introduced = $event['introduced'] === '0' ? '0' : ltrim($event['introduced'], 'v');
                    }
                    $version = is_string($event['fixed'] ?? null) ? ltrim($event['fixed'], 'v') : null;
                    if ($version !== null && preg_match(self::VERSION, $version) === 1 && $introduced !== null
                        && version_compare($introduced, (string) $component->version, '<=') && version_compare($version, (string) $component->version, '>')) {
                        $fixed[] = $version;
                    }
                }
            }
        }

        return array_values(array_unique($fixed));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $limit = (int) config('platform_updates.max_response_bytes', 5 * 1024 * 1024);
        $tooLarge = false;
        try {
            $pending = Http::timeout((int) config('platform_updates.timeout', 10))
                ->connectTimeout((int) config('platform_updates.connect_timeout', 5))
                ->withOptions([
                    'allow_redirects' => false,
                    // Abort as soon as the announced or the received size exceeds the cap (before it is held in memory).
                    'on_headers' => function ($response) use ($limit, &$tooLarge): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            $tooLarge = true;
                            throw new \RuntimeException('too_large');
                        }
                    },
                    'progress' => function ($downloadTotal, $downloaded) use ($limit, &$tooLarge): void {
                        if ($downloaded > $limit) {
                            $tooLarge = true;
                            throw new \RuntimeException('too_large');
                        }
                    },
                ])
                ->withHeaders(['User-Agent' => 'WP-CareGrid-Platform-Updates/1', 'Accept-Encoding' => 'identity'])   // no compressed answers: the size cap counts what is held
                ->acceptJson();
            $response = $method === 'post' ? $pending->asJson()->post($url, $payload) : $pending->get($url);
        } catch (Throwable) {
            throw new OsvUnavailable($tooLarge ? 'osv_response_too_large' : 'osv_unreachable');
        }
        if ($response->status() !== 200) {
            throw new OsvUnavailable($response->status() === 429 ? 'osv_rate_limited' : 'osv_http_error');
        }
        $body = $response->body();
        if (strlen($body) > $limit) {
            throw new OsvUnavailable('osv_response_too_large');
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new OsvUnavailable('osv_response_not_json');
        }

        return $decoded;
    }
}
