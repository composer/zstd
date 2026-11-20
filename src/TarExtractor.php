<?php

/*
 * This file is part of composer/zstd.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Zstd;

/**
 * Extracts an in-memory tar archive into an empty directory.
 *
 * The rules must stay identical to native/src/main.c, see README.md "Safety rules".
 *
 * @internal
 */
final class TarExtractor
{
    private const MAX_META = 1 << 20;
    private const MAX_REL = 4096;
    private const MAX_LINK_HOPS = 40;
    private const MAX_MSG_NAME = 200;

    /** @var bool */
    private $windows;
    /** @var string */
    private $dest = '';
    /** @var string|null parent of the previous entry, already verified */
    private $lastParent;
    /** @var list<array{string, string}> [path, target] */
    private $links = [];
    /** @var list<string> */
    private $createdLinks = [];

    /**
     * @param bool|null $windows apply Windows rules, defaults to the current platform
     */
    public function __construct(?bool $windows = null)
    {
        $this->windows = $windows ?? Filesystem::isWindows();
    }

    /**
     * @param string $destination an existing empty directory
     */
    public function extract(string $tar, string $destination): void
    {
        $this->dest = rtrim($destination, '/\\');
        if ($this->dest === '') {
            $this->dest = '/';
        }
        $this->lastParent = null;
        $this->links = [];
        $this->createdLinks = [];

        $len = \strlen($tar);
        $pos = 0;
        $longName = $longLink = $paxPath = $paxLink = null;
        $zero = str_repeat("\0", 512);
        while (true) {
            if ($pos + 512 > $len) {
                throw new ExtractionException('Truncated tar archive');
            }
            $h = substr($tar, $pos, 512);
            $pos += 512;
            if ($h === $zero) {
                break;
            }

            $sum = self::octal(substr($h, 148, 8));
            if ($sum === null) {
                throw new ExtractionException('Invalid tar header');
            }
            $calc = 0;
            foreach (count_chars(substr_replace($h, '        ', 148, 8), 1) as $byte => $count) {
                $calc += $byte * $count;
            }
            if ($calc !== $sum) {
                throw new ExtractionException('Tar header checksum mismatch');
            }
            $size = self::octal(substr($h, 124, 12));
            if ($size === null) {
                throw new ExtractionException('Unsupported tar entry size');
            }
            $mode = self::octal(substr($h, 100, 8)) ?? 0644;
            $type = $h[156];
            $padded = ($size + 511) & ~511;
            if ($pos + $padded > $len) {
                throw new ExtractionException('Truncated tar archive');
            }

            if ($type === 'L' || $type === 'K' || $type === 'x' || $type === 'g') {
                if ($size > self::MAX_META) {
                    throw new ExtractionException('Metadata entry too large');
                }
                $data = substr($tar, $pos, $size);
                $pos += $padded;
                if ($type === 'L') {
                    $longName = self::cstr($data);
                } elseif ($type === 'K') {
                    $longLink = self::cstr($data);
                } elseif ($type === 'x') {
                    self::parsePax($data, $paxPath, $paxLink);
                }
                continue;
            }

            if ($paxPath !== null) {
                $name = $paxPath;
            } elseif ($longName !== null) {
                $name = $longName;
            } else {
                $name = self::cstr(substr($h, 0, 100));
                if (substr($h, 257, 6) === "ustar\0" && $h[345] !== "\0") {
                    $name = self::cstr(substr($h, 345, 155)).'/'.$name;
                }
            }
            $target = null;
            if ($type === '2') {
                $target = $paxLink ?? $longLink ?? self::cstr(substr($h, 157, 100));
            }
            $longName = $longLink = $paxPath = $paxLink = null;

            $rel = $this->normalizeEntry($name, $why);
            if ($rel === null) {
                throw new ExtractionException(sprintf('Unsafe entry "%s": %s', self::printable($name), $why));
            }

            if ($type === '0' || $type === "\0" || $type === '7') {
                if ($rel === '') {
                    throw new ExtractionException(sprintf('Unsafe entry "%s": empty path', self::printable($name)));
                }
                $this->writeFile($rel, substr($tar, $pos, $size), ($mode & 0111) !== 0);
            } elseif ($type === '5') {
                if ($rel !== '') {
                    $this->makeParents($rel.'/x');
                }
            } elseif ($type === '2') {
                if ($rel === '') {
                    throw new ExtractionException(sprintf('Unsafe entry "%s": empty path', self::printable($name)));
                }
                if (!self::isValidLinkTarget((string) $target)) {
                    throw new ExtractionException(sprintf('Unsafe symlink "%s" -> "%s"', self::printable($name), self::printable((string) $target)));
                }
                $this->links[] = [$rel, (string) $target];
            } else {
                $shown = $type === "\0" ? '0' : ($type > ' ' && $type < "\x7f" ? $type : '?');
                throw new ExtractionException(sprintf('Unsupported entry type \'%s\' for "%s"', $shown, self::printable($name)));
            }
            $pos += $padded;
        }

        // links last, so no file is ever written through one
        $this->createLinks();
        if (!$this->windows) {
            foreach ($this->links as [$rel, $target]) {
                if (!$this->linkStaysInside($rel, $target)) {
                    $this->removeLinks();
                    throw new ExtractionException(sprintf('Unsafe symlink "%s" -> "%s": resolves outside of the destination', $rel, $target));
                }
            }
        }
    }

