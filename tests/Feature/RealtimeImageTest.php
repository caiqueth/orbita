<?php

declare(strict_types=1);

/**
 * The display layer resizes on the fly; animated sources are never format-converted.
 */

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Support\ImageUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function realtimeMedia(string $path, string $ext, bool $animated, string $mime): Media
{
    $user = User::factory()->createOne();

    return Media::create([
        'file_hash' => hash('sha256', $path),
        'file_name' => basename($path),
        'mime_type' => $mime,
        'file_extension' => $ext,
        'file_type' => 'image',
        'media_type' => Media::TYPE_POST,
        'path' => $path,
        'file_size' => 123,
        'metadata' => ['width' => 80, 'height' => 80, 'animated' => $animated],
        'uploaded_by' => $user->id,
        'upload_ip' => '127.0.0.1',
        'status' => 'active',
    ]);
}

/** Writes a real 3-frame animated GIF onto the fake disk. */
function putAnimatedGif(string $path): void
{
    $gif = new Imagick;
    foreach (['#ff0000', '#00ff00', '#0000ff'] as $color) {
        $frame = new Imagick;
        $frame->newImage(80, 80, new ImagickPixel($color));
        $frame->setImageFormat('gif');
        $frame->setImageDelay(20);
        $gif->addImage($frame);
    }
    $gif->setImageFormat('gif');

    Storage::disk('local')->put($path, $gif->getImagesBlob());
}

function putStaticPng(string $path): void
{
    $im = imagecreatetruecolor(400, 200);
    ob_start();
    imagepng($im);
    Storage::disk('local')->put($path, (string) ob_get_clean());
    imagedestroy($im);
}

function countFrames(string $bytes, string $ext): int
{
    $tmp = tempnam(sys_get_temp_dir(), 'frm').'.'.$ext;
    file_put_contents($tmp, $bytes);
    $probe = new Imagick($tmp);
    $n = $probe->getNumberImages();
    $probe->clear();
    $probe->destroy();
    @unlink($tmp);

    return $n;
}

it('animated media url forces the source format', function () {
    $media = realtimeMedia('posts/anim.gif', 'gif', animated: true, mime: 'image/gif');

    // Even when the caller asks for avif, the URL must request gif.
    $url = ImageUrl::media($media, ['width' => 40, 'format' => 'avif']);

    expect($url)->toContain('fm=gif');
    expect($url)->not->toContain('fm=avif');
});

it('static media url keeps the source format', function () {
    $media = realtimeMedia('posts/pic.png', 'png', animated: false, mime: 'image/png');

    // No format conversion at the endpoint (Cloudflare handles WebP/AVIF at the edge).
    $url = ImageUrl::media($media, ['width' => 800, 'format' => 'avif']);

    expect($url)->toContain('fm=png');
    expect($url)->not->toContain('fm=avif');
    expect($url)->not->toContain('fm=webp');
});

it('unsigned request is served publicly and cacheably', function () {
    Storage::fake('local');
    putStaticPng('posts/pic.png');

    // The /s/{path} endpoint is intentionally unsigned so URLs are infinitely cacheable.
    $response = get('/s/posts/pic.png?w=100');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    // cache.headers sorts directives alphabetically when setting Cache-Control.
    $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

it('directory path returns 404 instead of crashing the decoder', function () {
    Storage::fake('local');
    putStaticPng('posts/2026/10/pic.png');

    get('/s/posts/2026/10/')->assertNotFound();
    get('/s/posts/2026/10')->assertNotFound();
});

it('static png keeps its format and is resized', function () {
    Storage::fake('local');
    putStaticPng('posts/pic.png');
    $media = realtimeMedia('posts/pic.png', 'png', animated: false, mime: 'image/png');

    // A stale/forged URL asking for avif must still serve the source png, resized.
    $response = get(ImageUrl::media($media, ['width' => 100, 'format' => 'avif']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');

    $info = getimagesizefromstring((string) $response->getContent());
    expect($info[0])->toBe(100);
});

it('animated gif keeps every frame when resized', function () {
    Storage::fake('local');
    putAnimatedGif('posts/anim.gif');
    $media = realtimeMedia('posts/anim.gif', 'gif', animated: true, mime: 'image/gif');

    $response = get(ImageUrl::media($media, ['width' => 40]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');

    expect(countFrames((string) $response->getContent(), 'gif'))->toBe(3);
});

/**
 * A hand-crafted URL asking for avif on an animated source is refused by the controller.
 */
it('endpoint refuses to convert an animated source even if asked', function () {
    Storage::fake('local');
    putAnimatedGif('posts/anim.gif');

    $url = route('image', [
        'path' => 'posts/anim.gif', 'fm' => 'avif', 'w' => 40,
    ]);

    $response = get($url);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');

    expect(countFrames((string) $response->getContent(), 'gif'))->toBe(3);
});

/**
 * The cache key must use the corrected (source) format, not the requested one.
 */
it('cached animated image is still served as gif on the second request', function () {
    Storage::fake('local');
    putAnimatedGif('posts/anim.gif');

    $url = route('image', ['path' => 'posts/anim.gif', 'fm' => 'avif', 'w' => 40]);

    get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');

    $second = get($url);
    $second->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect(countFrames((string) $second->getContent(), 'gif'))->toBe(3, 'cache hit must keep every frame');
});

it('second request is served from the disk cache', function () {
    Storage::fake('local');
    putStaticPng('posts/pic.png');
    $media = realtimeMedia('posts/pic.png', 'png', animated: false, mime: 'image/png');
    $url = ImageUrl::media($media, ['width' => 50]);

    get($url)->assertOk();

    expect(Storage::disk('local')->allFiles('img-cache'))
        ->toHaveCount(1, 'first request must write exactly one cache entry');

    get($url)->assertOk();

    expect(Storage::disk('local')->allFiles('img-cache'))
        ->toHaveCount(1, 'second request must reuse it');
});
