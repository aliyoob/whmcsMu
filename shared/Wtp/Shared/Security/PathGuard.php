<?php
namespace Wtp\Shared\Security;

/**
 * Confines every filesystem write to a resolved base directory.
 *
 * Works for paths that do not exist yet (realpath() returns false for those),
 * by normalising lexically and then comparing against the resolved base.
 */
final class PathGuard
{
    /** @var string resolved, trailing-slash-free base directory */
    private $base;

    public function __construct(string $baseDir)
    {
        $resolved = realpath($baseDir);
        if ($resolved === false) {
            throw new SecurityException('Base directory does not exist: ' . $baseDir);
        }
        $this->base = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Resolve $relative inside the base and guarantee it cannot escape.
     *
     * @throws SecurityException on traversal, absolute paths, null bytes or symlink escape.
     */
    public function resolve(string $relative): string
    {
        if ($relative === '') {
            throw new SecurityException('Empty path');
        }
        if (strpos($relative, "\0") !== false) {
            throw new SecurityException('Null byte in path');
        }
        // Reject anything that is already absolute, UNC or a Windows drive path.
        if ($relative[0] === '/' || $relative[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $relative)) {
            throw new SecurityException('Absolute paths are not allowed: ' . $relative);
        }

        $normalized = self::normalize($relative);
        if ($normalized === null) {
            throw new SecurityException('Path traversal detected: ' . $relative);
        }

        $candidate = $this->base . DIRECTORY_SEPARATOR . $normalized;

        // If any existing ancestor resolves outside the base (symlink), reject.
        $existing = $candidate;
        while ($existing !== '' && !file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }
        $real = realpath($existing);
        if ($real !== false && !$this->isWithin($real)) {
            throw new SecurityException('Resolved path escapes the base directory');
        }
        return $candidate;
    }

    public function isWithin(string $absolutePath): bool
    {
        $absolutePath = rtrim($absolutePath, DIRECTORY_SEPARATOR);
        return $absolutePath === $this->base
            || strpos($absolutePath, $this->base . DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * Lexical normalisation. Returns null when the path would escape its root.
     */
    public static function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($parts)) {
                    return null; // escapes the root
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        if (empty($parts)) {
            return null;
        }
        return implode(DIRECTORY_SEPARATOR, $parts);
    }
}
