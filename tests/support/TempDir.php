<?php
namespace Wtp\Tests;

/** Self-cleaning temporary directory for filesystem tests. */
final class TempDir
{
    /** @var string */
    private $path;

    public function __construct(string $prefix = 'wtp-test-')
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('Cannot create temp dir');
        }
        $this->path = $base;
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->path : $this->path . DIRECTORY_SEPARATOR . ltrim($relative, '/');
    }

    public function mkdir(string $relative): string
    {
        $p = $this->path($relative);
        if (!is_dir($p) && !mkdir($p, 0700, true) && !is_dir($p)) {
            throw new \RuntimeException('Cannot create ' . $p);
        }
        return $p;
    }

    public function write(string $relative, string $contents): string
    {
        $p = $this->path($relative);
        $dir = dirname($p);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($p, $contents);
        return $p;
    }

    public function cleanup(): void
    {
        self::rrmdir($this->path);
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
