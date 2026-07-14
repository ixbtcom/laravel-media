<?php

declare(strict_types=1);

namespace Elegantly\Media\Exceptions;

use InvalidArgumentException;

final class UnresolvableMediaUrlException extends InvalidArgumentException
{
    public static function forUrl(string $url): self
    {
        return new self("Media URL [{$url}] cannot be resolved to a configured disk and path.");
    }
}
