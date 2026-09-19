<?php

declare(strict_types=1);

namespace Elegantly\Media\Compat;

use Elegantly\Media\MediaCollection;

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

    public function __construct(private string $name) {}

    public function useDisk(string $disk): static
    {
        $this->disk = $disk;

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
        );
    }
}
