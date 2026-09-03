<?php
namespace Wtp\Shared\Security;

use Wtp\Shared\Support\Str;

/**
 * SSRF protection for every outbound fetch (theme packages, WordPress core,
 * remote preview images, the agent's callbacks).
 *
 * Rules:
 *  - scheme must be http or https (https only unless explicitly relaxed)
 *  - no credentials in the URL, no fragment smuggling
 *  - host must be a hostname or a public IP literal
 *  - every A/AAAA record must be a globally routable address
 *  - an optional host allow-list narrows it further
 *  - redirects must be re-validated by the caller (see resolveRedirect())
 */
final class UrlGuard
{
    /** @var string[] */
    private $allowedSchemes = ['https'];
    /** @var string[]|null */
    private $allowedHosts;
    /** @var bool */
    private $resolveDns = true;

    /**
     * @param string[] $allowedSchemes
     * @param string[]|null $allowedHosts exact host match, lowercase; null = any public host
     */
    public function __construct(array $allowedSchemes = ['https'], ?array $allowedHosts = null, bool $resolveDns = true)
    {
        $this->allowedSchemes = array_map('strtolower', $allowedSchemes);
        $this->allowedHosts = $allowedHosts === null ? null : array_map('strtolower', $allowedHosts);
        $this->resolveDns = $resolveDns;
    }

    /**
     * @return array{url:string,scheme:string,host:string,port:int,ips:string[]}
     * @throws SecurityException
     */
    public function validate(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new SecurityException('URL is empty or too long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new SecurityException('Control characters in URL');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SecurityException('Malformed URL');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, $this->allowedSchemes, true)) {
            throw new SecurityException('Scheme not allowed: ' . $scheme);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SecurityException('Credentials in URL are not allowed');
        }

        $host = strtolower($parts['host']);
        $host = trim($host, '[]'); // IPv6 literal
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new SecurityException('Invalid port');
        }

        if ($this->allowedHosts !== null && !in_array($host, $this->allowedHosts, true)) {
            throw new SecurityException('Host not in allow-list: ' . $host);
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!Str::isHostname($host)) {
                throw new SecurityException('Invalid hostname: ' . $host);
            }
            if ($this->resolveDns) {
                $ips = self::resolve($host);
                if (empty($ips)) {
                    throw new SecurityException('Host does not resolve: ' . $host);
                }
            }
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new SecurityException('Host resolves to a non-routable address');
            }
        }

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /** Re-validate a Location header against the original request. */
    public function validateRedirect(string $location, string $originalUrl): array
    {
        if (preg_match('#^https?://#i', $location) !== 1) {
            // relative redirect: resolve against the original origin
            $p = parse_url($originalUrl);
            $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            $location = $origin . '/' . ltrim($location, '/');
        }
        return $this->validate($location);
    }

    /** @return string[] */
    public static function resolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Globally routable? Rejects loopback, RFC1918, link-local, CGNAT, multicast,
     * reserved, IPv4-mapped IPv6 and unique-local IPv6.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            // 100.64.0.0/10 (CGNAT) is not covered by NO_RES_RANGE on every PHP build.
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
                return false;
            }
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return false;
            }
            // ::1 loopback, :: unspecified
            if ($packed === str_repeat("\0", 15) . "\1" || $packed === str_repeat("\0", 16)) {
                return false;
            }
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);
            if (($b0 & 0xFE) === 0xFC) {
                return false; // fc00::/7 unique local
            }
            if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
                return false; // fe80::/10 link local
            }
            if ($b0 === 0xFF) {
                return false; // multicast
            }
            // ::ffff:0:0/96 IPv4-mapped -> validate the embedded v4 address
            if (strncmp($packed, str_repeat("\0", 10) . "\xFF\xFF", 12) === 0) {
                return self::isPublicIp(inet_ntop(substr($packed, 12, 4)));
            }
            return true;
        }

        return false;
    }
}
