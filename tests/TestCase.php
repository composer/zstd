<?php

/*
 * This file is part of composer/zstd.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Zstd\Tests;

use Composer\Zstd\ExtZstd;
use Composer\Zstd\Extraction;
use Composer\Zstd\Extractor;
use Composer\Zstd\Filesystem;
use Composer\Zstd\NativeExtractor;
use Composer\Zstd\ZstdCli;

/**
 * Environment variables:
 * - ZSTD_EXTRACT_BINARY: bundled binary to test (e.g. a fresh build), defaults to bin/ for this platform
 * - ZSTD_CLI: zstd executable to test, defaults to a lookup in PATH
 * - ZSTD_TEST_REQUIRE_METHODS: comma separated methods that must be available (set in CI)
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var string */
    protected $tmp = '';

    /** @var list<string>|null */
    private static $methods;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/composer-zstd-test-'.bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700);
        $this->tmp = (string) realpath($this->tmp);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            chmod($this->tmp, 0700);
            Filesystem::remove($this->tmp);
        }
    }

    protected static function bundledBinary(): ?string
    {
        $env = getenv('ZSTD_EXTRACT_BINARY');
        if (\is_string($env) && $env !== '') {
            return $env;
        }
        $name = NativeExtractor::getPlatformBinaryName();
        $path = \dirname(__DIR__).'/bin/'.$name;

        return $name !== null && is_file($path) ? $path : null;
    }

    protected static function zstdCli(): ?string
    {
        $env = getenv('ZSTD_CLI');

        return \is_string($env) && $env !== '' ? $env : null;
    }

    /**
     * Extraction methods available in this environment.
     *
     * @return list<string>
     */
    protected static function availableMethods(): array
    {
        if (self::$methods === null) {
            self::$methods = [];
            if (ExtZstd::isAvailable()) {
                self::$methods[] = Extractor::METHOD_EXT_ZSTD;
            }
            if (self::bundledBinary() !== null) {
                self::$methods[] = Extractor::METHOD_BUNDLED;
            }
            if ((new ZstdCli(self::zstdCli()))->isAvailable()) {
                self::$methods[] = Extractor::METHOD_ZSTD_CLI;
            }
            self::$methods[] = Extractor::METHOD_PHP;
        }

        return self::$methods;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideMethods(): array
    {
        $result = [];
        foreach (self::availableMethods() as $method) {
            $result[$method] = [$method];
        }

        return $result;
    }

    /**
     * An extractor restricted to a single method.
     */
    protected function extractor(string $method, int $maxSize = Extractor::DEFAULT_MAX_SIZE): Extractor
    {
        foreach (Extractor::METHODS as $known) {
            if ($known === $method) {
                return new Extractor($this->tmp.'/cache', [$known], self::bundledBinary(), self::zstdCli(), $maxSize);
            }
        }
        throw new \LogicException('Unknown method '.$method);
    }

    /**
     * Runs a prepared extraction the way an asynchronous process runner would: the command in a separate process,
     * then complete() with its result.
     */
    protected function runLikeProcessRunner(Extraction $extraction): void
    {
        $command = $extraction->getCommand();
        if ($command === null) {
            $extraction->run();

            return;
        }
        $out = $this->tmp.'/stdout-'.bin2hex(random_bytes(4));
        $err = $this->tmp.'/stderr-'.bin2hex(random_bytes(4));
        $process = self::startProcess($command, [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes);
        self::assertIsResource($process);
        $code = proc_close($process);
        $extraction->complete($code, (string) file_get_contents($out), (string) file_get_contents($err));
        unlink($out);
        unlink($err);
    }

    /**
     * proc_open() with an argument list, also on PHP < 7.4.
     *
     * @param list<string>          $command
     * @param array<int, list<string>|resource> $descriptors
     * @param array<int, resource>  $pipes
     * @return resource
     */
    protected static function startProcess(array $command, array $descriptors, &$pipes)
    {
        if (\PHP_VERSION_ID >= 70400) {
            $process = proc_open($command, $descriptors, $pipes);
        } elseif (Filesystem::isWindows()) {
            $process = proc_open(implode(' ', array_map([Filesystem::class, 'escapeWindowsArg'], $command)), $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        } else {
            $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), $descriptors, $pipes);
        }
        self::assertIsResource($process);

        return $process;
    }

    /**
     * @return list<string>
     */
    protected static function glob(string $pattern): array
    {
        $result = glob($pattern);

        return $result === false ? [] : $result;
    }

    protected function writeArchive(string $data): string
    {
        $path = $this->tmp.'/archive-'.bin2hex(random_bytes(4)).'.tar.zst';
        file_put_contents($path, $data);

        return $path;
    }

    /**
     * Lists a directory tree as path => description, without following symlinks.
     *
     * @return array<string, string>
     */
    protected static function tree(string $dir, string $prefix = ''): array
    {
        $result = [];
        $entries = scandir($dir);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            $rel = $prefix.$entry;
            if (is_link($path)) {
                $result[$rel] = 'link:'.readlink($path);
            } elseif (is_dir($path)) {
                $result[$rel] = 'dir';
                $result += self::tree($path, $rel.'/');
            } else {
                $result[$rel] = (Filesystem::isWindows() ? '' : (is_executable($path) ? 'x:' : '')).'file:'.file_get_contents($path);
            }
        }
        ksort($result);

        return $result;
    }

    protected static function link(string $target): string
    {
        // Windows stores symlinks as plain files holding the target
        return Filesystem::isWindows() ? 'file:'.$target : 'link:'.$target;
    }
}
