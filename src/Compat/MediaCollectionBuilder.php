<?php

declare(strict_types=1);

namespace Elegantly\Media\Compat;

use Closure;
use Elegantly\Media\MediaCollection;
use Elegantly\Media\Models\Media;

/**
 * Spatie-compatible fluent collection builder.
 *
 * Bridges Spatie's chained API:
 *   $this->addMediaCollection('avatar')->useDisk('s3')->singleFile();
 * to elegantly's declarative:
 *   new MediaCollection(name: 'avatar', disk: 's3', single: true);
 */
class MediaCollectionBuilder
{
    private ?string $disk = null;

    private bool $single = false;

    /** @var string[]|null */
    private ?array $acceptedMimeTypes = null;

    private ?string $fallbackDisk = null;

    private ?Closure $onFallbackStored = null;

    public function __construct(private string $name) {}

    public function useDisk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Диск, на который файл пишется, если основной диск коллекции его не принял.
     * Колбэк вызывается после успешной записи на резерв.
     *
     * @param  null|(Closure(Media $media, string $failedDisk, string $fallbackDisk): void)  $onFallbackStored
     */
    public function useFallbackDisk(string $disk, ?Closure $onFallbackStored = null): static
    {
        $this->fallbackDisk = $disk;
        $this->onFallbackStored = $onFallbackStored;

        return $this;
    }

    public function singleFile(): static
    {
        $this->single = true;

        return $this;
    }

    /**
     * @param  string[]  $mimeTypes
     */
    public function acceptsMimeTypes(array $mimeTypes): static
    {
        $this->acceptedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Build the MediaCollection object from accumulated settings.
     */
    public function build(): MediaCollection
    {
        return new MediaCollection(
            name: $this->name,
            disk: $this->disk,
            single: $this->single,
            acceptedMimeTypes: $this->acceptedMimeTypes,
            fallbackDisk: $this->fallbackDisk,
            onFallbackStored: $this->onFallbackStored,
        );
    }
}
