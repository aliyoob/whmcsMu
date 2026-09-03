<?php
namespace Wtp\Shared\Security;

use ZipArchive;

/**
 * Hardened ZIP extraction.
 *
 * Defends against: ZIP slip / path traversal, absolute paths, symlink escape,
 * zip bombs (entry count, uncompressed total, per-entry compression ratio),
 * and unwanted executable content in data-only archives.
 *
 * Extraction is atomic-ish: everything lands in a staging directory that the
 * caller promotes, so a failure never leaves a half-extracted theme in place.
 */
final class SafeZip
{
    /** Extensions allowed in code packages (themes, plugins, the agent itself). */
    public const CODE_EXTENSIONS = [
        'php', 'js', 'mjs', 'css', 'scss', 'json', 'txt', 'md', 'html', 'htm', 'xml', 'svg',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp',
        'ttf', 'otf', 'woff', 'woff2', 'eot',
        'mo', 'po', 'pot', 'map', 'yml', 'yaml', 'twig', 'dat', 'csv', 'less',
        'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'zip', 'htaccess',
    ];

    /** Extensions allowed in content packages. Deliberately excludes php. */
    public const CONTENT_EXTENSIONS = [
        'xml', 'json', 'dat', 'wie', 'txt', 'csv', 'md',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico',
        'mp4', 'webm', 'mp3', 'wav', 'pdf', 'zip',
    ];

    /** @var int */
    private $maxEntries = 20000;
    /** @var int bytes */
    private $maxTotalUncompressed = 1073741824; // 1 GiB
    /** @var int bytes */
    private $maxEntrySize = 268435456;          // 256 MiB
    /** @var int */
    private $maxCompressionRatio = 200;
    /** @var string[]|null null = allow any extension */
    private $allowedExtensions;

    /** @param string[]|null $allowedExtensions */
    public function __construct(?array $allowedExtensions = self::CODE_EXTENSIONS)
    {
        $this->allowedExtensions = $allowedExtensions === null
            ? null
            : array_map('strtolower', $allowedExtensions);
    }

    public function withLimits(
        ?int $maxEntries = null,
        ?int $maxTotalUncompressed = null,
        ?int $maxEntrySize = null,
        ?int $maxCompressionRatio = null
    ): self {
        $clone = clone $this;
        if ($maxEntries !== null) {
            $clone->maxEntries = $maxEntries;
        }
        if ($maxTotalUncompressed !== null) {
            $clone->maxTotalUncompressed = $maxTotalUncompressed;
        }
        if ($maxEntrySize !== null) {
            $clone->maxEntrySize = $maxEntrySize;
        }
        if ($maxCompressionRatio !== null) {
            $clone->maxCompressionRatio = $maxCompressionRatio;
        }
        return $clone;
    }

