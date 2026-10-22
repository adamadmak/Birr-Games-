<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if ($request->secure() || $request->header('x-forwarded-proto') === 'https') {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set(
                'Content-Security-Policy',
                implode('; ', [
                    "default-src 'self'",
                    "base-uri 'self'",
                    "form-action 'self' https:",
                    "frame-ancestors 'self' https://*.telegram.org https://web.telegram.org https://telegram.org",
                    "object-src 'none'",
                    "img-src 'self' data: https: blob:",
                    "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
                    // challenges.cloudflare.com is Turnstile, the human check on the
                    // registration form; it loads a script and renders in a frame.
                    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://challenges.cloudflare.com https://telegram.org https://*.telegram.org https://*.telegram-cdn.org",
                    "connect-src 'self' https: wss: ws:",
                    $this->frameSrc($request),
                ])
            );
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /**
     * Where a page may embed a frame.
     *
     * Everywhere on the site this is a short allowlist: Telegram's web view,
     * and the aggregators' own launcher hosts.
     *
     * The provider game page is the exception, and it has to be. It embeds
     * exactly one third-party URL — the launch URL — and the aggregator's
     * launcher then navigates that frame ONWARD to whichever studio actually
     * hosts the game. SmartSoft serve theirs from eu-server.ssgportal.com;
     * every one of the hundred-odd studios in the QTech catalogue has its own,
     * and a new one appears whenever QTech sign a partner. An allowlist cannot
     * track that, and getting it wrong fails silently in the worst way: the
     * browser refuses the frame, nothing is logged server-side, and the player
     * is left staring at the launcher's splash or at an "open this link"
     * fallback that dumps them out of the site.
     *
     * So this one page lets the frame travel. What makes that acceptable is
     * that the URL placed there was already validated on the server:
     * QtechGameController::validatedLaunchUrl (and the BigBang equivalent)
     * refuse anything that is not https on a host the aggregator owns. Nothing
     * else on that page is framed, and every other page keeps the allowlist.
     */
    private function frameSrc(Request $request): string
    {
        if ($request->routeIs('games.provider.launch')) {
            return "frame-src 'self' https:";
        }

        return "frame-src 'self' https://challenges.cloudflare.com https://telegram.org https://*.telegram.org https://web.telegram.org"
            .' https://bigbangcasino.bet https://*.bigbangcasino.bet https://iframe.winhouse.bet'
            .' https://qtplatform.com https://*.qtplatform.com https://qtlauncher.com https://*.qtlauncher.com';
    }
}

