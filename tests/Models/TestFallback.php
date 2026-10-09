<?php

declare(strict_types=1);

namespace Elegantly\Media\Tests\Models;

use Elegantly\Media\Contracts\InteractWithMedia;
use Elegantly\Media\Models\Media;
use Illuminate\Contracts\Support\Arrayable;
use RuntimeException;

class TestFallback extends Test implements InteractWithMedia
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public static array $fallbackCalls = [];

    public function registerMediaCollections(): Arrayable|iterable|null
    {
        $record = static function (Media $media, string $failedDisk, string $fallbackDisk): void {
            static::$fallbackCalls[] = [$media->uuid, $failedDisk, $fallbackDisk];
        };

        $this->addMediaCollection('with-fallback')
            ->useDisk('media')
            ->useFallbackDisk('fallback', $record);

        $this->addMediaCollection('same-fallback')
            ->useDisk('media')
            ->useFallbackDisk('media', $record);

        $this->addMediaCollection('no-fallback')
            ->useDisk('media');

        $this->addMediaCollection('throwing-callback')
            ->useDisk('media')
            ->useFallbackDisk('fallback', static fn () => throw new RuntimeException('owner update failed'));

        return null;
    }
}
