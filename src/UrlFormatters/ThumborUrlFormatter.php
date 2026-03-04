<?php

declare(strict_types=1);

namespace Elegantly\Media\UrlFormatters;

/**
 * URL Formatter that generates Thumbor CDN URLs for image resizing.
 *
 * Requires Ixbtcom\Common\Services\ImageService to be bound in the container.
 * For non-image URLs or when no dimensions are specified, returns the original URL.
 *
 * Usage:
 *   $media->getUrl(parameters: ['width' => 920, 'height' => 700, 'mode' => 'resize']);
 *
 * Or configure as default formatter:
 *   'default_url_formatter' => ThumborUrlFormatter::class
 */
class ThumborUrlFormatter extends AbstractUrlFormatter
{
    public function format(string $url, ?array $parameters = null): string
    {
        if (empty($parameters)) {
            return $url;
        }

        $width = $parameters['width'] ?? null;
        $height = $parameters['height'] ?? null;
        $mode = $parameters['mode'] ?? 'resize';

        // No dimensions requested — return original URL
        if ($width === null && $height === null) {
            // Pass through any non-dimension parameters as query string
            $otherParams = array_diff_key($parameters, array_flip(['width', 'height', 'mode']));

            return $otherParams ? $url.'?'.http_build_query($otherParams) : $url;
        }

        // Resolve ImageService from container
        // Uses class string to avoid hard dependency on ixbtcom/common
        $imageServiceClass = 'Ixbtcom\\Common\\Services\\ImageService';

        if (! app()->bound($imageServiceClass) && ! class_exists($imageServiceClass)) {
            // Fallback: return URL with query parameters
            return $url.'?'.http_build_query(array_filter([
                'w' => $width,
                'h' => $height,
                'mode' => $mode,
            ]));
        }

        $imageService = app($imageServiceClass);
        $cdnUrl = $imageService->url($url, $width, $height, $mode);

        return $cdnUrl ?? $url;
    }
}
