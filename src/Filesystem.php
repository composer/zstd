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
 * @internal
 */
final class Filesystem
{
    /**
     * Removes a directory tree without ever following symlinks.
     */
    public static function remove(string $path): void
    {
        // not recursive: an archive can nest directories thousands of levels deep
        $todo = [[$path, false]];
        while ($todo !== []) {
            [$path, $emptied] = array_pop($todo);
            if ($emptied) {
                if (!@rmdir($path)) {
                    throw new ExtractionException('Could not remove directory '.$path);
                }
                continue;
            }
            clearstatcache(true, $path);
            if (is_link($path) || is_file($path)) {
                if (!@unlink($path) && \DIRECTORY_SEPARATOR === '\\' && is_dir($path)) {
                    @rmdir($path); // directory symlinks/junctions on Windows
                }
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $entries = @scandir($path);
            if ($entries === false) {
                throw new ExtractionException('Could not read directory '.$path);
            }
            $todo[] = [$path, true];
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $todo[] = [$path.'/'.$entry, false];
                }
            }
        }
    }

    /**
     * Removes everything inside a directory, without following symlinks.
     */
    public static function clear(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($dir.'/'.$entry);
            }
        }
    }

    public static function isEmptyDir(string $path): bool
    {
        $entries = @scandir($path);

        return $entries !== false && \count($entries) <= 2;
    }

    public static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR === '\\';
    }

    /**
     * Quotes an argument for CreateProcess (MSVCRT rules), only used on PHP < 7.4.
     */
    public static function escapeWindowsArg(string $arg): string
    {
        if ($arg !== '' && preg_match('{[\s"]}', $arg) !== 1) {
            return $arg;
        }

        return '"'.preg_replace_callback('{(\\\\*)("|$)}', static function (array $m): string {
            return str_repeat('\\', 2 * \strlen($m[1])).($m[2] === '"' ? '\\"' : '');
        }, $arg).'"';
    }
}
