<?php

declare(strict_types=1);

namespace Elegantly\Media;

use Closure;
use Elegantly\Media\Models\Media;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class MediaCollection
{
    /**
     * @param  null|(string[])  $acceptedMimeTypes
     * @param  null|string|(Closure(): null|string)  $fallback
     * @param  null|(Closure(UploadedFile|File $file, TemporaryDirectory $temporaryDirectory): (UploadedFile|File))  $transform
     * @param  null|(Closure(Media $media): void)  $onAdded
     * @param  null|(Closure(Media $media, string $failedDisk, string $fallbackDisk): void)  $onFallbackStored
     */
    public function __construct(
        public string $name,
        public ?array $acceptedMimeTypes = null,
        public bool $single = false,
        public bool $public = false,
        public ?string $disk = null,
        public null|string|Closure $fallback = null,
        public ?Closure $transform = null,
        public ?Closure $onAdded = null,
        public ?string $fallbackDisk = null,
        public ?Closure $onFallbackStored = null,
    ) {}

}
