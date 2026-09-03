<?php
namespace Wtp\Addon\Support;

use RuntimeException;
use Wtp\Shared\Security\PathGuard;
use Wtp\Shared\Security\SecurityException;

/**
 * Package storage, deliberately outside the web root.
 *
 * Nothing here is ever served by the web server directly: the only way to a
 * byte of this directory is a signed, expiring, job-bound grant streamed by
 * the download controller. That is what stops a guessed URL from exfiltrating
 * a licensed premium theme.
 */
final class Storage
{
    /** @var string */
    private $root;
    /** @var PathGuard */
    private $guard;

    public function __construct(string $root)
    {
        if (!is_dir($root)) {
            throw new RuntimeException(
                'Package storage directory does not exist: ' . $root
                . ' — create it (root:root, 0750) and make it readable by the web user.'
            );
        }
        $this->root = rtrim((string) realpath($root), DIRECTORY_SEPARATOR);
        $this->guard = new PathGuard($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @throws SecurityException when the reference escapes the storage root */
    public function resolve(string $relativePath): string
    {
        return $this->guard->resolve($relativePath);
    }

    public function exists(string $relativePath): bool
    {
        try {
            $path = $this->resolve($relativePath);
        } catch (SecurityException $e) {
            return false;
        }
        return is_file($path) && is_readable($path);
    }

    public function size(string $relativePath): int
    {
        $path = $this->resolve($relativePath);
        $size = @filesize($path);
        return $size === false ? 0 : (int) $size;
    }

    public function checksum(string $relativePath): string
    {
        $path = $this->resolve($relativePath);
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Cannot checksum ' . $relativePath);
        }
        return $hash;
    }

    /**
     * Store an uploaded package under a content-addressed path.
     *
     * @return array{path:string,sha256:string,size:int}
     */
    public function put(string $sourcePath, string $filename, string $namespace = 'demo'): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('Source file is not readable');
        }
        $sha = hash_file('sha256', $sourcePath);
        if ($sha === false) {
            throw new RuntimeException('Cannot checksum the uploaded file');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '_', basename($filename));
        $safeName = substr((string) $safeName, 0, 120);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            $safeName = 'package.bin';
        }

        $relative = $namespace . '/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha . '-' . $safeName;
        $target = $this->resolve($relative);

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage directory');
        }

        if (!is_file($target)) {
            // move_uploaded_file is handled by the caller; this class only ever
            // sees a path it is allowed to read.
            if (!@copy($sourcePath, $target)) {
                throw new RuntimeException('Cannot write the package into storage');
            }
            @chmod($target, 0640);
        }

        return [
            'path' => $relative,
            'sha256' => $sha,
            'size' => (int) filesize($target),
        ];
    }

    public function delete(string $relativePath): bool
    {
        try {
            $path = $this->resolve($relativePath);
        } catch (SecurityException $e) {
            return false;
        }
        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * Stream a file to the client in bounded chunks, so a 200 MB package never
     * lands in PHP's memory.
     */
    public function stream(string $relativePath, int $chunkSize = 262144): void
    {
        $path = $this->resolve($relativePath);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open package for streaming');
        }
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        }
        fclose($handle);
    }
}
