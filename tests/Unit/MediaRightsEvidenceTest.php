<?php

declare(strict_types=1);

use Elegantly\Media\Models\Media;
use Elegantly\Media\Support\MediaRightsEvidence;

it('keeps creator supplier and rights holder as separate evidence roles', function () {
    $evidence = MediaRightsEvidence::fromMetadata([
        'rights_evidence' => [
            'schema_version' => 1,
            'file' => ['sha256' => str_repeat('a', 64)],
            'ingest' => [
                'method' => 'file',
                'original_url' => null,
                'captured_at' => '2026-08-31T10:00:00Z',
            ],
            'embedded' => [
                'creator' => [
                    ['value' => ' Марина Лысцева ', 'tags' => ['XMP-dc:Creator', 'IPTC:By-line']],
                    ['value' => 'Марина Лысцева', 'tags' => ['IPTC:By-line']],
                ],
                'supplier' => [
                    ['value' => 'ТАСС', 'tags' => ['IPTC:Source']],
                ],
                'rights_holder' => [
                    ['value' => 'Фотобанк Лори', 'tags' => ['XMP-plus:CopyrightOwnerName']],
                ],
            ],
            'extraction' => [
                'status' => 'success',
                'extractor' => 'exiftool',
                'extractor_version' => '13.36',
                'extracted_at' => '2026-08-31T10:00:01Z',
            ],
        ],
    ]);

    $normalized = $evidence->toArray();

    expect($normalized['schema_version'])->toBe(1)
        ->and($normalized['file'])->toBe(['sha256' => str_repeat('a', 64)])
        ->and($normalized['embedded']['creator'])->toBe([[
            'value' => 'Марина Лысцева',
            'tags' => ['IPTC:By-line', 'XMP-dc:Creator'],
        ]])
        ->and($normalized['embedded']['supplier'])->toBe([[
            'value' => 'ТАСС',
            'tags' => ['IPTC:Source'],
        ]])
        ->and($normalized['embedded']['rights_holder'])->toBe([[
            'value' => 'Фотобанк Лори',
            'tags' => ['XMP-plus:CopyrightOwnerName'],
        ]])
        ->and($normalized['extraction'])->toBe([
            'status' => 'success',
            'extractor' => 'exiftool',
            'extractor_version' => '13.36',
            'extracted_at' => '2026-08-31T10:00:01Z',
        ])
        ->and($evidence->hasStoredEvidence())->toBeTrue();
});

it('redacts unsafe original url parameters without collapsing repetitions', function () {
    $evidence = MediaRightsEvidence::fromMetadata([
        'rights_evidence' => [
            'schema_version' => 1,
            'file' => ['sha256' => str_repeat('b', 64)],
            'ingest' => [
                'method' => 'url',
                'original_url' => 'https://user:secret@example.test/photo.jpg?asset_id=42&token=secret&ASSET%5FID=second&X-Goog-Credential=credential&=drop#fragment',
                'captured_at' => '2026-08-31T10:00:00Z',
            ],
            'embedded' => [],
            'extraction' => [
                'status' => 'no_metadata',
                'extractor' => 'exiftool',
                'extractor_version' => null,
                'extracted_at' => '2026-08-31T10:00:01Z',
            ],
        ],
    ])->toArray();

    expect($evidence['ingest']['original_url'])
        ->toBe('https://example.test/photo.jpg?asset_id=42&token=%2A%2A%2A&ASSET_ID=second&X-Goog-Credential=%2A%2A%2A')
        ->not->toContain('secret', 'credential', 'fragment', '@');
});

it('fails closed when any embedded limit is exceeded', function (array $embedded) {
    $evidence = MediaRightsEvidence::fromMetadata([
        'rights_evidence' => [
            'schema_version' => 1,
            'file' => ['sha256' => str_repeat('c', 64)],
            'ingest' => [
                'method' => 'file',
                'original_url' => null,
                'captured_at' => '2026-08-31T10:00:00Z',
            ],
            'embedded' => $embedded,
            'extraction' => [
                'status' => 'success',
                'extractor' => 'exiftool',
                'extractor_version' => '13.36',
                'extracted_at' => '2026-08-31T10:00:01Z',
            ],
        ],
    ])->toArray();

    expect($evidence['extraction']['status'])->toBe('failed');

    foreach ($evidence['embedded'] as $values) {
        expect($values)->toBe([]);
    }
})->with([
    'value length' => [[
        'creator' => [['value' => str_repeat('а', 4097), 'tags' => ['IPTC:By-line']]],
    ]],
    'role count' => [[
        'creator' => array_fill(0, 17, ['value' => 'Автор', 'tags' => ['IPTC:By-line']]),
    ]],
    'tag count' => [[
        'creator' => [['value' => 'Автор', 'tags' => array_map(fn (int $i): string => "Tag:{$i}", range(1, 9))]],
    ]],
    'total payload' => [array_fill_keys(
        ['creator', 'credit_line', 'copyright_notice', 'rights_holder', 'supplier', 'rights_usage_terms'],
        array_map(fn (int $i): array => ['value' => str_repeat((string) $i, 700), 'tags' => ['Tag:Value']], range(10, 25)),
    )],
]);

it('returns an unavailable typed projection for absent malformed or unknown evidence', function (mixed $metadata) {
    $evidence = MediaRightsEvidence::fromMetadata(is_array($metadata) ? $metadata : null);

    expect($evidence->hasStoredEvidence())->toBeFalse()
        ->and($evidence->toArray()['extraction']['status'])->toBe('unavailable')
        ->and($evidence->toArray()['embedded'])->toBe([
            'creator' => [],
            'credit_line' => [],
            'copyright_notice' => [],
            'rights_holder' => [],
            'supplier' => [],
            'rights_usage_terms' => [],
        ]);
})->with([
    'absent' => [null],
    'malformed' => [['rights_evidence' => 'raw']],
    'unknown schema' => [['rights_evidence' => ['schema_version' => 2, 'secret' => 'must not leak']]],
]);

it('exposes the typed evidence through the media model without mutating metadata', function () {
    $media = Media::factory()->create([
        'metadata' => [
            'source' => 'editorjs',
            'rights_evidence' => [
                'schema_version' => 99,
                'secret' => 'must not leak',
            ],
        ],
    ]);

    $evidence = $media->rightsEvidence();

    expect($evidence)->toBeInstanceOf(MediaRightsEvidence::class)
        ->and($evidence->toArray()['extraction']['status'])->toBe('unavailable')
        ->and($media->isDirty())->toBeFalse()
        ->and($media->metadata['source'])->toBe('editorjs');
});
