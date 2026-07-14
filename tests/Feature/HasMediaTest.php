<?php

declare(strict_types=1);

use Elegantly\Media\Exceptions\UnresolvableMediaUrlException;
use Elegantly\Media\Jobs\DeleteModelMediaJob;
use Elegantly\Media\MediaCollection;
use Elegantly\Media\Tests\Models\TestCollections;
use Elegantly\Media\Tests\Models\TestSoftDelete;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('gets the correct media collection', function () {
    $model = new TestCollections;

    $collection = $model->getMediaCollection('single');

    expect($collection)->toBeInstanceOf(MediaCollection::class);
    expect($collection->name)->toBe('single');
});

it('gets the fallback value when no media exist', function () {
    $model = new TestCollections;

    expect($model->getFirstMediaUrl('fallback'))->toBe('fallback-value');
});

it('retreives the media url', function () {
    Storage::fake('media');
    $model = new TestCollections;
    $model->save();

    $model->addMedia(
        file: UploadedFile::fake()->image('foo.jpg'),
        disk: 'media',
        collectionName: 'multiple',
    );

    expect($model->getFirstMediaUrl('multiple'))->not->toBe(null);

});

it('adds a new media to the default collection', function () {
    Storage::fake('media');
    $model = new TestCollections;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media = $model->addMedia(
        file: $file,
        disk: 'media'
    );

    expect($media->model_id)->toBe($model->id);
    expect($media->model_type)->toBe(get_class($model));
    expect($media->exists)->toBe(true);
    expect($media->name)->toBe('foo');
    expect($media->extension)->toBe('jpg');
    expect($media->file_name)->toBe('foo.jpg');

    expect($media->collection_name)->toBe(config('media.default_collection_name'));
    expect($media->collection_group)->toBe(null);

    Storage::disk('media')->assertExists($media->path);
});

it('adds a new media to the specified collection', function () {
    Storage::fake('media');
    $model = new TestCollections;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media = $model->addMedia(
        file: $file,
        collectionName: 'multiple',
        disk: 'media'
    );

    expect($media->collection_name)->toBe('multiple');
    expect($media->collection_group)->toBe(null);
});

it('adds a new media to the specified collection group', function () {
    Storage::fake('media');
    $model = new TestCollections;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media = $model->addMedia(
        file: $file,
        collectionName: 'multiple',
        collectionGroup: 'group',
        disk: 'media'
    );

    expect($media->collection_name)->toBe('multiple');
    expect($media->collection_group)->toBe('group');
});

it('creates and reuses media from a permanent url without storing a file', function () {
    config()->set('media.url_disk_aliases', [
        'https://cdn.example.com/bucket' => 'media',
    ]);

    Storage::fake('media');

    $model = new TestCollections;
    $model->save();

    $media = $model->createMediaFromUrl(
        url: 'https://cdn.example.com/bucket/articles/cover.jpg',
        collectionName: 'multiple',
        metadata: ['source' => 'editorjs'],
        attributes: [
            'width' => 1600,
            'height' => 900,
            'mime_type' => 'image/jpeg',
        ],
    );

    $sameMedia = $model->createMediaFromUrl(
        url: 'https://cdn.example.com/bucket/articles/cover.jpg',
        collectionName: 'multiple',
    );

    expect($sameMedia->is($media))->toBeTrue()
        ->and($model->media()->count())->toBe(1)
        ->and($media->model_id)->toBe($model->id)
        ->and($media->collection_name)->toBe('multiple')
        ->and($media->collection_group)->toBeNull()
        ->and($media->disk)->toBe('media')
        ->and($media->path)->toBe('articles/cover.jpg')
        ->and($media->file_name)->toBe('cover.jpg')
        ->and($media->name)->toBe('cover')
        ->and($media->extension)->toBe('jpg')
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($media->size)->toBe(0)
        ->and($media->width)->toBe(1600)
        ->and($media->height)->toBe(900)
        ->and($media->aspect_ratio)->toBe(1.78)
        ->and($media->metadata)->toBe([
            'source' => 'editorjs',
            'source_url' => 'https://cdn.example.com/bucket/articles/cover.jpg',
        ]);

    Storage::disk('media')->assertMissing('articles/cover.jpg');
});

