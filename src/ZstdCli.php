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
 * Decompresses with a zstd command line tool found in PATH.
 *
 * @internal
 */
final class ZstdCli
{
    /** -q, -- and the decompression memory limit behave the same since 1.4 */
    private const MIN_VERSION = '1.4.0';

    /** @var string|null */
    private $path;
    /** @var bool|null */
    private $available;
    /** @var string|null */
    private $unavailableReason;

    /**
     * @param string|null $path zstd executable, looked up in PATH by default
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path;
    }

    public function isAvailable(): bool
    {
        if ($this->available === null) {
            $this->available = $this->probe();
        }

        return $this->available;
    }

    public function getUnavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    /**
     * @param string $archive zstd also decompresses gzip, xz, lz4 and legacy zstd when built for it, this is not prevented
     * @param int    $maxSize rejects input decompressing to more bytes than this
     * @throws ExtractionException when zstd rejects the data
     */
    public function decompress(string $archive, int $maxSize): string
    {
        [$code, $stdout, $stderr] = self::run($this->getCommand($archive), $maxSize);
        if (\strlen($stdout) > $maxSize) {
            throw ZstdDecoder::tooLarge($maxSize);
        }

        return $this->handleResult($code, $stdout, $stderr);
    }

    /**
     * The command decompressing an archive to stdout, for running it with another process runner.
     * Call isAvailable() first.
     *
     * @return list<string>
     */
    public function getCommand(string $archive): array
    {
        if (!$this->isAvailable()) {
            throw new \LogicException('zstd is not available');
        }

        return [(string) $this->path, '-d', '-c', '-q', '--', $archive];
    }

    /**
     * @return string the decompressed data
     * @throws ExtractionException when zstd rejected the data
     */
    public function handleResult(int $code, string $stdout, string $stderr): string
    {
        if ($code !== 0) {
            $message = trim(preg_replace('{^.*?: }', '', trim($stderr)) ?? $stderr);
            throw new ExtractionException('zstd: '.($message !== '' ? $message : 'exit code '.$code));
        }

        return $stdout;
    }

    private function probe(): bool
    {
        if (!\function_exists('proc_open')) {
            $this->unavailableReason = 'proc_open is not available';

            return false;
        }
        if ($this->path === null) {
            $this->path = self::findInPath();
            if ($this->path === null) {
                $this->unavailableReason = 'zstd was not found in PATH';

                return false;
            }
        }
        [$code, $stdout] = self::run([$this->path, '-V']);
        if ($code !== 0 || preg_match('{\bv(\d+\.\d+\.\d+)\b}', $stdout, $m) !== 1) {
            $this->unavailableReason = $this->path.' -V failed';

            return false;
        }
        if (version_compare($m[1], self::MIN_VERSION, '<')) {
            $this->unavailableReason = $this->path.' is version '.$m[1].', '.self::MIN_VERSION.'+ is required';

            return false;
        }

        return true;
    }

    private static function findInPath(): ?string
    {
        $paths = getenv('PATH');
        if (!\is_string($paths) || $paths === '') {
            return null;
        }
        $name = Filesystem::isWindows() ? 'zstd.exe' : 'zstd';
        foreach (explode(\PATH_SEPARATOR, $paths) as $dir) {
            // relative entries would run a zstd planted in the working directory (a project being installed)
            if (!self::isAbsolute($dir)) {
                continue;
            }
            $candidate = rtrim($dir, '/\\').\DIRECTORY_SEPARATOR.$name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isAbsolute(string $dir): bool
    {
        if (Filesystem::isWindows()) {
            return preg_match('{^(?:[a-z]:[\\\\/]|[\\\\/]{2})}i', $dir) === 1;
        }

        return $dir !== '' && $dir[0] === '/';
    }

    /**
     * @param list<string> $args
     * @param int          $maxOutput the process is killed once stdout goes over this
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function run(array $args, int $maxOutput = \PHP_INT_MAX): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if (\PHP_VERSION_ID >= 70400) {
            $process = @proc_open($args, $descriptors, $pipes);
        } elseif (Filesystem::isWindows()) {
            $process = @proc_open(implode(' ', array_map([Filesystem::class, 'escapeWindowsArg'], $args)), $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } else {
            $process = @proc_open(implode(' ', array_map('escapeshellarg', $args)), $descriptors, $pipes);
        }
        if (!\is_resource($process)) {
            return [-1, '', 'could not start '.$args[0]];
        }
        fclose($pipes[0]);
        // stderr only gets short messages, so reading stdout first cannot block on a full stderr pipe
        $stdout = (string) stream_get_contents($pipes[1], $maxOutput < \PHP_INT_MAX ? $maxOutput + 1 : -1);
        if (\strlen($stdout) > $maxOutput) {
            proc_terminate($process);
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