    /**
     * Validate every entry without writing anything.
     *
     * @return array{entries:int,uncompressed:int,names:string[],root:?string}
     * @throws SecurityException on the first unacceptable entry.
     */
    public function inspect(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new SecurityException('The PHP zip extension is required to process packages');
        }
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new SecurityException('Archive is not readable');
        }

        $zip = new ZipArchive();
        $code = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($code !== true) {
            throw new SecurityException('Cannot open archive (zip error ' . (int) $code . ')');
        }

        try {
            $count = $zip->numFiles;
            if ($count > $this->maxEntries) {
                throw new SecurityException('Archive has too many entries (' . $count . ')');
            }

            $total = 0;
            $names = [];
            $roots = [];

            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new SecurityException('Unreadable entry at index ' . $i);
                }
                $name = (string) $stat['name'];

                $this->assertEntryName($name);
                $this->assertNotSymlink($zip, $i, $name);

                $isDir = substr($name, -1) === '/';
                if (!$isDir) {
                    $size = (int) $stat['size'];
                    $comp = (int) $stat['comp_size'];

                    if ($size > $this->maxEntrySize) {
                        throw new SecurityException('Entry exceeds size limit: ' . $name);
                    }
                    if ($comp > 0 && $size / max(1, $comp) > $this->maxCompressionRatio) {
                        throw new SecurityException('Suspicious compression ratio (zip bomb): ' . $name);
                    }
                    $total += $size;
                    if ($total > $this->maxTotalUncompressed) {
                        throw new SecurityException('Archive exceeds total uncompressed size limit');
                    }
                    $this->assertExtension($name);
                    $names[] = $name;
                }

                $normalized = PathGuard::normalize($name);
                if ($normalized !== null) {
                    $first = explode(DIRECTORY_SEPARATOR, $normalized)[0];
                    $roots[$first] = true;
                }
            }

            return [
                'entries' => $count,
                'uncompressed' => $total,
                'names' => $names,
                'root' => count($roots) === 1 ? (string) array_key_first($roots) : null,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Inspect, then extract into $destination (which must already exist).
     *
     * @return array{entries:int,uncompressed:int,root:?string}
     */
    public function extractTo(string $zipPath, string $destination): array
    {
        $report = $this->inspect($zipPath);
        $guard = new PathGuard($destination);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new SecurityException('Cannot reopen archive for extraction');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = (string) $stat['name'];
                $target = $guard->resolve($name);

                if (substr($name, -1) === '/') {
                    self::mkdir($target);
                    continue;
                }
                self::mkdir(dirname($target));

                $in = $zip->getStream($name);
                if (!is_resource($in)) {
                    throw new SecurityException('Cannot read entry: ' . $name);
                }
                $out = fopen($target, 'wb');
                if (!is_resource($out)) {
                    fclose($in);
                    throw new SecurityException('Cannot write entry: ' . $name);
                }
                $written = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 262144);
                    if ($chunk === false) {
                        break;
                    }
                    $written += strlen($chunk);
                    if ($written > $this->maxEntrySize) {
                        fclose($in);
                        fclose($out);
                        @unlink($target);
                        throw new SecurityException('Entry grew beyond its declared size: ' . $name);
                    }
                    fwrite($out, $chunk);
                }
                fclose($in);
                fclose($out);
                @chmod($target, 0644);
            }
        } finally {
            $zip->close();
        }

        unset($report['names']);
        return $report;
    }

    private function assertEntryName(string $name): void
    {
        if ($name === '') {
            throw new SecurityException('Empty entry name');
        }
        if (strpos($name, "\0") !== false) {
            throw new SecurityException('Null byte in entry name');
        }
        if (strlen($name) > 255 * 4) {
            throw new SecurityException('Entry name too long');
        }
        if ($name[0] === '/' || $name[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $name)) {
            throw new SecurityException('Absolute path in archive: ' . $name);
        }
        $segments = preg_split('#[\\\\/]+#', $name);
        foreach ((array) $segments as $segment) {
            if ($segment === '..') {
                throw new SecurityException('Path traversal in archive: ' . $name);
            }
        }
    }

    private function assertNotSymlink(ZipArchive $zip, int $index, string $name): void
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return; // no attribute info; nothing to assert
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return;
        }
        $mode = ($attr >> 16) & 0xFFFF;
        if (($mode & 0xF000) === 0xA000) {
            throw new SecurityException('Symlinks are not allowed in packages: ' . $name);
        }
    }

    private function assertExtension(string $name): void
    {
        if ($this->allowedExtensions === null) {
            return;
        }
        $base = basename($name);
        if ($base === '' || $base[0] === '.') {
            // dotfiles: allow only a short known list
            if (!in_array(strtolower(ltrim($base, '.')), ['htaccess', 'gitkeep', 'editorconfig'], true)) {
                throw new SecurityException('Dotfile not allowed in package: ' . $name);
            }
            return;
        }
        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === '') {
            throw new SecurityException('Extensionless file not allowed in package: ' . $name);
        }
        if (!in_array($ext, $this->allowedExtensions, true)) {
            throw new SecurityException('Disallowed file type in package: ' . $name);
        }
        // Defence in depth against double extensions such as evil.php.png
        if (preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh)\./i', $base)) {
            throw new SecurityException('Suspicious double extension: ' . $name);
        }
    }

    private static function mkdir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new SecurityException('Cannot create directory: ' . $dir);
        }
    }
}
