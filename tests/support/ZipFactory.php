<?php
namespace Wtp\Tests;

use ZipArchive;

/** Builds the hostile archives the SafeZip tests need. */
final class ZipFactory
{
    /**
     * @param array<string,string> $entries path => contents
     */
    public static function make(string $path, array $entries, array $dirs = []): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip at ' . $path);
        }
        foreach ($dirs as $dir) {
            $zip->addEmptyDir($dir);
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    /** An entry flagged as a UNIX symlink pointing at $target. */
    public static function makeWithSymlink(string $path, string $name, string $target): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip');
        }
        $zip->addFromString($name, $target);
        $index = $zip->locateName($name);
        // S_IFLNK (0xA000) | 0777, shifted into the high half of the attribute word.
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0xA1FF) << 16);
        $zip->close();
        unset($index);
        return $path;
    }

    /** Highly compressible payload, for zip-bomb ratio checks. */
    public static function bomb(string $path, int $uncompressedBytes = 40000000): string
    {
        return self::make($path, ['bomb.txt' => str_repeat('A', $uncompressedBytes)]);
    }
}
