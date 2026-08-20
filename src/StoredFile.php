<?php

declare(strict_types=1);

namespace Elegantly\Media;

use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Facades\Storage;

/**
 * Файл, который уже лежит в хранилище: диск плюс ключ.
 *
 * Нужен, чтобы addMedia() принимал такой источник наравне с загруженным файлом
 * и ссылкой, а решение «как переносить» принималось внутри — по драйверам
 * дисков, а не отдельным методом на каждый случай.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $disk,
        public string $path,
    ) {}

    /**
     * Драйвер local отдаёт настоящий путь в файловой системе — с ним доступны замеры.
     *
     * ⛔ Умолчание пустое, а не 'local': диск без записи в конфиге (подменённый в
     * тестах, зарегистрированный на лету) локальным не считается — path() на нём
     * может отсутствовать.
     */
    public function isLocal(): bool
    {
        return config("filesystems.disks.{$this->disk}.driver", '') === 'local';
    }

    /** Локальный файл источника; null — источник удалённый. */
    public function toHttpFile(): ?HttpFile
    {
        if (! $this->isLocal()) {
            return null;
        }

        return new HttpFile(Storage::disk($this->disk)->path($this->path));
    }

    public function extension(): string
    {
        return strtolower((string) pathinfo($this->path, PATHINFO_EXTENSION));
    }
}
