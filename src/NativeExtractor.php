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
 * Runs the bundled zstd-extract binary.
 *
 * The binary runs in place when it is a plain executable file on disk. When it cannot (inside a phar, or not
 * executable), it is copied into the "zstd" subdirectory of Composer's cache directory first, see prepareCopy()
 * for how that is kept safe.
 *
 * @internal
 */
final class NativeExtractor
{
    private const EXIT_OK = 0;
    private const EXIT_INVALID = 1;
    private const EXIT_USAGE = 2;
    private const EXIT_IO = 3;

    /** @var string|null */
    private $cacheDir;
    /** @var string|null */
    private $binaryPath;
    /** @var string|false|null command to run, false once found unusable */
    private $command;
    /** @var resource|null verified binary kept open, executed through /proc/self/fd */
    private $handle;
    /** @var string|null */
    private $unavailableReason;
    /** @var string|null path of the executable file, in place or copied */
    private $path;

    /**
     * @param string|null $cacheDir   where the binary may be copied when it cannot run in place
     * @param string|null $binaryPath overrides the bundled binary
     */
    public function __construct(?string $cacheDir = null, ?string $binaryPath = null)
    {
        $this->cacheDir = $cacheDir;
        $this->binaryPath = $binaryPath;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }

    /**
     * File name of the bundled binary for the current platform, null if there is none.
     */
    public static function getPlatformBinaryName(): ?string
    {
        $machine = strtolower(php_uname('m'));
        switch (\PHP_OS_FAMILY) {
            case 'Linux':
                if ($machine === 'x86_64' || $machine === 'amd64') {
                    return 'zstd-extract-linux-x86_64';
                }
                if ($machine === 'aarch64' || $machine === 'arm64') {
                    return 'zstd-extract-linux-aarch64';
                }

                return null;
            case 'Darwin':
                return 'zstd-extract-darwin';
            case 'Windows':
                // arm64 Windows runs it through x64 emulation
                return 'zstd-extract-windows-x86_64.exe';
        }

        return null;
    }

    public function getUnavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    /**
     * Prepares the binary (copying it out if needed) and checks that it runs.
     */
    public function isAvailable(): bool
    {
        if ($this->command === null) {
            $command = $this->prepare();
            if ($command === false) {
                return false;
            }
            $this->command = $command;
            $result = $this->run([$command, '--version'], 1);
            if ($result === null || $result[0] !== self::EXIT_OK || strpos($result[1], 'zstd-extract ') !== 0) {
                return $this->unavailable($command.' --version failed'.($result !== null ? ' with exit code '.$result[0] : ''));
            }
        }

        return $this->command !== false;
    }

    /**
     * @param string $destination an existing empty directory
     * @return bool false if the binary could not be used and the caller should fall back to another method
     * @throws ExtractionException when the binary ran and rejected the archive or failed to write it
     */
    public function extract(string $archive, string $destination, int $maxSize): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $result = $this->run([(string) $this->command, '--max-size='.$maxSize, $archive, $destination], 2);
        if ($result === null) {
            return $this->unavailable('could not start '.$this->command);
        }

