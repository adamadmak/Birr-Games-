<?php

namespace App\Support;

/**
 * Cloudflare's proxy address ranges, trusted as forwarding proxies.
 *
 * vamosonebet.com went behind Cloudflare on 27 Oct 2026. Every request then
 * arrives from a Cloudflare edge, with the visitor's address appended to
 * X-Forwarded-For. With only the host's own ingress trusted, request()->ip()
 * became the Cloudflare edge — a handful of addresses shared by everyone in
 * the country — so the per-address sign-in and sign-up limits
 * (App\Support\AuthRateLimits, App\Services\RegistrationGuard) would have
 * closed the door on real players within minutes of a busy evening.
 *
 * Trusting these ranges lets Symfony walk X-Forwarded-For past the edge to
 * the visitor. A visitor cannot forge that: anything they put in the header
 * themselves sits to the LEFT of the address Cloudflare appends, and the walk
 * stops at the first untrusted address from the right.
 *
 * The list is Cloudflare's published one (cloudflare.com/ips-v4 and /ips-v6)
 * as of 27 Oct 2026. It changes rarely; when it does, update it here. It is a
 * literal because trusted proxies are set at bootstrap, before any database
 * or settings lookup is possible on this host.
 *
 * @see bootstrap/app.php
 */
final class Cloudflare
{
    /** @var list<string> */
    private const V4 = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    /** @var list<string> */
    private const V6 = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @return list<string> */
    public static function ranges(): array
    {
        return array_merge(self::V4, self::V6);
    }
}
