<?php

declare(strict_types=1);

namespace Elegantly\Media\Concerns;

use Elegantly\Media\Compat\MediaCollectionBuilder;
use Elegantly\Media\Compat\PendingMediaAdder;
use Elegantly\Media\Enums\MediaState;
use Elegantly\Media\Events\MediaAddedEvent;
use Elegantly\Media\Exceptions\InvalidMimeTypeException;
use Elegantly\Media\Helpers\File as HelpersFile;
use Elegantly\Media\Jobs\DeleteModelMediaJob;
use Elegantly\Media\MediaCollection;
use Elegantly\Media\Models\Media;
use Elegantly\Media\Support\MediaUrlResolver;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\File;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * @mixin Model
 *
 * @template TMedia of Media
 *
 * @property Collection<int, TMedia> $media
 */
trait HasMedia
{
    public static function bootHasMedia()
    {
        static::deleting(function (Model $model) {

            if (! config('media.delete_media_with_model')) {
                return true;
            }

            $isSoftDeleting = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();

            if (
                $isSoftDeleting &&
                ! config('media.delete_media_with_trashed_model')
            ) {
                return true;
            }

            /** @var class-string<DeleteModelMediaJob> */
            $job = config('media.delete_media_with_model_job');

            $model->media->each(fn ($media) => dispatch(new $job($media)));

        });
    }

    /**
     * @return MorphMany<TMedia>
     */
    public function media(): MorphMany
    {
        return $this
            ->morphMany(config('media.model'), 'model')
            ->chaperone()
            ->orderByRaw('-order_column DESC')
            ->orderBy('id', 'asc');
    }

    /**
     * @return Arrayable<array-key, MediaCollection>|iterable<MediaCollection>|null
     */
    public function registerMediaCollections(): Arrayable|iterable|null
    {
        return [];
    }

    public function getMediaCollection(string $collectionName): ?MediaCollection
    {
        // Check builder-based collections first (Spatie compat)
        if ($builderCollection = $this->getMediaCollectionFromBuilders($collectionName)) {
            return $builderCollection;
        }

        return collect($this->registerMediaCollections())->firstWhere('name', $collectionName);
    }

    /**
     * @return Collection<int, TMedia>
     */
    public function getMedia(
        ?string $collectionName = null,
        ?string $collectionGroup = null
    ): Collection {
        return $this->media
            ->when($collectionName, fn ($collection) => $collection->where('collection_name', $collectionName))
            ->where('collection_group', $collectionGroup)
            ->values();
    }

    public function hasMedia(
        ?string $collectionName = null,
        ?string $collectionGroup = null
    ): bool {
        return $this->getMedia($collectionName, $collectionGroup)->isNotEmpty();
    }

