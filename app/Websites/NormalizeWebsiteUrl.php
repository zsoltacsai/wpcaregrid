<?php

namespace App\Websites;

final class NormalizeWebsiteUrl
{
    public function __invoke(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! $parts || empty($parts['host'])) {
            return $url;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }
}
