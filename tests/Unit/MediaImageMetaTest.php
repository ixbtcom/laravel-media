<?php

declare(strict_types=1);

use Elegantly\Media\Models\Media;

it('записывает размеры, thumbhash и основной цвет из meta', function (): void {
    $media = Media::factory()->create([
        'width' => null,
        'height' => null,
        'aspect_ratio' => null,
        'average_color' => null,
        'metadata' => null,
    ]);

    $changed = $media->applyImageMeta([
        'width' => 2400,
        'height' => 1600,
        'thumbhash' => '3OcRJYB4d3h/iIeHeEh3eIhw+j3A',
        'average_color' => ['r' => 99, 'g' => 172, 'b' => 229],
    ]);

    expect($changed)->toBeTrue()
        ->and($media->width)->toBe(2400)
        ->and($media->height)->toBe(1600)
        ->and($media->aspect_ratio)->toEqual(1.5)
        ->and($media->metadata['thumbhash'] ?? null)->toBe('3OcRJYB4d3h/iIeHeEh3eIhw+j3A')
        ->and($media->average_color)->toBe('#63ace5');
});

it('не затирает сохранённые значения пустой meta', function (): void {
    $media = Media::factory()->create([
        'width' => 10,
        'height' => 20,
        'aspect_ratio' => 0.5,
        'average_color' => '#000000',
        'metadata' => ['thumbhash' => 'old'],
    ]);

    expect($media->applyImageMeta([]))->toBeFalse()
        ->and($media->applyImageMeta([
            'width' => 0,
            'height' => 0,
            'thumbhash' => '',
            'average_color' => null,
        ]))->toBeFalse()
        ->and($media->width)->toBe(10)
        ->and($media->height)->toBe(20)
        ->and($media->average_color)->toBe('#000000')
        ->and($media->metadata['thumbhash'])->toBe('old');
});

it('сохраняет прежний thumbhash рядом с новыми полями metadata', function (): void {
    $media = Media::factory()->create([
        'metadata' => ['image_block_id' => 42],
    ]);

    $media->applyImageMeta(['thumbhash' => 'hash']);

    expect($media->metadata['image_block_id'])->toBe(42)
        ->and($media->metadata['thumbhash'])->toBe('hash');
});
