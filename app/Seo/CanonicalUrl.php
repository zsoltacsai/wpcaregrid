<?php

namespace App\Seo;

/** Canonicals are built from deployment configuration, never the Request Host header. */
final class CanonicalUrl
{
    public function forPath(string $path): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];
        $scheme = in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            ? strtolower((string) $parts['scheme'])
            : 'https';
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port.'/'.ltrim($path, '/');
    }
}
