<?php

declare(strict_types=1);

use Elegantly\Media\Database\Factories\MediaFactory;
use Elegantly\Media\Models\Media;
use Elegantly\Media\Tests\Models\TestFallback;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;

function prepareMediaRelationSchema(): void
{
    if (Schema::hasColumn('media', 'relation_id')) {
        return;
    }

    Schema::table('media', function (Blueprint $table): void {
        $table->unsignedBigInteger('relation_id')->nullable();
        $table->string('relation_type', 64)->nullable();
        $table->index(['relation_id', 'relation_type']);
    });
}

it('stores an uploaded image', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media->storeFile(
        file: $file,
        disk: 'media'
    );

    expect($media->path)->toBe("{$media->uuid}/foo.jpg");
    expect($media->name)->toBe('foo');
    expect($media->extension)->toBe('jpg');
    expect($media->file_name)->toBe('foo.jpg');
    expect($media->mime_type)->toBe('image/jpeg');
    expect($media->width)->toBe(16);
    expect($media->height)->toBe(9);
    expect($media->aspect_ratio)->toBe(16 / 9);
    expect($media->duration)->toBe(null);
    expect($media->size)->toBe(695);

    Storage::disk('media')->assertExists($media->path);
});

it('stores an uploaded image using a prefixed path', function () {
    config()->set('media.generated_path_prefix', 'prefix');

    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media->storeFile(
        file: $file,
        disk: 'media'
    );

    expect($media->path)->toBe("prefix/{$media->uuid}/foo.jpg");
    expect($media->name)->toBe('foo');
    expect($media->extension)->toBe('jpg');
    expect($media->file_name)->toBe('foo.jpg');
    expect($media->mime_type)->toBe('image/jpeg');
    expect($media->width)->toBe(16);
    expect($media->height)->toBe(9);
    expect($media->aspect_ratio)->toBe(16 / 9);
    expect($media->duration)->toBe(null);
    expect($media->size)->toBe(695);

    Storage::disk('media')->assertExists($media->path);
});

it('stores an uploaded image with a custom name', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media->storeFile(
        file: $file,
        name: 'avatar',
        disk: 'media'
    );

    expect($media->path)->toBe("{$media->uuid}/avatar.jpg");
    expect($media->name)->toBe('avatar');
    expect($media->extension)->toBe('jpg');
    expect($media->file_name)->toBe('avatar.jpg');
    expect($media->mime_type)->toBe('image/jpeg');
    expect($media->width)->toBe(16);
    expect($media->height)->toBe(9);
    expect($media->aspect_ratio)->toBe(16 / 9);
    expect($media->duration)->toBe(null);
    expect($media->size)->toBe(695);

    Storage::disk('media')->assertExists($media->path);
});

it('stores an svg file', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $media->storeFile(
        file: $this->getTestFile('images/svg.svg'),
        disk: 'media',
    );

    expect($media->path)->toBe("{$media->uuid}/svg.svg");
    expect($media->name)->toBe('svg');
    expect($media->extension)->toBe('svg');
    expect($media->file_name)->toBe('svg.svg');
    expect($media->mime_type)->toBe('image/svg+xml');
    expect($media->width)->toBe(279);
    expect($media->height)->toBe(279);
    expect($media->aspect_ratio)->toBe(1.0);
    expect($media->duration)->toBe(null);
    expect($media->size)->toBe(1853);

    Storage::disk('media')->assertExists($media->path);
});

it('stores a pdf from an url', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $media->storeFile(
        file: $this->dummy_pdf_url,
        disk: 'media',
        name: 'document'
    );

    expect($media->path)->toBe("{$media->uuid}/document.pdf");
    expect($media->name)->toBe('document');
    expect($media->extension)->toBe('pdf');
    expect($media->file_name)->toBe('document.pdf');
    expect($media->mime_type)->toBe('application/pdf');
    expect($media->width)->toBe(null);
    expect($media->height)->toBe(null);
    expect($media->aspect_ratio)->toBe(null);
    expect($media->duration)->toBe(null);
    expect($media->size)->toBe(13264);

    Storage::disk('media')->assertExists($media->path);
});

it('retrieve the url', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    expect($media->getUrl())->toBe('/storage/{uuid}/empty.jpg');

});

it('retrieve the fallback url', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    expect($media->getUrl(
        conversion: 'poster',
        fallback: false,
    ))->toBe(null);

    expect($media->getUrl(
        conversion: 'poster',
        fallback: true,
    ))->toBe('/storage/{uuid}/empty.jpg');

    expect($media->getUrl(
        conversion: 'none',
        fallback: ['none-2', true],
    ))->toBe('/storage/{uuid}/empty.jpg');

    expect($media->getUrl(
        conversion: 'none',
        fallback: ['none-2'],
    ))->toBe(null);

    expect($media->getUrl(
        conversion: 'poster',
        fallback: true,
        parameters: [
            'width' => 200,
            'height' => 200,
        ]
    ))->toBe('/storage/{uuid}/empty.jpg?width=200&height=200');

});

