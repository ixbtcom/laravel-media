<?php

declare(strict_types=1);

use Elegantly\Media\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

uses(TestCase::class)->in(__DIR__);

/**
 * Диск, который не принимает запись: как провайдер в аварии. `throw => false`
 * повторяет боевые s3-диски (putFileAs вернёт false), `true` - исключение.
 */
function failingDisk(string $name, bool $throw = false): void
{
    $root = sys_get_temp_dir().'/laravel-media-failing-'.$name;

    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public function write(string $path, string $contents, Config $config): void
        {
            throw UnableToWriteFile::atLocation($path, 'disk is down');
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            throw UnableToWriteFile::atLocation($path, 'disk is down');
        }
    };

    Storage::set($name, new FilesystemAdapter(
        new Filesystem($adapter),
        $adapter,
        ['root' => $root, 'throw' => $throw],
    ));
}