    private static function octal(string $field): ?int
    {
        if ((\ord($field[0]) & 0x80) !== 0) {
            return null; // base-256: >8GB entries are not supported
        }
        if (preg_match('{^ *([0-7]+)[ \0]*$}D', $field, $m) !== 1) {
            return null;
        }

        return (int) octdec($m[1]);
    }

    /**
     * Archive-controlled text for error messages: bounded and without control characters.
     */
    private static function printable(string $s): string
    {
        $cut = \strlen($s) > self::MAX_MSG_NAME ? substr($s, 0, self::MAX_MSG_NAME).'...' : $s;

        return (string) preg_replace('{[\x00-\x1f\x7f]}', '?', $cut);
    }

    private static function cstr(string $s): string
    {
        $p = strpos($s, "\0");

        return $p === false ? $s : substr($s, 0, $p);
    }

    /**
     * pax extended header records: "<len> <key>=<value>\n"
     */
    private static function parsePax(string $d, ?string &$path, ?string &$linkpath): void
    {
        $n = \strlen($d);
        $pos = 0;
        while ($pos < $n) {
            if (preg_match('{\G(\d+) }', $d, $m, 0, $pos) !== 1) {
                throw new ExtractionException('Malformed pax header');
            }
            $len = (int) $m[1];
            if ($len === 0 || $len > $n - $pos || $d[$pos + $len - 1] !== "\n") {
                throw new ExtractionException('Malformed pax header');
            }
            $kv = substr($d, $pos + \strlen($m[0]), $len - \strlen($m[0]) - 1);
            $eq = strpos($kv, '=');
            if ($eq === false) {
                throw new ExtractionException('Malformed pax header');
            }
            $key = substr($kv, 0, $eq);
            $value = substr($kv, $eq + 1);
            if (strpos($value, "\0") !== false) {
                throw new ExtractionException('NUL byte in pax value');
            }
            if ($key === 'path') {
                $path = $value;
            } elseif ($key === 'linkpath') {
                $linkpath = $value;
            } elseif ($key === 'size') {
                throw new ExtractionException('pax size override is not supported');
            }
            $pos += $len;
        }
    }

    /**
     * Normalizes an entry name into "a/b/c" form, see the C implementation.
     */
    private function normalizeEntry(string $name, ?string &$why): ?string
    {
        if ($name === '') {
            $why = 'empty path';

            return null;
        }
        if ($name[0] === '/') {
            $why = 'absolute path';

            return null;
        }
        $out = [];
        foreach (explode('/', $name) as $c) {
            if ($c === '' || $c === '.') {
                continue;
            }
            if ($c === '..') {
                $why = 'parent directory reference';

                return null;
            }
            if (preg_match('{[\x00-\x1f\x7f\\\\]}', $c) === 1) {
                $why = 'forbidden character';

                return null;
            }
            if ($this->windows) {
                if (strpbrk($c, '<>:"|?*') !== false) {
                    $why = 'character not allowed on Windows';

                    return null;
                }
                $lastChar = substr($c, -1);
                if ($lastChar === '.' || $lastChar === ' ' || self::isReservedWindowsName($c)) {
                    $why = 'name not allowed on Windows';

                    return null;
                }
            }
            $out[] = $c;
        }
        $rel = implode('/', $out);
        if (\strlen($rel) >= self::MAX_REL) {
            $why = 'path too long';

            return null;
        }

        return $rel;
    }

    private static function isReservedWindowsName(string $c): bool
    {
        $dot = strpos($c, '.');
        $base = strtoupper(rtrim($dot === false ? $c : substr($c, 0, $dot), ' '));

        return \in_array($base, ['CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$'], true)
            || preg_match('{^(?:COM|LPT)(?:[0-9]|\xc2[\xb9\xb2\xb3])$}D', $base) === 1; // superscript 1-3 count too
    }

    private static function isValidLinkTarget(string $t): bool
    {
        return $t !== '' && $t[0] !== '/' && \strlen($t) < self::MAX_REL && preg_match('{[\x00-\x1f\x7f\\\\]}', $t) !== 1;
    }

