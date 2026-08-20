<?php

declare(strict_types=1);

namespace Elegantly\Media\Concerns;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Elegantly\Media\Enums\MediaType;
use Elegantly\Media\Helpers\File;
use Elegantly\Media\TemporaryDirectory;
use Elegantly\Media\UrlFormatters\AbstractUrlFormatter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File as HttpFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * @property ?MediaType $type
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $extension
 * @property ?string $name
 * @property ?string $file_name
 * @property ?string $mime_type
 * @property ?int $size in bytes
 * @property ?int $height
 * @property ?int $width
 * @property ?float $aspect_ratio
 * @property ?float $duration in Ms
 *
 * @mixin Model
 */
trait InteractWithFiles
{
    /**
     * @return class-string<AbstractUrlFormatter>
     */
    public function getDefaultUrlFormatter(): string
    {
        /** @var class-string<AbstractUrlFormatter> */
        $formatter = config()->string('media.default_url_formatter');

        return $formatter;
    }

    public function dirname(): ?string
    {

        if ($this->path) {
            return dirname($this->path);
        }

        return null;
    }

    public function getDisk(): ?Filesystem
    {
        if (! $this->disk) {
            return null;
        }

        return Storage::disk($this->disk);
    }

    /**
     * @param  null|array<array-key, mixed>  $parameters
     * @param  null|class-string<AbstractUrlFormatter>  $formatter
     */
    public function getUrl(
        ?array $parameters = null,
        ?string $formatter = null
    ): ?string {
        if (! $this->path) {
            return null;
        }

        $url = $this->getDisk()?->url($this->path);

        if ($url) {
            $formatter ??= $this->getDefaultUrlFormatter();

            return (new $formatter)->format($url, $parameters);
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @param  null|array<array-key, mixed>  $parameters
     * @param  null|class-string<AbstractUrlFormatter>  $formatter
     */
    public function getTemporaryUrl(
        DateTimeInterface $expiration,
        array $options = [],
        ?array $parameters = null,
        ?string $formatter = null,
    ): ?string {
        if (! $this->path) {
            return null;
        }

        $url = $this->getDisk()?->temporaryUrl($this->path, $expiration, $options);

        if ($url) {
            $formatter ??= $this->getDefaultUrlFormatter();

            return (new $formatter)->format($url, $parameters);
        }

        return null;
    }

    /**
     * @return null|resource
     */
    public function readStream()
    {
        if (! $this->path) {
            return null;
        }

        return $this->getDisk()?->readStream($this->path);
    }

    public function deleteFile(): bool
    {
        if (! $this->path) {
            return true;
        }

        $filesystem = $this->getDisk();

        if ($filesystem?->exists($this->path)) {
            return (bool) $filesystem->delete($this->path);
        }

        return true;
    }

    public function deleteDirectory(): bool
    {
        $dirname = $this->dirname();

        if (! $dirname) {
            return true;
        }

        $filesystem = $this->getDisk();

        if (! $filesystem) {
            return true;
        }

        return $filesystem->deleteDirectory($dirname);
    }

    /**
     * @return ?string The new file path on success, null on failure
     */
    /**
     * Кладёт на диск объект, уже лежащий в другом хранилище, серверной копией —
     * не протаскивая его через приложение. Нужно там, где файл принят
     * presigned-загрузкой на temp-бакет: скачать гигабайты во временный файл и
     * залить обратно значило бы удвоить трафик и упереться в таймаут PHP.
     *
     * false — копия неприменима (разные провайдеры, чужой драйвер): вызывающий
     * код тянет файл во временный каталог и идёт обычным путём.
     *
     * @param  null|array<array-key, mixed>  $options
     */
    public function putFileFromDisk(
        string $sourceDisk,
        string $sourcePath,
        string $disk,
        ?string $destination,
        string $name,
        ?array $options = null,
    ): string|null|false {
        $this->disk = $disk;

        $source = Storage::disk($sourceDisk);
        $destination = Str::rtrim($destination ?? '', '/');
        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $name = File::sanitizeFilename($name);
        $fileName = $extension !== '' ? "{$name}.{$extension}" : $name;
        $path = $destination !== '' ? "{$destination}/{$fileName}" : $fileName;

        if (! $this->copyObjectWithinProvider($sourceDisk, $sourcePath, $disk, $path, $options)) {
            return false;
        }

        $this->path = $path;
        $this->name = $name;
        $this->extension = $extension !== '' ? $extension : null;
        $this->file_name = $fileName;
        $this->mime_type ??= $source->mimeType($sourcePath) ?: null;
        $this->size = $source->size($sourcePath);
        $this->type = MediaType::tryFromMimeType($this->mime_type ?? '');

        return $path;
    }

    /**
     * Серверная копия внутри одного S3-провайдера. false — провайдер не тот,
     * бакеты за разными эндпоинтами или копия не удалась: вызывающий код
     * откатывается на поток.
     *
     * SHORTCUT: одиночный CopyObject, потолок S3 — 5 ГБ на объект. Ролики
     * ограничены четырьмя, так что влезают. Триггер на multipart-копию —
     * первое повышение лимита видео выше 5 ГБ.
     *
     * @param  null|array<array-key, mixed>  $options
     */
    private function copyObjectWithinProvider(
        string $sourceDisk,
        string $sourcePath,
        string $targetDisk,
        string $targetPath,
        ?array $options,
    ): bool {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);

        if (! $source instanceof AwsS3V3Adapter || ! $target instanceof AwsS3V3Adapter) {
            return false;
        }

        $sourceEndpoint = config("filesystems.disks.{$sourceDisk}.endpoint");
        $targetEndpoint = config("filesystems.disks.{$targetDisk}.endpoint");

        if (! is_string($sourceEndpoint) || $sourceEndpoint !== $targetEndpoint) {
            return false;
        }

        $sourceBucket = config("filesystems.disks.{$sourceDisk}.bucket");
        $targetBucket = config("filesystems.disks.{$targetDisk}.bucket");

        if (! is_string($sourceBucket) || ! is_string($targetBucket)) {
            return false;
        }

        $arguments = [
            'Bucket' => $targetBucket,
            'Key' => $targetPath,
            'CopySource' => "{$sourceBucket}/{$sourcePath}",
        ];

        // Метаданные копии задаются только когда их просили: без REPLACE
        // провайдер переносит Content-Type исходного объекта как есть.
        $overrides = array_intersect_key(
            $options ?? [],
            ['ContentType' => true, 'ContentDisposition' => true, 'CacheControl' => true],
        );

        if ($overrides !== []) {
            $arguments = [...$arguments, ...$overrides, 'MetadataDirective' => 'REPLACE'];
        }

        try {
            $target->getClient()->copyObject($arguments);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    public function putFile(
        string $disk,
        string $destination,
        UploadedFile|HttpFile $file,
        string $name,
        ?array $options = null,
        ?string $extension = null,
    ): string|null|false {
        $this->disk = $disk;

        $destination = Str::rtrim($destination, '/');
        // Расширение переданное явно — от ключа в хранилище, откуда файл забрали:
        // на нём уже завязаны presigned-загрузка и temp-ссылка, а определение по
        // содержимому дало бы другое имя того же объекта.
        $extension ??= File::extension($file);

        $name = File::sanitizeFilename($name);

        $pathname = $file->getPathname();

        $fileName = $extension ? "{$name}.{$extension}" : $name;

        $path = $this->getDisk()?->putFileAs(
            $destination,
            $file,
            $fileName,
            $options ?? [],
        ) ?: null;

        $this->path = $path;
        $this->name = $name;
        $this->extension = $extension;
        $this->file_name = $fileName;

        $this->mime_type = File::mimeType($file);
        $this->size = $file->getSize();

        try {
            if ($dimension = File::dimension($pathname)) {
                $this->height = (int) $dimension->height;
                $this->width = (int) $dimension->width;
                $this->aspect_ratio = $dimension->getAspectRatio()->value;
            }

            $this->duration = File::duration($pathname);
        } catch (\Throwable $th) {
            report($th);
        }

        try {
            $this->type = File::type($pathname);
        } catch (\Throwable $th) {
            $this->type = MediaType::Other;
        }

        return $path;
    }

    /**
     * @return ?string The new file path on success, null on failure
     */
    public function copyFileTo(
        string|Filesystem $disk,
        string $path,
    ): ?string {
        $filesystem = $disk instanceof Filesystem ? $disk : Storage::disk($disk);

        $stream = $this->readStream();

        if (! $stream) {
            return null;
        }

        $result = $filesystem->writeStream(
            $path,
            $stream
        );

        return $result ? $path : null;
    }

    /**
     * @return ?string The new file path on success, null on failure
     */
    public function moveFileTo(
        string $disk,
        string $path,
    ): ?string {

        if ($disk === $this->disk && $path === $this->path) {
            return $path;
        }

        if ($this->copyFileTo($disk, $path)) {
            try {
                $this->deleteFile();
            } catch (\Throwable $th) {
                report($th);
            }

            $this->disk = $disk;
            $this->path = $path;
            $this->save();

            return $path;
        }

        return null;
    }

    /**
     * Transform the media file inside a temporary directory while keeping the same Model
     * Usefull to optimize or convert the media file afterwards
     *
     * @param  Closure(HttpFile $copy, \Spatie\TemporaryDirectory\TemporaryDirectory $temporaryDirectory): HttpFile  $transform
     * @return $this
     */
    public function transformFile(Closure $transform): static
    {

        TemporaryDirectory::callback(function ($temporaryDirectory) use ($transform) {
            /** Used to delete the old file at the end */
            $clone = clone $this;

            $disk = $this->disk;
            $path = $this->path;
            $name = $this->name;

            if (! $path || ! $disk || ! $name) {
                return $this;
            }

            $storage = TemporaryDirectory::storage($temporaryDirectory);

            $copy = $this->copyFileTo(
                disk: $storage,
                path: $path
            );

            if (! $copy) {
                return;
            }

            $file = $transform(
                new HttpFile($storage->path($copy)),
                $temporaryDirectory
            );

            $result = $this->putFile(
                disk: $disk,
                destination: dirname($path),
                file: $file,
                name: $name
            );

            if (
                $result &&
                $clone->path !== $this->path
            ) {
                $clone->deleteFile();
            }

            $this->save();

        });

        return $this;
    }

    public function humanReadableSize(
        int $precision = 0,
        ?int $maxPrecision = null
    ): ?string {
        if (! $this->size) {
            return null;
        }

        return Number::fileSize($this->size, $precision, $maxPrecision);
    }

    public function humanReadableDuration(
        ?int $syntax = null,
        bool $short = false,
        int $parts = CarbonInterval::NO_LIMIT,
        ?int $options = null
    ): ?string {
        if (! $this->duration) {
            return null;
        }

        return CarbonInterval::milliseconds($this->duration)
            ->cascade()
            ->forHumans($syntax, $short, $parts, $options);
    }

    /**
     * Whether the file is safe to use with <img>, <video>, etc. in the browser.
     */
    public function isBrowserSafe(): bool
    {
        return match ($this->type) {
            MediaType::Image => in_array($this->mime_type, ['image/apng', 'image/avif', 'image/gif', 'image/jpeg', 'image/png', 'image/svg', 'image/webp']),
            MediaType::Video => in_array($this->mime_type, ['video/mp4', 'video/x-m4v', 'video/webm']),
            MediaType::Audio => in_array($this->mime_type, ['audio/mpeg', 'audio/mp4', 'audio/webm', 'audio/wav']),
            MediaType::Pdf => true,
            default => false,
        };
    }
}
