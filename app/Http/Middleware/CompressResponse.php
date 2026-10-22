<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip text responses before they leave the app.
 *
 * Nothing in front of this application compresses anything — a lobby page went
 * out as 1,556 KB of plain HTML, and the same bytes gzip to 85 KB. Eighteen
 * times the data, on a market that reaches this site almost entirely over
 * mobile connections it pays for by the megabyte.
 *
 * The weight is not waste in the page; it is a catalogue of ten thousand games
 * rendered as markup, which compresses extremely well precisely because it is
 * the same few tags repeated. So this is the cheapest large win available:
 * nothing about the page changes, only how much of it travels.
 *
 * Deliberately conservative. It skips anything already encoded, anything that
 * is not text, anything streamed or served from disk, and anything small
 * enough that the header costs more than the saving. `Vary: Accept-Encoding`
 * goes on every eligible response, compressed or not, so that a shared cache
 * can never hand a gzipped body to a client that did not ask for one.
 */
class CompressResponse
{
    /** Below this, the CPU and the extra header cost more than the saving. */
    private const MIN_BYTES = 1024;

    private const COMPRESSIBLE = [
        'text/html',
        'text/plain',
        'text/css',
        'text/xml',
        'application/json',
        'application/javascript',
        'application/xml',
        'image/svg+xml',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->eligible($request, $response)) {
            return $response;
        }

        // Announced even when we do not compress this particular response: the
        // decision depends on the request's Accept-Encoding, so any cache in
        // between has to key on it either way.
        $response->headers->set('Vary', 'Accept-Encoding', false);

        if (! str_contains(strtolower((string) $request->headers->get('Accept-Encoding')), 'gzip')) {
            return $response;
        }

        $body = (string) $response->getContent();
        if (strlen($body) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($body, 6);
        if ($compressed === false || strlen($compressed) >= strlen($body)) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        // The body is no longer the bytes the ETag was computed over.
        $response->headers->remove('ETag');

        return $response;
    }

    private function eligible(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        // 204 and 304 carry no body; a redirect's body is never worth it.
        if ($response->isEmpty() || $response->isRedirection()) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));

        foreach (self::COMPRESSIBLE as $compressible) {
            if (str_contains($type, $compressible)) {
                return true;
            }
        }

        return false;
    }
}
