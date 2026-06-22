<?php

namespace humhub\modules\fediverse\services;

/**
 * Guards outbound fetches against SSRF: only public, https hosts that do not
 * resolve to private/loopback/link-local/reserved IP ranges are allowed.
 */
class UrlSafety
{
    public static function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = trim($parts['host'], '[]'); // strip brackets from IPv6 literal

        $ips = self::resolve($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] resolved IPs (or the literal IP if host is already an IP) */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
