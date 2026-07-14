<?php

declare(strict_types=1);

namespace Elegantly\Media\Support;

use Elegantly\Media\Exceptions\UnresolvableMediaUrlException;

final class MediaUrlResolver
{
    /**
     * @return array{
     *     disk: string,
     *     path: string,
     * }
     */
    public function resolve(string $url): array
    {
        $normalizedUrl = $this->normalizeAbsoluteUrl($url);

        if ($normalizedUrl === null) {
            throw UnresolvableMediaUrlException::forUrl($url);
        }

        foreach ($this->mappings() as $prefix => $disk) {
            if (! str_starts_with($normalizedUrl, "{$prefix}/")) {
                continue;
            }

            $path = rawurldecode(substr($normalizedUrl, strlen($prefix) + 1));

            if ($this->isValidPath($path)) {
                return [
                    'disk' => $disk,
                    'path' => $path,
                ];
            }
        }

        throw UnresolvableMediaUrlException::forUrl($url);
    }

    /** @return array<string, string> */
    private function mappings(): array
    {
        $mappings = [];
        $disks = config('filesystems.disks', []);

        foreach (is_array($disks) ? $disks : [] as $disk => $configuration) {
            $prefix = is_array($configuration)
                ? $this->normalizeAbsoluteUrl($configuration['url'] ?? null)
                : null;

            if ($prefix !== null && is_string($disk)) {
                $mappings[$prefix] = $disk;
            }
        }

        $aliases = config('media.url_disk_aliases', []);

        foreach (is_array($aliases) ? $aliases : [] as $prefix => $disk) {
            $normalizedPrefix = $this->normalizeAbsoluteUrl($prefix);

            if ($normalizedPrefix !== null && is_string($disk)) {
                $mappings[$normalizedPrefix] = $disk;
            }
        }

        uksort($mappings, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $mappings;
    }

    private function normalizeAbsoluteUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$host}{$port}{$path}";
    }

    private function isValidPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
