<?php

declare(strict_types=1);

use Elegantly\Media\Models\Media;
use Elegantly\Media\PathGenerators\IdPathGenerator;

it('uses string and integer media keys', function (int|string $key, string $expected): void {
    $media = (new Media(['id' => $key]))
        ->setKeyType(is_string($key) ? 'string' : 'int');

    expect((new IdPathGenerator('prefix'))->media($media)->value())->toBe($expected);
})->with([
    'integer key' => [42, 'prefix/42/'],
    'string key' => ['media-42', 'prefix/media-42/'],
]);

it('ignores non-string metadata paths and uses the media key', function (): void {
    $media = new Media(['id' => 42, 'metadata' => ['path' => ['invalid']]]);

    expect((new IdPathGenerator('prefix'))->media($media)->value())->toBe('prefix/42/');
});

it('rejects a media without a string or integer key', function (): void {
    $media = (new Media)->setKeyType('string');

    expect(fn () => (new IdPathGenerator)->media($media))
        ->toThrow(LogicException::class);
});
