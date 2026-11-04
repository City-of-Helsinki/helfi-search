<?php

declare(strict_types=1);

namespace App;

/**
 * Helpers for matching URLs to sites.
 */
final class Site
{
    /**
     * Returns the URL's origin: scheme, host and port, lowercased, with the
     * default port left out. "https://Nuorten.hel.fi:443/foo" becomes
     * "https://nuorten.hel.fi".
     */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;

        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return "$scheme://$host";
        }

        return "$scheme://$host:$port";
    }
}
