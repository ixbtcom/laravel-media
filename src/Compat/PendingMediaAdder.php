<?php

declare(strict_types=1);

namespace Elegantly\Media\Compat;

use Elegantly\Media\Concerns\HasMedia;
use Elegantly\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;

/**
 * Spatie-compatible fluent media adder.
 *
 * Bridges Spatie's chained API:
 *   $model->addMediaFromUrl($url)->usingName('cover')->toMediaCollection('covers');
 * to elegantly's single-call:
 *   $model->addMedia($url, collectionName: 'covers', name: 'cover');
 *
 * @mixin HasMedia
 */
class PendingMediaAdder
{
    private ?string $name = null;

    private ?string $fileName = null;

    private ?array $metadata = null;

    private bool $preserveOriginal = false;

    /**
     * @param  Model&HasMedia  $model
     * @param  string|UploadedFile|File|resource  $file
     */
    public function __construct(
        private Model $model,
        private mixed $file,
    ) {}

    public function usingName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function usingFileName(string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function withCustomProperties(array $properties): static
    {
        $this->metadata = array_merge($this->metadata ?? [], $properties);

        return $this;
    }

    public function preservingOriginal(): static
    {
        $this->preserveOriginal = true;

        return $this;
    }

    /**
     * Terminal method — stores the file into the collection.
     */
    public function toMediaCollection(string $collectionName = 'default', string $disk = ''): Media
    {
        return $this->model->addMedia(
            file: $this->file,
            collectionName: $collectionName,
            name: $this->name ?? $this->fileName,
            disk: $disk ?: null,
            metadata: $this->metadata,
        );
    }
}
