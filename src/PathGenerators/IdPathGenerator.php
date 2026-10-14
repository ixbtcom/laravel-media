<?php

declare(strict_types=1);

namespace Elegantly\Media\PathGenerators;

use Elegantly\Media\Models\Media;
use Elegantly\Media\Models\MediaConversion;
use Illuminate\Support\Stringable;
use LogicException;

/**
 * Path generator that uses media ID as the directory name.
 *
 * Produces paths compatible with Spatie MediaLibrary's DefaultPathGenerator:
 *   - Media: {prefix}/{id}/filename
 *   - Conversion: {prefix}/{id}/conversions/{conversion_name}/{uuid}/filename
 *
 * Special cases (via metadata):
 *   - metadata['path']: uses the stored path directly
 */
class IdPathGenerator extends AbstractPathGenerator
{
    /**
     * @example {prefix}/{id}/
     */
    public function media(Media $media): Stringable
    {
        // Custom path stored in metadata (e.g. for migrated files)
        $customPath = data_get($media->metadata, 'path');

        if (is_string($customPath) && $customPath !== '') {
            return str($customPath)->finish('/');
        }

        $mediaId = $media->getKey();

        if (! is_int($mediaId) && ! is_string($mediaId)) {
            throw new LogicException('The media model must have a string or integer key before generating its path.');
        }

        $mediaId = (string) $mediaId;

        // Default: {prefix}/{id}/
        if ($this->prefix) {
            return str($this->prefix)
                ->finish('/')
                ->append($mediaId)
                ->finish('/');
        }

        return str($mediaId)->finish('/');
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