it('retrieve the conversion url', function () {

    /** @var Media $media */
    $media = MediaFactory::new()->withPoster()->create();

    expect($media->getUrl(
        conversion: 'poster',
        fallback: false,
    ))->toBe('/storage/{uuid}/conversions/poster/poster.jpg');

    expect($media->getUrl(
        conversion: 'poster',
        fallback: false,
        parameters: [
            'width' => 200,
            'height' => 200,
        ]
    ))->toBe('/storage/{uuid}/conversions/poster/poster.jpg?width=200&height=200');

    expect($media->getUrl(
        conversion: 'none',
        fallback: ['poster'],
    ))->toBe('/storage/{uuid}/conversions/poster/poster.jpg');

});

it('store a file within a prefixed path', function () {
    config()->set('media.generated_path_prefix', 'media');

    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $file = $this->getTestFile('images/svg.svg');

    $media->storeFile(
        file: $file,
        disk: 'media',
        name: 'foo'
    );

    expect($media->name)->toBe('foo');
    expect($media->file_name)->toBe('foo.svg');
    expect($media->path)->toBe("media/{$media->uuid}/foo.svg");

    Storage::disk('media')->assertExists($media->path);
});

it('reorder models', function () {

    $first_media = MediaFactory::new()->create(['order_column' => 0]);
    $second_media = MediaFactory::new()->create(['order_column' => 1]);
    $third_media = MediaFactory::new()->create(['order_column' => 2]);

    Media::reorder([
        $third_media->getKey(),
        $first_media->getKey(),
        $second_media->getKey(),
    ]);

    expect($third_media->refresh()->order_column)->toBe(0);
    expect($first_media->refresh()->order_column)->toBe(1);
    expect($second_media->refresh()->order_column)->toBe(2);
});

it('reorder models using uuids', function () {

    $first_media = MediaFactory::new()->create(['order_column' => 0]);
    $second_media = MediaFactory::new()->create(['order_column' => 1]);
    $third_media = MediaFactory::new()->create(['order_column' => 2]);

    Media::reorder([
        $third_media->uuid,
        $first_media->uuid,
        $second_media->uuid,
    ], using: 'uuid');

    expect($third_media->refresh()->order_column)->toBe(0);
    expect($first_media->refresh()->order_column)->toBe(1);
    expect($second_media->refresh()->order_column)->toBe(2);
});

it('reorder models from a custom sequence', function () {

    $first_media = MediaFactory::new()->create(['order_column' => 0]);
    $second_media = MediaFactory::new()->create(['order_column' => 1]);
    $third_media = MediaFactory::new()->create(['order_column' => 2]);

    Media::reorder([
        $third_media->getKey(),
        $first_media->getKey(),
        $second_media->getKey(),
    ], sequence: fn (?int $previous) => ($previous === null ? 0 : ($previous + 2)));

    expect($third_media->refresh()->order_column)->toBe(0);
    expect($first_media->refresh()->order_column)->toBe(2);
    expect($second_media->refresh()->order_column)->toBe(4);
});

it('deletes the files in a directory when a directory media is deleted', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make([
        'disk' => 'media',
        'path' => 'videos/example/hls/master.m3u8',
        'mime_type' => 'application/vnd.apple.mpegurl',
        'metadata' => ['directory' => true],
    ]);

    Storage::fake('media');

    $media->save();

    Storage::disk('media')->put($media->path, '#EXTM3U');
    Storage::disk('media')->put('videos/example/hls/playlist-720p.m3u8', '#EXTM3U');
    Storage::disk('media')->put('videos/example/hls/segment-000.ts', 'segment');

    $media->delete();

    Storage::disk('media')->assertMissing('videos/example/hls/master.m3u8');
    Storage::disk('media')->assertMissing('videos/example/hls/playlist-720p.m3u8');
    Storage::disk('media')->assertMissing('videos/example/hls/segment-000.ts');
});

it('keeps sibling files when deleting a media without the directory flag', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make([
        'disk' => 'media',
        'path' => 'videos/example/poster/poster.webp',
        'mime_type' => 'image/webp',
    ]);

    Storage::fake('media');

    $media->save();

    Storage::disk('media')->put($media->path, 'poster');
    Storage::disk('media')->put('videos/example/poster/other.webp', 'other');

    $media->delete();

    Storage::disk('media')->assertMissing('videos/example/poster/poster.webp');
    Storage::disk('media')->assertExists('videos/example/poster/other.webp');
});

it('deletes ordinary media when relation columns are not installed yet', function () {
    if (Schema::hasColumn('media', 'relation_id')) {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['relation_id', 'relation_type']);
            $table->dropColumn(['relation_id', 'relation_type']);
        });
    }

    Storage::fake('media');
    $media = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/ordinary.png',
    ]);
    Storage::disk('media')->put($media->path, 'ordinary');

    $media->delete();

    expect(Media::query()->where('id', $media->id)->exists())->toBeFalse();
    Storage::disk('media')->assertMissing($media->path);
});

