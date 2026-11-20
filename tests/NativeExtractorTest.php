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

use Composer\Zstd\Extractor;
use Composer\Zstd\Filesystem;
use Composer\Zstd\NativeExtractor;

class NativeExtractorTest extends TestCase
{
    private function archive(): string
    {
        return $this->writeArchive((new TarBuilder())->file('a', 'hello')->zst());
    }

    private function requireBinary(): string
    {
        $binary = self::bundledBinary();
        if ($binary === null) {
            self::markTestSkipped('No native binary available for this platform');
        }

        return $binary;
    }

    public function testFallsBackToPhpWhenBinaryIsMissing(): void
    {
        $extractor = new Extractor(null, [Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], $this->tmp.'/missing');
        $extractor->extract($this->archive(), $this->tmp.'/dest');
        self::assertSame('php', $extractor->getLastMethod());
        self::assertStringContainsString('does not exist', (string) ($extractor->getUnavailableReasons()[Extractor::METHOD_BUNDLED] ?? ''));
        self::assertSame(['a' => 'file:hello'], self::tree($this->tmp.'/dest'));
    }

    public function testFallsBackToPhpWhenBinaryCannotRun(): void
    {
        if (Filesystem::isWindows()) {
            self::markTestSkipped('POSIX only');
        }
        file_put_contents($this->tmp.'/garbage', str_repeat("\x7fELF garbage", 10));
        chmod($this->tmp.'/garbage', 0755);
        $extractor = new Extractor(null, [Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], $this->tmp.'/garbage');
        $extractor->extract($this->archive(), $this->tmp.'/dest');
        self::assertSame('php', $extractor->getLastMethod());
        self::assertSame(['a' => 'file:hello'], self::tree($this->tmp.'/dest'));
    }

    public function testRunsInPlace(): void
    {
        $extractor = new Extractor($this->tmp.'/cache', [Extractor::METHOD_BUNDLED], $this->requireBinary());
        $extractor->extract($this->archive(), $this->tmp.'/dest');
        self::assertSame(Extractor::METHOD_BUNDLED, $extractor->getLastMethod());
        self::assertFalse(is_dir($this->tmp.'/cache'), 'no copy is made');
    }

    /**
     * Paths with a stream wrapper (e.g. phar://) cannot be executed and get copied to the cache dir.
     */
    public function testCopiesBinaryToPrivateCacheDir(): void
    {
        $binary = $this->requireBinary();
        $source = 'file://'.$binary;
        $cache = $this->tmp.'/cache';

        $extractor = new Extractor($cache, [Extractor::METHOD_BUNDLED], $source);
        $extractor->extract($this->archive(), $this->tmp.'/dest1');
        self::assertSame(Extractor::METHOD_BUNDLED, $extractor->getLastMethod());

        $copies = self::glob($cache.'/zstd/*');
        self::assertCount(1, $copies);
        self::assertSame(hash_file('sha256', $binary), hash_file('sha256', $copies[0]));
        if (!Filesystem::isWindows()) {
            self::assertSame(0700, fileperms($cache.'/zstd') & 0777);
        }

        // a tampered copy is detected and replaced
        file_put_contents($copies[0], 'tampered');
        $extractor = new Extractor($cache, [Extractor::METHOD_BUNDLED], $source);
        $extractor->extract($this->archive(), $this->tmp.'/dest2');
        self::assertSame(hash_file('sha256', $binary), hash_file('sha256', $copies[0]));
    }

    /**
     * On Linux the verified file is executed through its open handle, so swapping it afterwards has no effect.
     */
    public function testSwappingTheCopyAfterVerificationHasNoEffectOnLinux(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux only');
        }
        $cache = $this->tmp.'/cache';
        $extractor = new Extractor($cache, [Extractor::METHOD_BUNDLED], 'file://'.$this->requireBinary());
        $extractor->extract($this->archive(), $this->tmp.'/dest1');

        $copies = self::glob($cache.'/zstd/*');
        self::assertCount(1, $copies);
        rename($copies[0], $copies[0].'.old');
        file_put_contents($copies[0], "#!/bin/sh\nexit 0\n");
        chmod($copies[0], 0700);