it('rejects urls that do not resolve to a permanent disk and path', function (string $url) {
    config()->set('media.url_disk_aliases', [
        'https://cdn.example.com/bucket' => 'media',
    ]);

    Storage::fake('media');

    $model = new TestCollections;
    $model->save();

    expect(fn () => $model->createMediaFromUrl($url, 'multiple'))
        ->toThrow(UnresolvableMediaUrlException::class)
        ->and($model->media()->count())->toBe(0);
})->with([
    'temporary relative url' => '/storage/editorjs-tmp/cover.jpg',
    'unknown external host' => 'https://images.example.org/cover.jpg',
]);

it('deletes old media when adding to single collection', function () {
    Storage::fake('media');
    $model = new TestCollections;
    $model->save();

    $firstMedia = $model->addMedia(
        file: UploadedFile::fake()->image('foo.jpg'),
        disk: 'media',
        collectionName: 'single',
    );

    Storage::disk('media')->assertExists($firstMedia->path);

    expect($model->media)->toHaveLength(1);

    $secondMedia = $model->addMedia(
        file: UploadedFile::fake()->image('foo.jpg'),
        disk: 'media',
        collectionName: 'single',
    );

    Storage::disk('media')->assertMissing($firstMedia->path);
    Storage::disk('media')->assertExists($secondMedia->path);

    expect($model->media)->toHaveLength(1);

    expect($firstMedia->fresh())->toBe(null);
});

it('deletes media and its files with the model when delete_media_with_model is true', function () {
    config()->set('media.delete_media_with_model', true);

    Queue::fake();
    Storage::fake('media');

    $model = new TestCollections;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg');

    $media = $model->addMedia(
        file: $file,
        disk: 'media'
    );

    Storage::disk('media')->assertExists($media->path);

    $model->delete();

    Queue::assertPushed(DeleteModelMediaJob::class, 1);

});

it('does not delete media with the model when delete_media_with_model is false', function () {
    config()->set('media.delete_media_with_model', false);

    Queue::fake();
    Storage::fake('media');

    $model = new TestCollections;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg');

    $media = $model->addMedia(
        file: $file,
        disk: 'media'
    );

    Storage::disk('media')->assertExists($media->path);

    $model->delete();

    Queue::assertPushed(DeleteModelMediaJob::class, 0);

});

it('deletes media with the trashed model when delete_media_with_trashed_model is true', function () {
    config()->set('media.delete_media_with_model', true);
    config()->set('media.delete_media_with_trashed_model', true);

    Queue::fake();
    Storage::fake('media');

    $model = new TestSoftDelete;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg');

    $media = $model->addMedia(
        file: $file,
        disk: 'media'
    );

    Storage::disk('media')->assertExists($media->path);

    $model->delete();

    Queue::assertPushed(DeleteModelMediaJob::class, 1);
});

it('does not delete media with the trashed model when delete_media_with_trashed_model is false', function () {
    config()->set('media.delete_media_with_model', true);
    config()->set('media.delete_media_with_trashed_model', false);

    Queue::fake();
    Storage::fake('media');

    $model = new TestSoftDelete;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg');

    $media = $model->addMedia(
        file: $file,
        disk: 'media'
    );

    Storage::disk('media')->assertExists($media->path);

    $model->delete();

    Queue::assertPushed(DeleteModelMediaJob::class, 0);
});

it('deletes media with the force deleted model when delete_media_with_trashed_model is false', function () {
    config()->set('media.delete_media_with_model', true);
    config()->set('media.delete_media_with_trashed_model', false);

    Queue::fake();
    Storage::fake('media');

    $model = new TestSoftDelete;
    $model->save();

    $file = UploadedFile::fake()->image('foo.jpg');

    $media = $model->addMedia(
        file: $file,
        disk: 'media'
    );

    Storage::disk('media')->assertExists($media->path);

    $model->forceDelete();

    Queue::assertPushed(DeleteModelMediaJob::class, 1);
});