it('resolves parent and related media for arbitrary relation types', function () {
    prepareMediaRelationSchema();

    $parent = MediaFactory::new()->create();
    $child = MediaFactory::new()->create([
        'relation_id' => $parent->id,
        'relation_type' => 'video_cover',
    ]);

    expect($child->relation_id)->toBe($parent->id)
        ->and($child->relation_type)->toBe('video_cover')
        ->and($child->parentMedia->is($parent))->toBeTrue()
        ->and($parent->relatedMedia->modelKeys())->toBe([$child->id]);
});

it('deletes related media and their files when a parent is deleted', function () {
    prepareMediaRelationSchema();

    Storage::fake('media');

    $parent = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/parent.png',
    ]);
    $child = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/original.png',
        'relation_id' => $parent->id,
        'relation_type' => 'processing_source',
    ]);
    Storage::disk('media')->put($parent->path, 'result');
    Storage::disk('media')->put($child->path, 'original');

    $connection = $parent->getConnection();
    $readPdo = new PDO('sqlite::memory:');
    $readPdo->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, relation_id INTEGER, relation_type TEXT)');
    $readPdo->exec('CREATE TABLE media_conversions (id INTEGER PRIMARY KEY, media_id INTEGER)');
    $connection->setReadPdo($readPdo);
    $connection->forgetRecordModificationState();

    try {
        $parent->delete();
    } finally {
        $connection->setReadPdo(null);
    }

    expect(Media::query()->whereIn('id', [$parent->id, $child->id])->exists())->toBeFalse();
    Storage::disk('media')->assertMissing($parent->path);
    Storage::disk('media')->assertMissing($child->path);
});

it('keeps a parent media when a child is deleted', function () {
    prepareMediaRelationSchema();

    Storage::fake('media');

    $parent = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/parent.png',
    ]);
    $child = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/original.png',
        'relation_id' => $parent->id,
        'relation_type' => 'processing_source',
    ]);
    Storage::disk('media')->put($parent->path, 'result');
    Storage::disk('media')->put($child->path, 'original');

    $child->delete();

    expect(Media::query()->where('id', $parent->id)->exists())->toBeTrue();
    Storage::disk('media')->assertExists($parent->path);
    Storage::disk('media')->assertMissing($child->path);
});

it('deletes cyclic media relations without recursing indefinitely', function () {
    prepareMediaRelationSchema();

    Storage::fake('media');

    $first = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/first.png',
    ]);
    $second = MediaFactory::new()->create([
        'disk' => 'media',
        'path' => 'sources/second.png',
        'relation_id' => $first->id,
        'relation_type' => 'processing_source',
    ]);
    $first->forceFill([
        'relation_id' => $second->id,
        'relation_type' => 'processing_source',
    ])->save();
    Storage::disk('media')->put($first->path, 'first');
    Storage::disk('media')->put($second->path, 'second');

    $first->delete();

    expect(Media::query()->whereIn('id', [$first->id, $second->id])->exists())->toBeFalse();
    Storage::disk('media')->assertMissing($first->path);
    Storage::disk('media')->assertMissing($second->path);
});

it('adds media to the collection fallback disk when the main disk is down', function () {
    Storage::fake('fallback');
    failingDisk('media');
    Log::spy();
    TestFallback::$fallbackCalls = [];

    $model = new TestFallback;
    $model->save();

    $media = $model->addMedia(
        file: UploadedFile::fake()->image('foo.jpg', width: 16, height: 9),
        collectionName: 'with-fallback',
    );

    expect($media->disk)->toBe('fallback');
    expect($media->refresh()->disk)->toBe('fallback');
    Storage::disk('fallback')->assertExists($media->path);
    expect(TestFallback::$fallbackCalls)->toBe([[$media->uuid, 'media', 'fallback']]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Media stored to fallback disk'
        && $context['uuid'] === $media->uuid
        && $context['collection'] === 'with-fallback'
        && $context['disk'] === 'media'
        && $context['fallback_disk'] === 'fallback')->once();
});

it('reports both disks when the fallback disk is down too', function () {
    failingDisk('media', throw: true);
    failingDisk('fallback', throw: true);
    TestFallback::$fallbackCalls = [];

    $model = new TestFallback;
    $model->save();

    try {
        $model->addMedia(
            file: UploadedFile::fake()->image('foo.jpg', width: 16, height: 9),
            collectionName: 'with-fallback',
        );
        $this->fail('addMedia must throw when both disks are down');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain("'media'")->toContain("'fallback'")->toContain('disk is down');
        expect($e->getPrevious())->toBeInstanceOf(UnableToWriteFile::class);
    }

    expect(TestFallback::$fallbackCalls)->toBe([]);
});

it('keeps the fallback file when the owner callback throws', function () {
    Storage::fake('fallback');
    failingDisk('media');
    Log::spy();

    $model = new TestFallback;
    $model->save();

    $media = $model->addMedia(
        file: UploadedFile::fake()->image('foo.jpg', width: 16, height: 9),
        collectionName: 'throwing-callback',
    );

    expect($media->refresh()->disk)->toBe('fallback');
    Storage::disk('fallback')->assertExists($media->path);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'Media fallback disk callback failed')->once();
});
