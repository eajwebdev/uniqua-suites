<?php

namespace App\Support;

use Closure;

/**
 * Stops a saved URL being used to reach this server's own network. Checked again before each
 * fetch, because a host that was public when saved can later resolve somewhere private.
 */
class PublicUrl
{
    /** Replaceable so tests need no real DNS. */
    public static ?Closure $resolver = null;

    public static function isSafe(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $addresses = self::resolve($parts['host']);
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return [trim($host, '[]')];
        }

        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_merge($v4, $v6);
    }
}
