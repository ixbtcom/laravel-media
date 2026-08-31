<?php

declare(strict_types=1);

namespace Elegantly\Media\Support;

use JsonException;

final class MediaRightsEvidence
{
    public const SCHEMA_VERSION = 1;

    private const MAX_VALUES_PER_ROLE = 16;

    private const MAX_TAGS_PER_VALUE = 8;

    private const MAX_VALUE_LENGTH = 4096;

    private const MAX_JSON_BYTES = 65_536;

    private const ROLES = [
        'creator',
        'credit_line',
        'copyright_notice',
        'rights_holder',
        'supplier',
        'rights_usage_terms',
    ];

    private const SAFE_QUERY_PARAMETERS = [
        'asset_id',
        'image_id',
        'media_id',
        'photo_id',
        'item_id',
        'uid',
    ];

    private const EXTRACTION_STATUSES = [
        'success',
        'no_metadata',
        'unavailable',
        'failed',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly array $data,
        private readonly bool $hasStoredEvidence,
    ) {}

    /**
     * @param  null|array<array-key, mixed>  $metadata
     */
    public static function fromMetadata(?array $metadata): self
    {
        $evidence = $metadata['rights_evidence'] ?? null;

        if (! is_array($evidence)) {
            return self::unavailable();
        }

        return self::fromArray($evidence);
    }

    /**
     * @param  null|array<array-key, mixed>  $evidence
     */
    public static function fromArray(?array $evidence): self
    {
        if (($evidence['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return self::unavailable();
        }

        $status = $evidence['extraction']['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::EXTRACTION_STATUSES, true)) {
            return self::unavailable();
        }

        [$embedded, $limitExceeded] = self::normalizeEmbedded($evidence['embedded'] ?? null);

        if ($status !== 'success') {
            $embedded = self::emptyEmbedded();
        }

        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'file' => [
                'sha256' => self::normalizeSha256($evidence['file']['sha256'] ?? null),
            ],
            'ingest' => [
                'method' => self::normalizeIngestMethod($evidence['ingest']['method'] ?? null),
                'original_url' => self::redactOriginalUrl($evidence['ingest']['original_url'] ?? null),
                'captured_at' => self::normalizeTimestamp($evidence['ingest']['captured_at'] ?? null),
            ],
            'embedded' => $embedded,
            'extraction' => [
                'status' => $limitExceeded ? 'failed' : $status,
                'extractor' => 'exiftool',
                'extractor_version' => self::normalizeShortString($evidence['extraction']['extractor_version'] ?? null),
                'extracted_at' => self::normalizeTimestamp($evidence['extraction']['extracted_at'] ?? null),
            ],
        ];

        if ($limitExceeded || self::encodedSize($normalized) > self::MAX_JSON_BYTES) {
            $normalized['embedded'] = self::emptyEmbedded();
            $normalized['extraction']['status'] = 'failed';
        }

        return new self($normalized, true);
    }

    public function hasStoredEvidence(): bool
    {
        return $this->hasStoredEvidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public static function redactOriginalUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;

        if (! in_array($scheme, ['http', 'https'], true) || $host === null || $host === '') {
            return null;
        }

        $authority = str_contains($host, ':') ? "[{$host}]" : $host;

        if (isset($parts['port'])) {
            $authority .= ":{$parts['port']}";
        }

        $path = $parts['path'] ?? '';
        $query = self::redactQuery($parts['query'] ?? null);

        return "{$scheme}://{$authority}{$path}".($query === null ? '' : "?{$query}");
    }

    private static function unavailable(): self
    {
        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'file' => ['sha256' => null],
            'ingest' => [
                'method' => null,
                'original_url' => null,
                'captured_at' => null,
            ],
            'embedded' => self::emptyEmbedded(),
            'extraction' => [
                'status' => 'unavailable',
                'extractor' => 'exiftool',
                'extractor_version' => null,
                'extracted_at' => null,
            ],
        ], false);
    }

    /**
     * @return array<string, array<int, array{value: string, tags: array<int, string>}>>
     */
    private static function emptyEmbedded(): array
    {
        return array_fill_keys(self::ROLES, []);
    }

    /**
     * @return array{array<string, array<int, array{value: string, tags: array<int, string>}>>, bool}
     */
    private static function normalizeEmbedded(mixed $embedded): array
    {
        $normalized = self::emptyEmbedded();

        if (! is_array($embedded)) {
            return [$normalized, false];
        }

        foreach (self::ROLES as $role) {
            $values = $embedded[$role] ?? [];

            if (! is_array($values)) {
                continue;
            }

            if (count($values) > self::MAX_VALUES_PER_ROLE) {
                return [self::emptyEmbedded(), true];
            }

            $byValue = [];

            foreach ($values as $item) {
                if (! is_array($item) || ! is_string($item['value'] ?? null)) {
                    continue;
                }

                $value = trim($item['value']);

                if ($value === '') {
                    continue;
                }

                if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                    return [self::emptyEmbedded(), true];
                }

                $tags = $item['tags'] ?? [];

                if (! is_array($tags)) {
                    $tags = [];
                }

                if (count($tags) > self::MAX_TAGS_PER_VALUE) {
                    return [self::emptyEmbedded(), true];
                }

                $normalizedTags = [];

                foreach ($tags as $tag) {
                    if (! is_string($tag)) {
                        continue;
                    }

                    $tag = trim($tag);

                    if ($tag !== '') {
                        $normalizedTags[$tag] = $tag;
                    }
                }

                $byValue[$value] ??= [];
                $byValue[$value] = [...$byValue[$value], ...array_values($normalizedTags)];
            }

            foreach ($byValue as $value => $tags) {
                $tags = array_values(array_unique($tags));
                sort($tags, SORT_STRING);

                $normalized[$role][] = [
                    'value' => $value,
                    'tags' => $tags,
                ];
            }
        }

        return [$normalized, false];
    }

    private static function normalizeSha256(mixed $sha256): ?string
    {
        if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        return $sha256;
    }

    private static function normalizeIngestMethod(mixed $method): ?string
    {
        return is_string($method) && in_array($method, ['file', 'url'], true)
            ? $method
            : null;
    }

    private static function normalizeTimestamp(mixed $timestamp): ?string
    {
        if (! is_string($timestamp)) {
            return null;
        }

        $timestamp = trim($timestamp);

        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $timestamp) === 1
            ? $timestamp
            : null;
    }

    private static function normalizeShortString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= 255 ? $value : null;
    }

    private static function redactQuery(?string $query): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }

        $redacted = [];

        foreach (explode('&', $query) as $parameter) {
            [$encodedName, $encodedValue] = array_pad(explode('=', $parameter, 2), 2, '');
            $name = self::decodeQueryPart($encodedName);

            if ($name === null || $name === '') {
                continue;
            }

            $value = self::decodeQueryPart($encodedValue);
            $safeValue = in_array(strtolower($name), self::SAFE_QUERY_PARAMETERS, true)
                && $value !== null
                && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) === 1;

            $redacted[] = rawurlencode($name).'='.rawurlencode($safeValue ? $value : '***');
        }

        return $redacted === [] ? null : implode('&', $redacted);
    }

    private static function decodeQueryPart(string $value): ?string
    {
        if (preg_match('/%(?![A-Fa-f0-9]{2})/', $value) === 1) {
            return null;
        }

        $decoded = rawurldecode($value);

        if (preg_match('/[\x00-\x1F\x7F]/u', $decoded) === 1) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function encodedSize(array $value): int
    {
        try {
            return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            return self::MAX_JSON_BYTES + 1;
        }
    }
}