    /**
     * @return int 0 missing, 1 file, 2 dir, 3 link, 4 other
     */
    private static function type(string $path): int
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return 3;
        }
        if (!file_exists($path)) {
            return 0;
        }
        if (is_dir($path)) {
            return 2;
        }

        return is_file($path) ? 1 : 4;
    }

    /**
     * Creates every parent directory of $rel, refusing to traverse anything that is not a real directory.
     */
    private function makeParents(string $rel): void
    {
        $slash = strrpos($rel, '/');
        if ($slash === false) {
            return;
        }
        $parent = substr($rel, 0, $slash);
        if ($parent === $this->lastParent) {
            return;
        }
        $prefix = '';
        foreach (explode('/', $parent) as $c) {
            $prefix = $prefix === '' ? $c : $prefix.'/'.$c;
            $full = $this->dest.'/'.$prefix;
            $t = self::type($full);
            if ($t === 0) {
                if (!@mkdir($full, 0777) && !is_dir($full)) {
                    throw new ExtractionException(sprintf('Cannot create directory %s: %s', $prefix, self::lastError()));
                }
                $t = self::type($full);
            }
            if ($t !== 2) {
                throw new ExtractionException(sprintf('%s exists and is not a directory', $prefix));
            }
        }
        $this->lastParent = $parent;
    }

    private function writeFile(string $rel, string $data, bool $exec): void
    {
        $this->makeParents($rel);
        $full = $this->dest.'/'.$rel;
        // "x" (O_EXCL) never follows a symlink at the final component
        $h = @fopen($full, 'xb');
        if ($h === false && self::type($full) !== 0) {
            // later duplicates win, but only ever replace a regular file
            if (self::type($full) !== 1) {
                throw new ExtractionException(sprintf('%s conflicts with an existing entry', $rel));
            }
            if (!@unlink($full)) {
                throw new ExtractionException(sprintf('Cannot replace %s: %s', $rel, self::lastError()));
            }
            $h = @fopen($full, 'xb');
        }
        if ($h === false) {
            throw new ExtractionException(sprintf('Cannot create %s: %s', $rel, self::lastError()));
        }
        $written = $data === '' ? 0 : fwrite($h, $data);
        if (!fclose($h) || $written !== \strlen($data)) {
            throw new ExtractionException(sprintf('Cannot write %s', $rel));
        }
        if ($exec && !$this->windows) {
            @chmod($full, 0777 & ~umask());
        }
    }

    private function createLinks(): void
    {
        // links now appear on disk, so every parent must be checked again
        $this->lastParent = null;
        foreach ($this->links as [$rel, $target]) {
            $this->makeParents($rel);
            $full = $this->dest.'/'.$rel;
            if ($this->windows) {
                // no symlinks on Windows: store the target as file content, like git with core.symlinks=false
                $h = @fopen($full, 'xb');
                if ($h === false && self::type($full) !== 0) {
                    throw new ExtractionException(sprintf('%s conflicts with an existing entry', $rel));
                }
                if ($h === false || fwrite($h, $target) !== \strlen($target) || !fclose($h)) {
                    throw new ExtractionException(sprintf('Cannot create %s: %s', $rel, self::lastError()));
                }
                continue;
            }
            if (!@symlink($target, $full)) {
                if (self::type($full) !== 0) {
                    throw new ExtractionException(sprintf('%s conflicts with an existing entry', $rel));
                }
                throw new ExtractionException(sprintf('Cannot create symlink %s: %s', $rel, self::lastError()));
            }
            $this->createdLinks[] = $full;
        }
    }

    private function removeLinks(): void
    {
        foreach ($this->createdLinks as $full) {
            @unlink($full);
        }
    }

    /**
     * Walks the link target on the real filesystem, following links like the kernel would, and fails as soon as
     * any step would leave the destination. Missing components are resolved lexically.
     */
    private function linkStaysInside(string $rel, string $target): bool
    {
        $slash = strrpos($rel, '/');
        $cur = $slash === false ? [] : explode('/', substr($rel, 0, $slash));
        $rem = explode('/', $target);
        $hops = 0;
        while ($rem !== []) {
            $c = array_shift($rem);
            if ($c === '' || $c === '.') {
                continue;
            }
            if ($c === '..') {
                if ($cur === []) {
                    return false;
                }
                array_pop($cur);
                continue;
            }
            $cur[] = $c;
            $path = implode('/', $cur);
            if (\strlen($path) >= self::MAX_REL) {
                return false;
            }
            $full = $this->dest.'/'.$path;
            clearstatcache(true, $full);
            if (is_link($full)) {
                $t = @readlink($full);
                if ($t === false || $t === '' || ++$hops > self::MAX_LINK_HOPS || $t[0] === '/') {
                    return false;
                }
                array_pop($cur);
                $rem = array_merge(explode('/', $t), $rem);
            }
        }

        return true;
    }

    private static function lastError(): string
    {
        $e = error_get_last();

        return $e !== null ? $e['message'] : 'unknown error';
    }
}
