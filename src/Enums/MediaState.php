<?php

declare(strict_types=1);

namespace Elegantly\Media\Enums;

/**
 * Состояние файла Media в асинхронном пайплайне загрузки.
 *
 * NULL в колонке state трактуется как Ready — существующие строки
 * не бэкфиллятся (см. Media::isReady()).
 */
enum MediaState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
