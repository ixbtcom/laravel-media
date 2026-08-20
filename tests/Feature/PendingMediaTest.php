<?php

declare(strict_types=1);

use Elegantly\Media\Enums\MediaState;
use Elegantly\Media\Events\MediaAddedEvent;
use Elegantly\Media\Models\Media;
use Elegantly\Media\Tests\Models\TestCollections;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function makePendingMedia(TestCollections $model, string $tempPath = 'editorjs-tmp/foo.jpg', bool $withFile = true): Media
{
    if ($withFile) {
        $upload = UploadedFile::fake()->image(basename($tempPath), width: 16, height: 9);
        Storage::disk('temp')->putFileAs(dirname($tempPath), $upload, basename($tempPath));
    }

    return $model->addPendingMedia(
        tempDisk: 'temp',
        tempPath: $tempPath,
        collectionName: 'multiple',
        name: 'media-abc',
        disk: 'media',
        metadata: ['source' => 'editorjs'],
        width: 16,
        height: 9,
        mimeType: 'image/jpeg',
        size: 123,
    );
}

it('treats missing state as ready (NULL ≡ ready for pre-existing rows)', function () {
    $media = Media::factory()->create();

    $media->refresh();

    expect($media->state)->toBeNull();
    expect($media->isReady())->toBeTrue();
    expect($media->isPending())->toBeFalse();
});

it('casts explicit state values to MediaState enum', function () {
    $media = Media::factory()->create(['state' => MediaState::Pending]);

    $media->refresh();

    expect($media->state)->toBe(MediaState::Pending);
    expect($media->isPending())->toBeTrue();
    expect($media->isReady())->toBeFalse();
});

it('creates pending media without a file and without MediaAddedEvent', function () {
    Storage::fake('temp');
    Event::fake([MediaAddedEvent::class]);

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);

    expect($media->exists)->toBeTrue();
    expect($media->state)->toBe(MediaState::Pending);
    expect($media->path)->toBeNull();
    expect($media->getUrl())->toBeNull();
    expect($media->width)->toBe(16);
    expect($media->mime_type)->toBe('image/jpeg');
    expect($media->metadata['source'])->toBe('editorjs');
    expect($media->metadata['pending_temp'])->toBe([
        'disk' => 'temp',
        'path' => 'editorjs-tmp/foo.jpg',
        'target_disk' => 'media',
        'node' => config('media.local_node'),
    ]);
    expect($media->model_id)->toBe($model->id);

    Event::assertNotDispatched(MediaAddedEvent::class);
});

it('rejects local pending media when the node identity is missing', function () {
    config()->set('media.local_node', null);
    Storage::fake('temp');

    $model = new TestCollections;
    $model->save();

    expect(fn () => makePendingMedia($model))->toThrow(LogicException::class);
});

it('finalizePending copies the temp file to the final disk and fires MediaAddedEvent once', function () {
    Storage::fake('temp');
    Storage::fake('media');
    Event::fake([MediaAddedEvent::class]);

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);

    expect($media->finalizePending())->toBeTrue();

    $media->refresh();

    expect($media->state)->toBe(MediaState::Ready);
    expect($media->isReady())->toBeTrue();
    expect($media->disk)->toBe('media');
    expect($media->path)->not->toBeNull();
    expect(Storage::disk('media')->exists($media->path))->toBeTrue();
    // Temp-файл сохраняется до cleanup-джобы: открытый редактор ссылается
    // на temp-URL до пересохранения формы.
    expect(Storage::disk('temp')->exists('editorjs-tmp/foo.jpg'))->toBeTrue();
    expect($media->metadata)->not->toHaveKey('pending_temp');

    // Идемпотентность: повторный вызов — no-op, событие не дублируется.
    expect($media->finalizePending())->toBeTrue();
    Event::assertDispatchedTimes(MediaAddedEvent::class, 1);
});

it('finalizePending marks failed on missing temp file and recovers on retry', function () {
    Storage::fake('temp');
    Storage::fake('media');
    Event::fake([MediaAddedEvent::class]);

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model, withFile: false);

    expect($media->finalizePending())->toBeFalse();
    $media->refresh();
    expect($media->state)->toBe(MediaState::Failed);
    Event::assertNotDispatched(MediaAddedEvent::class);

    // Ретрай из failed после появления temp-файла доводит до ready.
    $upload = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);
    Storage::disk('temp')->putFileAs('editorjs-tmp', $upload, 'foo.jpg');

    expect($media->finalizePending())->toBeTrue();
    $media->refresh();
    expect($media->state)->toBe(MediaState::Ready);
    Event::assertDispatchedTimes(MediaAddedEvent::class, 1);
});

