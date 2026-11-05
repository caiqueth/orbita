<?php

namespace App\Http\Controllers;

use App\Support\ImageDriver;
use App\Support\ImageFormats;
use App\Support\ImageUrl;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * On-the-fly image processing endpoint (intervention/image v4).
 */
class ImageController extends Controller
{
    private const MAX_DIMENSION = 4000;

    private const DIMENSION_STEP = 50;

    private const ANIMATION_PROBE_TTL = 2592000;

    public function show(Request $request, string $path): Response
    {
        $disk = Storage::disk('local');

        if (! $this->pathIsSafe($path) || ! $disk->fileExists($path)) {
            abort(404);
        }

        $format = $this->format($request->query('fm'), $path);
        $width = $this->dimension($request->query('w'));
        $height = $this->dimension($request->query('h'));
        $quality = max(1, min(100, (int) config('orbita.images.quality', 82)));
        $fit = $request->query('fit') === 'crop' ? 'crop' : 'contain';

        // Correct client-controlled format before the cache key is computed.
        if ($this->sourceIsAnimated($disk, $path)) {
            $sourceExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'gif';
            $format = in_array($sourceExtension, ImageUrl::FORMATS, true) ? $sourceExtension : 'gif';
        }

        $cacheKey = $this->cacheKey($path, $format, $width, $height, $quality, $fit);

        if ($disk->fileExists($cacheKey)) {
            return $this->respond($disk->get($cacheKey), $format);
        }

        // Reuse the probe's decode if available; avoid decoding the source twice.
        $image = $this->decodedSource ?? (new ImageManager($this->driver(), strip: true))->decodePath($disk->path($path));

        if ($width !== null || $height !== null) {
            if ($fit === 'crop' && $width !== null && $height !== null) {
                $image->cover($width, $height);
            } else {
                // scale() never upsizes past the intrinsic size and keeps the aspect ratio.
                $image->scaleDown(width: $width, height: $height);
            }
        }

        $bytes = (string) $image->encodeUsingFileExtension($format, quality: $quality);

        $disk->put($cacheKey, $bytes);

        return $this->respond($bytes, $format);
    }

    /** Set when the animation probe had to decode; show() reuses it instead of decoding again. */
    private ?ImageInterface $decodedSource = null;

    /**
     * Whether the SOURCE file holds an animation, cached per path and size.
     */
    private function sourceIsAnimated(Filesystem $disk, string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! ImageFormats::isAnimatable($extension)) {
            return false;
        }

        $key = 'img:animated:'.sha1($path.'|'.$disk->size($path));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $this->decodedSource = (new ImageManager($this->driver(), strip: true))->decodePath($disk->path($path));
            $animated = $this->decodedSource->isAnimated();
        } catch (\Throwable) {
            $animated = false;
        }

        Cache::put($key, $animated ? 1 : 0, self::ANIMATION_PROBE_TTL);

        return $animated;
    }

    private function driver(): DriverInterface
    {
        return ImageDriver::make();
    }

    /**
     * Output format is the source file's format; WebP/AVIF is never emitted.
     */
    private function format(?string $value, string $path): string
    {
        $value = strtolower((string) $value);

        if (in_array($value, ImageUrl::FORMATS, true)) {
            return $value;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        return in_array($ext, ImageUrl::FORMATS, true) ? $ext : 'jpg';
    }

    private function pathIsSafe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function dimension(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $requested = max(1, min(self::MAX_DIMENSION, (int) $value));

        $snapped = (int) (ceil($requested / self::DIMENSION_STEP) * self::DIMENSION_STEP);

        return min(self::MAX_DIMENSION, $snapped);
    }

    private function cacheKey(string $path, string $format, ?int $w, ?int $h, int $q, string $fit): string
    {
        $prefix = (string) config('orbita.images.cache_prefix', 'img-cache');
        $hash = sha1(implode('|', [$path, $format, $w ?? '', $h ?? '', $q, $fit]));

        return sprintf('%s/%s/%s.%s', $prefix, substr($hash, 0, 2), $hash, $format);
    }

    private function respond(string $bytes, string $format): Response
    {
        $mime = match ($format) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => 'image/webp',
        };

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