        $extractor->extract($this->archive(), $this->tmp.'/dest2');
        self::assertSame(['a' => 'file:hello'], self::tree($this->tmp.'/dest2'));
    }

    /**
     * @return array<string, array{callable(string): string}>
     */
    public static function provideInsecureCacheDirs(): array
    {
        return [
            'symlinked dir' => [static function (string $tmp): string {
                mkdir($tmp.'/elsewhere');
                mkdir($tmp.'/cache');
                symlink($tmp.'/elsewhere', $tmp.'/cache/zstd');

                return $tmp.'/cache';
            }],
            'world writable ancestor' => [static function (string $tmp): string {
                mkdir($tmp.'/shared');
                chmod($tmp.'/shared', 0777);

                return $tmp.'/shared/cache';
            }],
        ];
    }

    /**
     * @dataProvider provideInsecureCacheDirs
     * @param callable(string): string $setup
     */
    public function testInsecureCacheDirIsNotUsed(callable $setup): void
    {
        if (Filesystem::isWindows()) {
            self::markTestSkipped('POSIX only');
        }
        $source = 'file://'.$this->requireBinary();
        $extractor = new Extractor($setup($this->tmp), [Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], $source);
        $extractor->extract($this->archive(), $this->tmp.'/dest');
        self::assertSame('php', $extractor->getLastMethod());
        self::assertArrayHasKey(Extractor::METHOD_BUNDLED, $extractor->getUnavailableReasons());
    }

    /**
     * @return array<string, array{array<string, string>, string|null, string}>
     */
    public static function provideCacheDirEnvs(): array
    {
        return [
            'COMPOSER_CACHE_DIR' => [['COMPOSER_CACHE_DIR' => '/custom/cache', 'COMPOSER_HOME' => '/ignored'], null, '/custom/cache'],
            'COMPOSER_HOME' => [['COMPOSER_HOME' => '/composer/home'], null, '/composer/home/cache'],
            'XDG_CACHE_HOME' => [['XDG_CACHE_HOME' => '/xdg/cache'], null, '/xdg/cache/composer'],
            'legacy ~/.composer/cache' => [[], '.composer/cache', '{home}/.composer/cache'],
        ];
    }

    /**
     * Same resolution as Composer\Factory::getCacheDir()
     *
     * @dataProvider provideCacheDirEnvs
     * @param array<string, string> $env
     */
    public function testComposerCacheDirResolution(array $env, ?string $createInHome, string $expected): void
    {
        if (\PHP_OS !== 'Linux') {
            self::markTestSkipped('Linux resolution rules');
        }
        $vars = ['COMPOSER_CACHE_DIR', 'COMPOSER_HOME', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'HOME'];
        $backup = [];
        foreach ($vars as $var) {
            $backup[$var] = [$_SERVER[$var] ?? null, getenv($var)];
            unset($_SERVER[$var]);
            putenv($var);
        }
        $env += ['HOME' => $this->tmp];
        if ($createInHome !== null) {
            mkdir($this->tmp.'/'.$createInHome, 0777, true);
        }
        try {
            foreach ($env as $var => $value) {
                $_SERVER[$var] = $value;
            }
            self::assertSame(str_replace('{home}', $this->tmp, $expected), NativeExtractor::getComposerCacheDir());
        } finally {
            foreach ($backup as $var => [$server, $value]) {
                unset($_SERVER[$var]);
                if ($server !== null) {
                    $_SERVER[$var] = $server;
                }
                putenv($value === false ? $var : $var.'='.$value);
            }
        }
    }

    public function testAvailableMethods(): void
    {
        $extractor = new Extractor(null, [Extractor::METHOD_ZSTD_CLI, Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], $this->tmp.'/missing', $this->tmp.'/missing-zstd');
        self::assertSame([Extractor::METHOD_PHP], $extractor->getAvailableMethods());
        self::assertSame([Extractor::METHOD_ZSTD_CLI, Extractor::METHOD_BUNDLED], array_keys($extractor->getUnavailableReasons()));

        $extractor = new Extractor($this->tmp.'/cache', null, self::bundledBinary(), self::zstdCli());
        $expected = array_values(array_intersect($extractor->getMethodOrder(), self::availableMethods()));
        self::assertSame($expected, $extractor->getAvailableMethods());
    }

    public function testBundledComesFirstAndDecidingCopiesNothing(): void
    {
        $extractor = new Extractor($this->tmp.'/cache', null, 'file://'.$this->requireBinary());
        self::assertSame([Extractor::METHOD_BUNDLED, Extractor::METHOD_EXT_ZSTD, Extractor::METHOD_ZSTD_CLI, Extractor::METHOD_PHP], $extractor->getMethodOrder());
        self::assertFalse(is_dir($this->tmp.'/cache'));
    }

    /**
     * prepare() only picks the bundled binary once its copy worked, otherwise it moves on to the next method.
     */
    public function testPrepareSkipsBundledWhenTheCopyFails(): void
    {
        if (Filesystem::isWindows()) {
            self::markTestSkipped('POSIX only');
        }
        mkdir($this->tmp.'/shared');
        chmod($this->tmp.'/shared', 0777);
        $extractor = new Extractor($this->tmp.'/shared/cache', null, 'file://'.$this->requireBinary(), $this->tmp.'/missing-zstd');
        $extraction = $extractor->prepare($this->archive(), $this->tmp.'/dest');
        self::assertNotSame(Extractor::METHOD_BUNDLED, $extraction->getMethod());
        self::assertArrayHasKey(Extractor::METHOD_BUNDLED, $extractor->getUnavailableReasons());
    }

    /**
     * A copy that cannot be made safely makes the bundled method unavailable, extraction continues with the next one.
     */
    public function testFailedCopyFallsThrough(): void
    {
        if (Filesystem::isWindows()) {
            self::markTestSkipped('POSIX only');
        }
        mkdir($this->tmp.'/shared');
        chmod($this->tmp.'/shared', 0777);
        $extractor = new Extractor($this->tmp.'/shared/cache', [Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], 'file://'.$this->requireBinary());
        $extractor->extract($this->archive(), $this->tmp.'/dest');
        self::assertSame(Extractor::METHOD_PHP, $extractor->getLastMethod());
        self::assertStringContainsString('writable by other users', $extractor->getUnavailableReasons()[Extractor::METHOD_BUNDLED]);
    }
}