        return $this->handleResult($result[0], $result[1]);
    }

    /**
     * The command to extract an archive, for running it with another process runner (e.g. asynchronously).
     *
     * It refers to the binary by path, as the open handle trick of extract() needs control over the child's file
     * descriptors. Call isAvailable() first.
     *
     * @return list<string>
     */
    public function getCommand(string $archive, string $destination, int $maxSize): array
    {
        if ($this->path === null || !$this->isAvailable()) {
            throw new \LogicException('The bundled binary is not available');
        }

        return [$this->path, '--max-size='.$maxSize, $archive, $destination];
    }

    /**
     * Interprets the exit code of an extraction.
     *
     * @return bool false if the binary itself could not run (wrong arch, exec denied, crash), the caller should then
     *              fall back to another method
     * @throws ExtractionException when the binary rejected the archive or failed to write it
     */
    public function handleResult(int $code, string $stderr): bool
    {
        if ($code === self::EXIT_OK) {
            return true;
        }
        $message = trim(preg_replace('{^zstd-extract: }', '', trim($stderr)) ?? $stderr);
        if ($code === self::EXIT_INVALID || $code === self::EXIT_IO || $code === self::EXIT_USAGE) {
            throw new ExtractionException($message !== '' ? ucfirst($message) : 'zstd-extract failed with exit code '.$code);
        }

        // anything else means the binary itself could not run (wrong arch, exec denied, crash)
        return $this->unavailable(sprintf('%s exited with code %d: %s', (string) $this->command, $code, $message));
    }

    /**
     * @param list<string> $args
     * @param 1|2          $capture stdout or stderr; never both, a child filling the pipe we are not reading would hang
     * @return array{int, string}|null exit code, captured output; null if the process could not start
     */
    private function run(array $args, int $capture): ?array
    {
        $descriptors = [
            $capture => ['pipe', 'w'],
            3 - $capture => ['file', Filesystem::isWindows() ? 'NUL' : '/dev/null', 'w'],
        ];
        if ($this->handle !== null) {
            $descriptors[3] = $this->handle;
        }
        if (\PHP_VERSION_ID >= 70400) {
            $process = @proc_open($args, $descriptors, $pipes);
        } elseif (Filesystem::isWindows()) {
            $process = @proc_open(implode(' ', array_map([Filesystem::class, 'escapeWindowsArg'], $args)), $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } else {
            $process = @proc_open(implode(' ', array_map('escapeshellarg', $args)), $descriptors, $pipes);
        }
        if (!\is_resource($process)) {
            return null;
        }
        $output = (string) stream_get_contents($pipes[$capture]);
        fclose($pipes[$capture]);

        return [proc_close($process), $output];
    }

    /**
     * @return false
     */
    private function unavailable(string $reason): bool
    {
        $this->unavailableReason = $reason;
        $this->command = false;

        return false;
    }

    /**
     * @return string|false
     */
    private function prepare()
    {
        if (!\function_exists('proc_open')) {
            return $this->unavailable('proc_open is not available');
        }
        $source = $this->locateSource();
        if ($source === null) {
            return false;
        }
        $inPlace = self::inPlaceCommand($source);
        if ($inPlace !== null) {
            $this->path = $inPlace;

            return $inPlace;
        }

        try {
            return $this->prepareCopy($source);
        } catch (ExtractionException $e) {
            return $this->unavailable($e->getMessage());
        }
    }

    private function locateSource(): ?string
    {
        if ($this->binaryPath !== null) {
            $source = $this->binaryPath;
        } else {
            $name = self::getPlatformBinaryName();
            if ($name === null) {
                $this->unavailable('no binary for '.\PHP_OS_FAMILY.'/'.php_uname('m'));

                return null;
            }
            $source = \dirname(__DIR__).'/bin/'.$name;
        }
        if (!is_file($source)) {
            $this->unavailable($source.' does not exist');

            return null;
        }

        return $source;
    }

    /**
     * A plain executable file on disk is as trusted as this PHP code next to it, so it runs in place.
     */
    private static function inPlaceCommand(string $source): ?string
    {
        if (strpos($source, '://') !== false) {
            return null;
        }
        if (is_executable($source)) {
            return $source;
        }
        if (@chmod($source, 0755)) {
            clearstatcache(true, $source);
            if (is_executable($source)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Copies the binary into a private directory and returns the command to run it.
     *
     * Swapping the file between the hash check and execution requires write access to that directory, which is
     * verified to be only possible for the current user (or root), who could tamper with this PHP process anyway.
     * On Linux the race is closed entirely: the verified file stays open and is executed through /proc/self/fd.
     */
    private function prepareCopy(string $source): string
    {
        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new ExtractionException('Could not read '.$source);
        }
        $dir = $this->cacheDir ?? self::getComposerCacheDir();
        if ($dir === null) {
            throw new ExtractionException('No cache directory available to copy the binary to');
        }
        $dir = rtrim($dir, '/\\').'/zstd';
        $uid = self::ensurePrivateDir($dir);
        if ($uid !== null) {
            // only the resolved path was verified, a symlink on the way to it could be repointed later
            $dir = (string) realpath($dir);
        }

        $target = $dir.'/'.substr($hash, 0, 16).'-'.basename($source);
        $handle = $this->openVerified($target, $hash, $uid);
        if ($handle === null) {
            $tmp = $dir.'/.tmp-'.bin2hex(random_bytes(8));
            $in = @fopen($source, 'rb');
            $out = @fopen($tmp, 'xb');
            if ($in === false || $out === false) {
                throw new ExtractionException('Could not copy the binary to '.$dir);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            @chmod($tmp, 0700);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new ExtractionException('Could not copy the binary to '.$target);
            }
            $handle = $this->openVerified($target, $hash, $uid);
            if ($handle === null) {
                throw new ExtractionException($target.' does not match the bundled binary');
            }
        }

        $this->path = $target;
        if (\PHP_OS_FAMILY === 'Linux' && is_dir('/proc/self/fd')) {
            $this->handle = $handle;

            return '/proc/self/fd/3';
        }
        fclose($handle);

        return $target;
    }

    /**
     * @return resource|null open handle on the file if it is a regular file owned by us matching the hash
     */
    private function openVerified(string $path, string $hash, ?int $uid)
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return null;
        }
        // checks go through the handle, so they are about the very file that will be executed
        $stat = fstat($h);
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $h);
        if ($stat === false || hash_final($ctx) !== $hash
            || ($uid !== null && ($stat['uid'] !== $uid || ($stat['mode'] & 0022) !== 0))
        ) {
            fclose($h);
            @unlink($path);

            return null;
        }

        return $h;
    }

    /**
     * Makes sure $dir is a directory only the current user can write to, and that it cannot be swapped by
     * anyone else either (every ancestor is owned by us or root, and sticky if writable by others).
     *
     * @return int|null the current user id, null on Windows where ACLs are not checked
     */
    private static function ensurePrivateDir(string $dir): ?int
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir)) {
            throw new ExtractionException($dir.' is not a directory');
        }
        if (Filesystem::isWindows()) {
            return null;
        }

        // the owner of a file we create is our effective uid, without requiring ext-posix
        $probe = $dir.'/.probe-'.bin2hex(random_bytes(8));
        $h = @fopen($probe, 'xb');
        if ($h === false) {
            throw new ExtractionException($dir.' is not writable');
        }
        $stat = fstat($h);
        fclose($h);
        @unlink($probe);
        if ($stat === false) {
            throw new ExtractionException('Could not stat '.$probe);
        }
        $uid = $stat['uid'];

        $dirStat = @lstat($dir);
        if ($dirStat === false || $dirStat['uid'] !== $uid) {
            throw new ExtractionException($dir.' is not owned by the current user');
        }
        if (($dirStat['mode'] & 0022) !== 0) {
            if (!@chmod($dir, 0700)) {
                throw new ExtractionException($dir.' is writable by other users');
            }
        }

        $path = realpath($dir);
        if ($path === false) {
            throw new ExtractionException('Could not resolve '.$dir);
        }
        while (($parent = \dirname($path)) !== $path) {
            $path = $parent;
            $s = @stat($path);
            if ($s === false) {
                throw new ExtractionException('Could not stat '.$path);
            }
            if ($s['uid'] !== 0 && $s['uid'] !== $uid) {
                throw new ExtractionException($path.' is owned by another user');
            }
            if (($s['mode'] & 0022) !== 0 && ($s['mode'] & 01000) === 0) {
                throw new ExtractionException($path.' is writable by other users');
            }
        }

        return $uid;
    }

    /**
     * Composer's cache directory, resolved the same way as Composer\Factory::getCacheDir() does.
     */
    public static function getComposerCacheDir(): ?string
    {
        $dir = self::env('COMPOSER_CACHE_DIR');
        if ($dir !== null) {
            return $dir;
        }
        $composerHome = self::env('COMPOSER_HOME');
        if ($composerHome !== null) {
            return $composerHome.'/cache';
        }

        if (Filesystem::isWindows()) {
            $base = self::env('LOCALAPPDATA');
            if ($base !== null) {
                return rtrim(strtr($base, '\\', '/'), '/').'/Composer';
            }
            $appData = self::env('APPDATA');

            return $appData !== null ? rtrim(strtr($appData, '\\', '/'), '/').'/Composer/cache' : null;
        }

        $userDir = self::env('HOME');
        if ($userDir === null) {
            return null;
        }
        $userDir = rtrim(strtr($userDir, '\\', '/'), '/');
        if (\PHP_OS === 'Darwin') {
            return $userDir.'/Library/Caches/composer';
        }

        // Composer's home: the first existing of $XDG_CONFIG_HOME/composer (on XDG systems) and ~/.composer
        $useXdg = self::useXdg();
        $homes = [];
        if ($useXdg) {
            $homes[] = (self::env('XDG_CONFIG_HOME') ?? $userDir.'/.config').'/composer';
        }
        $homes[] = $userDir.'/.composer';
        $home = $homes[0];
        foreach ($homes as $candidate) {
            if (@is_dir($candidate)) {
                $home = $candidate;
                break;
            }
        }

        if ($home === $userDir.'/.composer' && is_dir($home.'/cache')) {
            return $home.'/cache';
        }
        if ($useXdg) {
            return (self::env('XDG_CACHE_HOME') ?? $userDir.'/.cache').'/composer';
        }

        return $home.'/cache';
    }

    private static function useXdg(): bool
    {
        foreach (array_keys($_SERVER) as $key) {
            if (strpos((string) $key, 'XDG_') === 0) {
                return true;
            }
        }

        return @is_dir('/etc/xdg');
    }

    /**
     * Like Composer's Platform::getEnv(): $_SERVER first, then getenv(); empty values count as unset.
     */
    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