it('does not finalize a local temp file owned by another node', function () {
    config()->set('media.local_node', 'admin-ixbt-site');
    Storage::fake('temp');
    Storage::fake('media');
    Event::fake([MediaAddedEvent::class]);

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);

    config()->set('media.local_node', 'admin-ixbt-su');

    expect($media->finalizePending())->toBeFalse();
    expect($media->refresh()->state)->toBe(MediaState::Pending);
    expect(Storage::disk('temp')->exists('editorjs-tmp/foo.jpg'))->toBeTrue();
    Event::assertNotDispatched(MediaAddedEvent::class);
});

it('does not mark an unowned legacy temp as failed when it is missing locally', function () {
    config()->set('media.local_node', 'admin-ixbt-site');
    Storage::fake('temp');
    Storage::fake('media');

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model, withFile: false);
    $metadata = $media->metadata;
    unset($metadata['pending_temp']['node']);
    $media->update(['metadata' => $metadata]);

    expect($media->finalizePending())->toBeFalse();
    expect($media->refresh()->state)->toBe(MediaState::Pending);
});

it('stays ready when post-ready side effects throw (recoverability guard)', function () {
    Storage::fake('temp');
    Storage::fake('media');

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);

    // Реальный listener, кидающий исключение ПОСЛЕ ready-save (Event::fake тут не нужен).
    Event::listen(MediaAddedEvent::class, function () {
        throw new RuntimeException('listener exploded');
    });

    expect($media->finalizePending())->toBeTrue();

    $media->refresh();

    // Файл финален — откат в failed сделал бы Media невосстановимой (pending_temp уже снят).
    expect($media->state)->toBe(MediaState::Ready);
    expect(Storage::disk('media')->exists($media->path))->toBeTrue();
});

it('deleting pending media removes its temp file', function () {
    Storage::fake('temp');

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);

    expect(Storage::disk('temp')->exists('editorjs-tmp/foo.jpg'))->toBeTrue();

    $media->delete();

    expect(Storage::disk('temp')->exists('editorjs-tmp/foo.jpg'))->toBeFalse();
    expect(Media::query()->find($media->id))->toBeNull();
});

it('deleting pending media from another node preserves its local temp file', function () {
    config()->set('media.local_node', 'admin-ixbt-site');
    Storage::fake('temp');

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);

    config()->set('media.local_node', 'admin-ixbt-su');
    $media->delete();

    expect(Storage::disk('temp')->exists('editorjs-tmp/foo.jpg'))->toBeTrue();
    expect(Media::query()->find($media->id))->toBeNull();
});

it('deleting legacy pending media ignores an unavailable temp disk', function () {
    Storage::fake('temp');

    $model = new TestCollections;
    $model->save();

    $media = makePendingMedia($model);
    $metadata = $media->metadata;
    unset($metadata['pending_temp']['node']);
    $metadata['pending_temp']['disk'] = 'missing-temp-disk';
    $media->update(['metadata' => $metadata]);

    $media->delete();

    expect(Media::query()->find($media->id))->toBeNull();
});

it('finalizePending copies from a remote temp disk without a local path', function () {
    // Вложения и ролики EditorJS лежат на S3-temp: локального пути у такого
    // объекта нет, и финализация обязана копировать из хранилища в хранилище.
    Storage::fake('temp');
    Storage::fake('media');
    config()->set('filesystems.disks.temp.driver', 's3');

    $model = new TestCollections;
    $model->save();

    // Содержимое — настоящая шапка mp4: тип и расширение объекта определяются
    // по нему, и подделка тут проверяла бы не перенос, а сам фейк.
    $payload = "\x00\x00\x00\x18ftypisomiso2avc1mp41\x00\x00\x00\x08mdat";
    Storage::disk('temp')->put('editorjs-tmp/clip.mp4', $payload);

    $media = $model->addPendingMedia(
        tempDisk: 'temp',
        tempPath: 'editorjs-tmp/clip.mp4',
        collectionName: 'multiple',
        name: 'media-abc',
        disk: 'media',
        mimeType: 'video/mp4',
        size: 32,
    );

    expect($media->metadata['pending_temp'])->not->toHaveKey('node');
    expect($media->finalizePending())->toBeTrue();

    $media->refresh();

    expect($media->state)->toBe(MediaState::Ready);
    expect($media->disk)->toBe('media');
    expect($media->file_name)->toBe('media-abc.mp4');
    expect($media->extension)->toBe('mp4');
    expect($media->mime_type)->toBe('video/mp4');
    expect($media->size)->toBe(32);
    expect(Storage::disk('media')->get($media->path))->toBe($payload);
});
