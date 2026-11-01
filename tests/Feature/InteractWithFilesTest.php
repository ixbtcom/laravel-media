<?php

declare(strict_types=1);

use Elegantly\Media\Database\Factories\MediaFactory;
use Elegantly\Media\Helpers\File;
use Elegantly\Media\Models\Media;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\File as HttpFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Image;

it('copies a file to a disk and path', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media->storeFile(
        file: $file,
        disk: 'media'
    );

    Storage::disk('media')->assertExists($media->path);

    Storage::fake('media-copy');

    $copy = $media->copyFileTo(
        disk: 'media-copy',
        path: $media->path
    );

    Storage::disk('media-copy')->assertExists($copy);
});

it('transforms a file and delete the original one', function () {
    /** @var Media $media */
    $media = MediaFactory::new()->make();

    Storage::fake('media');

    $file = UploadedFile::fake()->image('foo.jpg', width: 16, height: 9);

    $media->storeFile(
        file: $file,
        disk: 'media'
    );

    $path = $media->path;

    Storage::disk('media')->assertExists($path);

    $media->transformFile(function ($file) {

        $path = $file->getRealPath();
        $basename = dirname($path);
        $name = File::name($path);

        $new = "{$basename}/{$name}.png";

        Image::load($path)
            ->save($new);

        return new HttpFile($new);
    });

    Storage::disk('media')->assertMissing($path);

    Storage::disk('media')->assertExists($media->path);

    $path = $media->path;

    $media->transformFile(function ($file) {
        $path = $file->getRealPath();

        Image::load($path)
            ->optimize()
            ->save($path);

        return new HttpFile($path);
    });

    Storage::disk('media')->assertExists($path);

});

it('keeps the source content type when a server-side copy replaces metadata', function () {
    $client = new class
    {
        /** @var array<int, array<string, mixed>> */
        public array $copies = [];

        /** @param array<string, mixed> $arguments */
        public function headObject(array $arguments): array
        {
            return ['ContentType' => 'application/pdf'];
        }

        /** @param array<string, mixed> $arguments */
        public function copyObject(array $arguments): array
        {
            $this->copies[] = $arguments;

            return [];
        }
    };

    foreach (['temp' => 'temp-bucket', 'files' => 'files-bucket'] as $disk => $bucket) {
        config()->set("filesystems.disks.{$disk}", ['driver' => 's3', 'bucket' => $bucket, 'endpoint' => 'https://s3.example.test']);

        $adapter = Mockery::mock(AwsS3V3Adapter::class);
        $adapter->shouldReceive('getClient')->andReturn($client);
        $adapter->shouldReceive('mimeType')->andReturn('application/pdf');
        $adapter->shouldReceive('size')->andReturn(10);
        Storage::set($disk, $adapter);
    }

    /** @var Media $media */
    $media = MediaFactory::new()->make();

    $path = $media->putFileFromDisk('temp', 'uploads/doc.pdf', 'files', 'docs', 'doc', [
        'ContentDisposition' => 'attachment; filename="doc.pdf"',
    ]);

    expect($path)->toBe('docs/doc.pdf')
        ->and($client->copies)->toHaveCount(1)
        ->and($client->copies[0]['MetadataDirective'])->toBe('REPLACE')
        ->and($client->copies[0]['ContentType'] ?? null)->toBe('application/pdf');
});

it('falls back without copying when S3 metadata is not a string', function (string $option, mixed $value) {
    $client = new class
    {
        public int $copies = 0;

        public function headObject(array $arguments): array
        {
            return ['ContentType' => 'application/pdf'];
        }

        public function copyObject(array $arguments): array
        {
            $this->copies++;

            return [];
        }
    };

    foreach (['temp' => 'temp-bucket', 'files' => 'files-bucket'] as $disk => $bucket) {
        config()->set("filesystems.disks.{$disk}", ['driver' => 's3', 'bucket' => $bucket, 'endpoint' => 'https://s3.example.test']);

        $adapter = Mockery::mock(AwsS3V3Adapter::class);
        $adapter->shouldReceive('getClient')->andReturn($client);
        $adapter->shouldReceive('mimeType')->andReturn('application/pdf');
        $adapter->shouldReceive('size')->andReturn(10);
        Storage::set($disk, $adapter);
    }

    $media = MediaFactory::new()->make();

    expect($media->putFileFromDisk('temp', 'uploads/doc.pdf', 'files', 'docs', 'doc', [$option => $value]))
        ->toBeFalse()
        ->and($client->copies)->toBe(0);
})->with([
    'null content type' => ['ContentType', null],
    'array disposition' => ['ContentDisposition', ['attachment']],
    'boolean cache control' => ['CacheControl', false],
]);
