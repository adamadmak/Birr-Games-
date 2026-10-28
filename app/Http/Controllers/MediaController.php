<?php

namespace App\Http\Controllers;

use App\Models\SiteMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MediaController
{
    /**
     * Serve an operator-uploaded hero image from the database.
     *
     * The {version} segment changes whenever the image is replaced, so the URL
     * can be cached for a year without anyone ever seeing a stale picture. The
     * body is checked against a short list of raster types before it is sent:
     * a row can only ever be served as an image, never as markup.
     */
    public function hero(Request $request, string $slot, string $version): Response
    {
        abort_unless(in_array($slot, \App\Support\HeroBanner::SLOTS, true), 404);

        // Without the blob: a 304 (most hits, the asset is immutable) must not
        // read the whole image out of the database to decide it is unchanged.
        $row = SiteMedia::available()
            ? SiteMedia::query()->where('slot', 'hero-'.$slot)->first(['id', 'slot', 'mime', 'bytes', 'updated_at'])
            : null;

        abort_if($row === null || ! in_array($row->mime, SiteMedia::ALLOWED_MIMES, true), 404);

        $etag = '"'.md5($row->slot.$row->updated_at?->timestamp.$row->bytes).'"';

        $headers = [
            'Content-Type' => $row->mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $binary = base64_decode((string) SiteMedia::query()->whereKey($row->id)->value('data'), true);

        abort_if($binary === false || $binary === '', 404);

        return response($binary, 200, $headers + ['Content-Length' => (string) strlen($binary)]);
    }
}
