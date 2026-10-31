<?php

declare(strict_types=1);

use Elegantly\Media\FileDownloaders\HttpFileDownloader;
use Illuminate\Support\Facades\Http;
use Spatie\TemporaryDirectory\TemporaryDirectory;

it('download a file from an url as a temporary file', function () {

    $temporaryDirectory = (new TemporaryDirectory)
        ->location(storage_path('media-tmp'))
        ->create();

    $path = HttpFileDownloader::fromUrl(
        $this->dummy_pdf_url,
        $temporaryDirectory->path()
    );

    expect(is_file($path))->toBe(true);

    $temporaryDirectory->delete();

    expect(is_file($path))->toBe(false);
});

it('download a file from an url as a temporary file and sets the right extension', function () {
    $url = 'https://media.test/image';
    $contents = file_get_contents($this->getTestFile('images/800x900.png'));

    Http::fake([
        $url => Http::response($contents, 200, ['Content-Type' => 'image/png']),
    ]);

    $temporaryDirectory = (new TemporaryDirectory)
        ->location(storage_path('media-tmp'))
        ->create();

    try {
        $path = HttpFileDownloader::fromUrl($url, $temporaryDirectory->path());

        expect(is_file($path))->toBe(true);
        expect(str($path)->endsWith('.png'))->toBe(true);
        expect(file_get_contents($path))->toBe($contents);
    } finally {
        $temporaryDirectory->delete();
    }

    expect(is_file($path))->toBe(false);
});
