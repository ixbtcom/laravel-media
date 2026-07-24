# laravel-media

## Overview

Форк [`elegantly/laravel-media`](https://github.com/ElegantEngineeringTech/laravel-media), переименованный в `ixbtcom/laravel-media`. Гибкая медиа-библиотека для Laravel с поддержкой конверсий, FFmpeg, множественных дисков и CDN-форматирования URL.

**Namespace остаётся `Elegantly\Media`** — не менялся при форке.

## Ключевые отличия от upstream

- **Spatie compatibility layer** (`src/Compat/`) — мост между API Spatie MediaLibrary и Elegantly:
  - `PendingMediaAdder` — fluent API: `$model->addMediaFromUrl($url)->usingName('cover')->toMediaCollection('covers')`
  - `MediaCollectionBuilder` — fluent API: `$this->addMediaCollection('avatar')->useDisk('s3')->singleFile()`
  - Методы в HasMedia: `addMediaFromUrl()`, `addMediaFromDisk()`, `addMediaFromRequest()`, `addMediaCollection()`
  - Методы в Media: `getCustomProperty()`, `setCustomProperty()`, `getFullUrl()`
- **`IdPathGenerator`** — генерация путей по auto-increment ID (совместимо с Spatie `DefaultPathGenerator`): `{prefix}/{id}/filename`
- **`ThumborUrlFormatter`** — форматирование URL через Thumbor CDN (`Ixbtcom\Common\Services\ImageService`)
- **`MediaThumbnailConverter`** — FFmpeg-конвертер для генерации thumbnail из видео (с настраиваемым timecode и frames)
- **Pending-состояние Media (async-загрузка, 2026-07-24):** enum `MediaState` (`pending|ready|failed`), nullable-колонка `state` (`NULL`≡`ready`, миграция `add_state_to_media_table`); `HasMedia::addPendingMedia()` создаёт Media БЕЗ файла (temp-ссылка в `metadata.pending_temp`, `MediaAddedEvent` НЕ кидается); `Media::finalizePending()` идемпотентно копирует temp→финальный диск под `Cache::lock("media-finalize-{uuid}")`, событие только после копии, post-ready side effects не откатывают state; `deletePendingTempFile()` при удалении. Не путать с Compat `PendingMediaAdder` (fluent-builder). Потребитель: async-загрузка EditorJS (`ixbtadmin/docs/architecture/editorjs-async-upload.md`)
- Убрана зависимость `spatie/pdf-to-image`

## Naming swap (критично!)

| Spatie MediaLibrary | Elegantly/laravel-media |
|---|---|
| `HasMedia` (interface) | `InteractWithMedia` (contract) |
| `InteractsWithMedia` (trait) | `HasMedia` (trait) |

## Структура

```
src/
├── Compat/                    # Spatie-совместимый fluent API
│   ├── MediaCollectionBuilder.php
│   └── PendingMediaAdder.php
├── Concerns/
│   └── HasMedia.php           # Основной trait для моделей
├── Contracts/
│   └── InteractWithMedia.php  # Interface (= Spatie HasMedia)
├── Converters/
│   └── Video/
│       └── MediaThumbnailConverter.php  # FFmpeg thumbnail
├── FFMpeg/                    # FFMpeg wrapper
├── Models/
│   ├── Media.php              # Основная модель
│   └── MediaConversion.php    # Модель конверсии
├── PathGenerators/
│   ├── AbstractPathGenerator.php
│   ├── IdPathGenerator.php    # По ID (Spatie-совместимый)
│   └── UuidPathGenerator.php  # По UUID (default)
└── UrlFormatters/
    ├── AbstractUrlFormatter.php
    ├── DefaultUrlFormatter.php
    ├── ThumborUrlFormatter.php       # Thumbor CDN
    ├── CloudflareImageUrlFormatter.php
    └── CloudflareVideoUrlFormatter.php
```

## Конфигурация

Файл: `config/media.php`. Ключевые настройки:

```php
'model' => Media::class,                        // Можно заменить на свою модель
'disk' => env('MEDIA_DISK', 'local'),           // Диск хранения
'default_url_formatter' => DefaultUrlFormatter::class,  // Для CDN: ThumborUrlFormatter::class
'default_path_generator' => UuidPathGenerator::class,   // Для Spatie-совместимости: IdPathGenerator::class
```

В ixbtwire/ixbtadmin используется `IdPathGenerator` + `ThumborUrlFormatter`.

## Команды

```bash
composer test          # Запуск тестов (Pest)
composer analyse       # PHPStan
composer format        # Laravel Pint
composer lint          # Pint + PHPStan
```

## Важные gotchas

- **Early save при IdPathGenerator**: Media сохраняется в БД *до* генерации пути, чтобы получить ID для директории. Поле `size` получает default `0` для NOT NULL constraint
- **`loadMissing('conversions')`** вызывается при удалении Media, чтобы корректно удалить все файлы конверсий
- **ThumborUrlFormatter** использует `Ixbtcom\Common\Services\ImageService` через string-резолв из контейнера (без hard dependency)
- **BunnyStream**: `IdPathGenerator` поддерживает диск `bunny_stream` — путь берётся из `metadata.bunny_media_id`
- **Metadata path**: если в `metadata.path` есть значение, `IdPathGenerator` использует его напрямую (для мигрированных файлов)

---

## DeepSeek delegation (synced)

@~/code/claude/snippets/deepseek-routing.md