    /**
     * @return TMedia
     */
    public function getFirstMedia(
        ?string $collectionName = null,
        ?string $collectionGroup = null
    ): ?Media {
        return $this->getMedia($collectionName, $collectionGroup)->first();
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function getFirstMediaUrl(
        ?string $collectionName = null,
        ?string $collectionGroup = null,
        ?string $conversion = null,
        ?array $parameters = null,
    ): ?string {
        $media = $this->getFirstMedia($collectionName, $collectionGroup);

        if ($url = $media?->getUrl(
            conversion: $conversion,
            parameters: $parameters
        )) {
            return $url;
        }

        if (
            $collectionName &&
            $collection = $this->getMediaCollection($collectionName)
        ) {
            return value($collection->fallback);
        }

        return null;
    }

    /**
     * @param  string|resource|UploadedFile|File  $file
     * @param  array<array-key, mixed>  $metadata
     * @param  array<array-key, mixed>  $attributes
     * @return TMedia
     */
    public function addMedia(
        mixed $file,
        ?string $collectionName = null,
        ?string $collectionGroup = null,
        ?string $name = null,
        ?string $disk = null,
        ?int $order = null,
        ?array $metadata = null,
        array $attributes = [],
        ?string $destination = null,
        ?array $options = null,
    ): Media {
        $collectionName ??= config('media.default_collection_name');

        /** @var class-string<TMedia> */
        $model = config('media.model');

        $media = new $model;
        $media->fill($attributes);
        $media->model()->associate($this);
        $media->collection_name = $collectionName;
        $media->collection_group = $collectionGroup;
        $media->order_column = $order;
        $media->metadata = $metadata;

        $collection = $collectionName ? $this->getMediaCollection($collectionName) : null;

        $media->storeFile(
            file: $file,
            destination: $destination,
            name: $name,
            disk: $disk ?? $collection?->disk,
            before: function ($file, $temporaryDirectory) use ($collection) {
                if ($acceptedMimeTypes = $collection?->acceptedMimeTypes) {
                    $mime = HelpersFile::mimeType($file);

                    if (! in_array($mime, $acceptedMimeTypes)) {
                        throw InvalidMimeTypeException::notAccepted(
                            $mime,
                            $acceptedMimeTypes
                        );
                    }
                }

                if ($transform = $collection?->transform) {
                    return $transform($file, $temporaryDirectory);
                }

                return $file;
            },
            options: $options,
        );

        if ($this->relationLoaded('media')) {
            $this->media->push($media);
        }

        if ($collection?->single) {
            $this->clearMediaCollection(
                collectionName: $collectionName,
                collectionGroup: $collectionGroup,
                except: [$media->id]
            );
        }

        $media->generateConversions(
            filter: fn ($definition) => $definition->immediate,
            force: true,
            withChildren: true,
            withForceChildren: true,
        );

        if ($onAdded = $collection?->onAdded) {
            $onAdded($media);
        }

        event(new MediaAddedEvent($media));

        return $media;
    }

    /**
     * Создаёт Media-запись БЕЗ физического файла (state=pending) для асинхронной
     * загрузки: файл уже лежит на temp-диске, копирование на финальный диск
     * выполняет Media::finalizePending() (defer/sweep). MediaAddedEvent здесь
     * НЕ кидается — он стреляет только после успешной финализации.
     *
     * @param  array<array-key, mixed>|null  $metadata
     * @return TMedia
     */
    public function addPendingMedia(
        string $tempDisk,
        string $tempPath,
        ?string $collectionName = null,
        ?string $collectionGroup = null,
        ?string $name = null,
        ?string $disk = null,
        ?array $metadata = null,
        ?int $width = null,
        ?int $height = null,
        ?string $mimeType = null,
        ?int $size = null,
    ): Media {
        $collectionName ??= config('media.default_collection_name');

        /** @var class-string<TMedia> */
        $model = config('media.model');

        $collection = $collectionName ? $this->getMediaCollection($collectionName) : null;

        $media = new $model;
        $media->model()->associate($this);
        $media->collection_name = $collectionName;
        $media->collection_group = $collectionGroup;
        $media->name = $name ?? pathinfo($tempPath, PATHINFO_FILENAME);
        $media->state = MediaState::Pending;
        $media->width = $width;
        $media->height = $height;
        $media->aspect_ratio = ($width && $height) ? $width / $height : null;
        $media->mime_type = $mimeType;
        $media->size = $size ?? 0;
        $pendingTemp = [
            'disk' => $tempDisk,
            'path' => $tempPath,
            'target_disk' => $disk ?? $collection?->disk,
        ];

        if (config("filesystems.disks.{$tempDisk}.driver", 'local') === 'local') {
            $localNode = trim((string) config('media.local_node'));

            if ($localNode === '') {
                throw new LogicException('media.local_node must identify the owner of local temporary files.');
            }

            $pendingTemp['node'] = $localNode;
        }

        $media->metadata = array_merge($metadata ?? [], ['pending_temp' => $pendingTemp]);
        $media->save();

        if ($this->relationLoaded('media')) {
            $this->media->push($media);
        }

        return $media;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @param  array<array-key, mixed>  $attributes
     * @return TMedia
     */
    public function createMediaFromUrl(
        string $url,
        ?string $collectionName = null,
        ?string $collectionGroup = null,
        ?array $metadata = null,
        array $attributes = [],
    ): Media {
        $collectionName ??= config('media.default_collection_name');
        $resolvedUrl = app(MediaUrlResolver::class)->resolve($url);

        $existingMedia = $this->media()
            ->where('collection_name', $collectionName)
            ->where('collection_group', $collectionGroup)
            ->where('disk', $resolvedUrl['disk'])
            ->where('path', $resolvedUrl['path'])
            ->first();

        if ($existingMedia) {
            return $existingMedia;
        }

        /** @var class-string<TMedia> $model */
        $model = config('media.model');
        $fileName = basename($resolvedUrl['path']);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: null;
        $name = pathinfo($fileName, PATHINFO_FILENAME) ?: $fileName;

        $media = new $model;
        $media->fill($attributes);
        $media->model()->associate($this);
        $media->collection_name = $collectionName;
        $media->collection_group = $collectionGroup;
        $media->disk = $resolvedUrl['disk'];
        $media->path = $resolvedUrl['path'];
        $media->file_name ??= $fileName;
        $media->extension ??= $extension;
        $media->name ??= $name;
        $media->size ??= 0;
        $media->metadata = [
            ...($metadata ?? []),
            'source_url' => $url,
        ];

        if (! $media->aspect_ratio && $media->width && $media->height) {
            $media->aspect_ratio = round($media->width / $media->height, 2);
        }

        $media->saveQuietly();

        if ($this->relationLoaded('media')) {
            $this->media->push($media);
        }

        return $media;
    }

    /**
     * @return $this
     */
    public function deleteMedia(int $mediaId): static
    {
        $this->media->find($mediaId)?->delete();

        $this->setRelation(
            'media',
            $this->media->except([$mediaId])
        );

        return $this;
    }

    /**
     * @return $this
     */
    public function clearMediaCollection(
        string $collectionName,
        ?string $collectionGroup = null,
        array $except = [],
    ): static {

        $media = $this->getMedia($collectionName, $collectionGroup)
            ->except($except)
            ->loadMissing(['conversions'])
            ->each(fn ($media) => $media->delete());

        $this->setRelation(
            'media',
            $this->media->except($media->modelKeys())
        );

        return $this;
    }

    /**
     * @return \Illuminate\Support\Collection<int, PendingDispatch>
     */
    public function dispatchMediaConversion(
        string $conversionName,
        bool $force = true,
        ?string $collectionName = null,
        ?string $collectionGroup = null,
        bool $withChildren = false,
        bool $withForceChildren = false,
    ): \Illuminate\Support\Collection {

        return $this
            ->getMedia($collectionName, $collectionGroup)
            ->toBase()
            ->map(function ($media) use ($conversionName, $force, $withChildren, $withForceChildren) {
                return $media->dispatchConversion(
                    conversion: $conversionName,
                    force: $force,
                    withChildren: $withChildren,
                    withForceChildren: $withForceChildren
                );
            })
            ->filter();

    }

    /**
     * @return Collection<int, TMedia>
     */
    public function deleteMediaConversion(
        string $conversionName,
        ?string $collectionName = null,
        ?string $collectionGroup = null,
    ): Collection {
        return $this
            ->getMedia($collectionName, $collectionGroup)
            ->each(function ($media) use ($conversionName) {
                return $media->deleteConversion($conversionName);
            });
    }

    // Spatie Compatibility Shim -------------------------------------------------------------------

    /**
     * @var array<string, MediaCollectionBuilder>
     */
    private array $_collectionBuilders = [];

    /**
     * Spatie-compatible: addMediaFromUrl($url)->usingName('cover')->toMediaCollection('covers')
     */
    public function addMediaFromUrl(string $url): PendingMediaAdder
    {
        return new PendingMediaAdder($this, $url);
    }

    /**
     * Spatie-compatible: addMediaFromDisk($path, $disk)->toMediaCollection('videos')
     */
    public function addMediaFromDisk(string $path, string $disk): PendingMediaAdder
    {
        $storage = Storage::disk($disk);
        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read media file [{$path}] from disk [{$disk}].");
        }

        return (new PendingMediaAdder($this, $stream, closeFileAfterStore: true))
            ->usingName(pathinfo($path, PATHINFO_FILENAME));
    }

    /**
     * Spatie-compatible: addMediaFromRequest('image')->toMediaCollection('photos')
     */
    public function addMediaFromRequest(string $key, ?Request $request = null): PendingMediaAdder
    {
        $request ??= request();
        $file = $request->file($key);

        return new PendingMediaAdder($this, $file);
    }

    /**
     * Spatie-compatible fluent collection builder.
     * Usage: $this->addMediaCollection('avatar')->useDisk('s3')->singleFile();
     *
     * Collections built this way are automatically resolved via getMediaCollection().
     */
    public function addMediaCollection(string $name): MediaCollectionBuilder
    {
        $builder = new MediaCollectionBuilder($name);
        $this->_collectionBuilders[$name] = $builder;

        return $builder;
    }

    /**
     * Resolve collection: check builder-based collections first, then declarative ones.
     */
    public function getMediaCollectionFromBuilders(string $collectionName): ?MediaCollection
    {
        // Ensure builders are populated (registerMediaCollections fills _collectionBuilders via addMediaCollection)
        if (empty($this->_collectionBuilders)) {
            $this->registerMediaCollections();
        }

        if (isset($this->_collectionBuilders[$collectionName])) {
            return $this->_collectionBuilders[$collectionName]->build();
        }

        return null;
    }

    // \ Spatie Compatibility Shim -----------------------------------------------------------------
}
