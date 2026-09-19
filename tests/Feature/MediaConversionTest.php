<?php

declare(strict_types=1);

use Elegantly\Media\Database\Factories\MediaFactory;
use Elegantly\Media\Enums\MediaConversionState;
use Elegantly\Media\Enums\MediaType;
use Elegantly\Media\Models\Media;
use Elegantly\Media\Models\MediaConversion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Приложение не генерирует конверсии, а описывает уже существующие файлы строкой
 * в media_conversions - так же поступает и тест.
 */
function makeConversion(Media $media, string $name, UploadedFile $file): MediaConversion
{
    $path = "{$media->id}/conversions/{$name}.jpg";
    Storage::disk($media->disk)->put($path, $file->get());

    $conversion = new MediaConversion([
        'conversion_name' => $name,
        'state' => MediaConversionState::Succeeded,
        'disk' => $media->disk,
        'path' => $path,
        'name' => $name,
        'file_name' => "{$name}.jpg",
        'size' => $file->getSize(),
        'type' => MediaType::Image,
    ]);

    $media->conversions()->save($conversion);

    return $conversion;
}

it('deletes the files associated with the MediaConversion', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make([
        'disk' => 'media',
    ]);

    Storage::fake('media');

    $media->save();

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $conversion = makeConversion($media, 'poster', $file);

    Storage::disk('media')->assertExists($conversion->path);

    $conversion->deleteFile();

    Storage::disk('media')->assertMissing($conversion->path);

});

it('deletes the files in a directory conversion when the media is deleted', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make([
        'disk' => 'media',
    ]);

    Storage::fake('media');

    $media->save();

    $conversion = $media->conversions()->create([
        'conversion_name' => 'hls',
        'disk' => 'media',
        'path' => 'videos/example/hls/master.m3u8',
        'mime_type' => 'application/vnd.apple.mpegurl',
        'metadata' => ['directory' => true],
    ]);

    Storage::disk('media')->put($conversion->path, '#EXTM3U');
    Storage::disk('media')->put('videos/example/hls/playlist-720p.m3u8', '#EXTM3U');
    Storage::disk('media')->put('videos/example/hls/segment-000.ts', 'segment');

    $media->delete();

    Storage::disk('media')->assertMissing('videos/example/hls/master.m3u8');
    Storage::disk('media')->assertMissing('videos/example/hls/playlist-720p.m3u8');
    Storage::disk('media')->assertMissing('videos/example/hls/segment-000.ts');
});

it('keeps unmarked conversion siblings when deleting a media conversion', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make([
        'disk' => 'media',
    ]);

    Storage::fake('media');

    $media->save();

    $conversion = $media->conversions()->create([
        'conversion_name' => 'poster',
        'disk' => 'media',
        'path' => 'videos/example/poster/poster.webp',
        'mime_type' => 'image/webp',
    ]);

    Storage::disk('media')->put($conversion->path, 'poster');
    Storage::disk('media')->put('videos/example/poster/other.webp', 'other');

    $conversion->delete();

    Storage::disk('media')->assertMissing($conversion->path);
    Storage::disk('media')->assertExists('videos/example/poster/other.webp');
});

it('On MediaConversion deletion, it deletes the files', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make([
        'disk' => 'media',
    ]);

    Storage::fake('media');

    $media->save();

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $conversion = makeConversion($media, 'poster', $file);

    Storage::disk('media')->assertExists($conversion->path);

    $conversion->delete();

    Storage::disk('media')->assertMissing($conversion->path);

});
