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
        private bool $closeFileAfterStore = false,
    ) {}

    public function __destruct()
    {
        $this->closeOwnedFile();
    }

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
    /** @var null|array<array-key, mixed> */
    private ?array $writeOptions = null;

    private ?string $destination = null;

    /**
     * Опции записи, уходящие в putFileAs. Нужны там, где объекту в хранилище
     * надо проставить заголовок (Content-Disposition для скачиваемых вложений).
     *
     * @param  null|array<array-key, mixed>  $options
     */
    public function withWriteOptions(?array $options): static
    {
        $this->writeOptions = $options;

        return $this;
    }

    /** Каталог внутри бакета вместо пути от path-генератора. */
    public function toDestination(?string $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function toMediaCollection(string $collectionName = 'default', string $disk = ''): Media
    {
        try {
            return $this->model->addMedia(
                file: $this->file,
                collectionName: $collectionName,
                name: $this->name ?? $this->fileName,
                disk: $disk ?: null,
                metadata: $this->metadata,
                destination: $this->destination,
                options: $this->writeOptions,
            );
        } finally {
            $this->closeOwnedFile();
        }
    }

    private function closeOwnedFile(): void
    {
        if ($this->closeFileAfterStore && is_resource($this->file)) {
            fclose($this->file);
        }
    }
}
