<?php

declare(strict_types=1);

namespace Elegantly\Media\PathGenerators;

use Elegantly\Media\Models\Media;
use Elegantly\Media\Models\MediaConversion;
use Illuminate\Support\Stringable;

/**
 * Path generator that uses media ID as the directory name.
 *
 * Produces paths compatible with Spatie MediaLibrary's DefaultPathGenerator:
 *   - Media: {prefix}/{id}/filename
 *   - Conversion: {prefix}/{id}/conversions/{conversion_name}/{uuid}/filename
 *
 * Special cases (via metadata):
 *   - bunny_stream disk: {bunny_media_id}/filename
 *   - metadata['path']: uses the stored path directly
 */
class IdPathGenerator extends AbstractPathGenerator
{
    /**
     * @example {prefix}/{id}/
     * @example {bunny_media_id}/ (for bunny_stream disk)
     */
    public function media(Media $media): Stringable
    {
        // Bunny Stream: use bunny_media_id from metadata
        if ($media->disk === 'bunny_stream') {
            $bunnyMediaId = data_get($media->metadata, 'bunny_media_id');

            if (empty($bunnyMediaId)) {
                return str('');
            }

            return str($bunnyMediaId)->finish('/');
        }

        // Custom path stored in metadata (e.g. for migrated files)
        if ($path = data_get($media->metadata, 'path')) {
            return str($path)->finish('/');
        }

        // Default: {prefix}/{id}/
        if ($this->prefix) {
            return str($this->prefix)
                ->finish('/')
                ->append((string) $media->getKey())
                ->finish('/');
        }

        return str((string) $media->getKey())->finish('/');
    }

    /**
     * @example {prefix}/{id}/conversions/{conversion_name}/{uuid}/
     */
    public function conversion(
        Media $media,
        MediaConversion $mediaConversion,
    ): Stringable {
        return $this->media($media)
            ->append('conversions/')
            ->append(str_replace('.', '/conversions/', $mediaConversion->conversion_name))
            ->finish('/')
            ->append($mediaConversion->uuid)
            ->finish('/');
    }
}
