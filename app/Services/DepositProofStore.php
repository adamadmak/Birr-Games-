<?php

namespace App\Services;

use App\Models\AgentOrder;
use App\Models\DepositProof;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Turns an uploaded payment screenshot into a stored, safe image.
 *
 * Every upload is decoded and re-encoded as a JPEG no wider or taller than
 * 1600 px: that strips location and device metadata, neutralises anything
 * hidden in the file, and keeps a phone screenshot around 150 KB while the
 * amount, reference and time on it stay readable.
 */
final class DepositProofStore
{
    private const MAX_SIDE = 1600;
    private const QUALITY = 80;

    public function store(AgentOrder $order, UploadedFile $file): DepositProof
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            throw new InvalidArgumentException('Screenshots cannot be processed on this server right now.');
        }

        $raw = (string) file_get_contents($file->getRealPath());
        $image = $raw !== '' ? @imagecreatefromstring($raw) : false;
        if (! $image) {
            throw new InvalidArgumentException('That file is not a picture we can read. Upload a JPG or PNG screenshot.');
        }

        try {
            [$width, $height] = [imagesx($image), imagesy($image)];
            $scale = min(1, self::MAX_SIDE / max($width, $height));
            $w = max(1, (int) round($width * $scale));
            $h = max(1, (int) round($height * $scale));

            // Onto a white canvas: a PNG screenshot with transparency would turn black as a JPEG.
            $canvas = imagecreatetruecolor($w, $h);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);

            ob_start();
            $ok = imagejpeg($canvas, null, self::QUALITY);
            $jpeg = (string) ob_get_clean();
            imagedestroy($canvas);
        } finally {
            imagedestroy($image);
        }

        if (! $ok || $jpeg === '') {
            throw new InvalidArgumentException('The screenshot could not be saved. Try again with a JPG or PNG.');
        }

        DepositProof::ensureTable();

        return DepositProof::query()->updateOrCreate(
            ['agent_order_id' => $order->id],
            [
                'user_id' => $order->user_id,
                'mime' => 'image/jpeg',
                'bytes' => strlen($jpeg),
                'width' => $w,
                'height' => $h,
                'data' => base64_encode($jpeg),
            ]
        );
    }
}
