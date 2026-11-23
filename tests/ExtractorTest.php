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

use Composer\Zstd\ExtractionException;
use Composer\Zstd\Extractor;
use Composer\Zstd\Filesystem;

/**
 * Valid archives, run through every available method. Malicious ones are in BadActorTest.
 */
class ExtractorTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function extract(string $method, string $archiveData): array
    {
        $dest = $this->tmp.'/dest';
        $extractor = $this->extractor($method);
        $extractor->extract($this->writeArchive($archiveData), $dest);
        self::assertSame($method, $extractor->getLastMethod());

        return self::tree($dest);
    }

    /**
     * Fails when a method CI is expected to cover is missing, instead of silently skipping it.
     */
    public function testRequiredMethodsAreAvailable(): void
    {
        $required = getenv('ZSTD_TEST_REQUIRE_METHODS');
        if (!\is_string($required) || $required === '') {
            self::markTestSkipped('ZSTD_TEST_REQUIRE_METHODS is not set');
        }
        foreach (explode(',', $required) as $method) {
            self::assertContains(trim($method), self::availableMethods());
        }
    }

    /**
     * @dataProvider provideMethods
     */
    public function testRegularEntries(string $method): void
    {
        $tar = (new TarBuilder())
            ->dir('./')
            ->dir('./a/')
            ->file('./a/b.txt', 'hello')
            ->file('bin/run', "#!/bin/sh\n", 0755)
            ->file('empty')
            ->file('deep//nested/./dir/file', 'x')
            ->file('dup', 'first')
            ->file('dup', 'second')
            ->dir('emptydir');

        $expected = [
            'a' => 'dir',
            'a/b.txt' => 'file:hello',
            'bin' => 'dir',
            'bin/run' => (Filesystem::isWindows() ? '' : 'x:')."file:#!/bin/sh\n",
            'deep' => 'dir',
            'deep/nested' => 'dir',
            'deep/nested/dir' => 'dir',
            'deep/nested/dir/file' => 'file:x',
            'dup' => 'file:second',
            'empty' => 'file:',
            'emptydir' => 'dir',
        ];
        self::assertSame($expected, $this->extract($method, $tar->zst()));
    }

    /**
     * @dataProvider provideMethods
     */
    public function testLongNames(string $method): void
    {
        self::requireLongPaths();
        $long = str_repeat('d', 120).'/'.str_repeat('f', 150);
        $tar = (new TarBuilder())
            ->gnuLong('gnu/'.$long, '0', 'gnu')
            ->pax(['path' => 'pax/'.$long, 'mtime' => '0'], 'ignored', '0', 'pax')
            ->entry('file', '0', 'ustar', 0644, '', 'prefix/dir')
            ->gnuLong('link-'.str_repeat('l', 120), '2', '', 'gnu/'.$long)
            ->pax(['path' => str_repeat('n/', 127).'deep'], 'x', '0', 'deep'); // the most components allowed

        $tree = $this->extract($method, $tar->zst());
        self::assertSame('file:gnu', $tree['gnu/'.$long]);
        self::assertSame('file:pax', $tree['pax/'.$long]);
        self::assertSame('file:ustar', $tree['prefix/dir/file']);
        self::assertSame(self::link('gnu/'.$long), $tree['link-'.str_repeat('l', 120)]);
        self::assertArrayNotHasKey('ignored', $tree);
        self::assertSame('file:deep', $tree[str_repeat('n/', 127).'deep']);
    }

    /**
     * @dataProvider provideMethods
     */
    public function testSafeSymlinks(string $method): void
    {
        $tar = (new TarBuilder())
            ->file('a/b.txt', 'hello')
            ->symlink('link', 'a/b.txt')
            ->symlink('a/up', '../link')
            ->symlink('dirlink', 'a')
            ->symlink('chain', 'dirlink/b.txt')
            ->symlink('dangling', 'missing/../nothing')
            ->symlink('deep/deep/root', '../..')
            ->symlink('self-dir', './a/./');

        $tree = $this->extract($method, $tar->zst());
        self::assertSame(self::link('a/b.txt'), $tree['link']);
        self::assertSame(self::link('../link'), $tree['a/up']);
        self::assertSame(self::link('dirlink/b.txt'), $tree['chain']);
        self::assertSame(self::link('missing/../nothing'), $tree['dangling']);
        self::assertSame(self::link('../..'), $tree['deep/deep/root']);
        if (!Filesystem::isWindows()) {
            self::assertSame('hello', file_get_contents($this->tmp.'/dest/chain'));
            self::assertSame('hello', file_get_contents($this->tmp.'/dest/a/up'));
        }
    }

    /**
     * @dataProvider provideMethods
     */
    public function testMultipleFramesAndSkippableFrames(string $method): void
    {
        $tar = (new TarBuilder())->file('a', str_repeat('a', 200000))->file('b', 'b')->build();
        $data = pack('V', 0x184D2A50).pack('V', 0)
            .TarBuilder::zstdFrame(substr($tar, 0, 1000))
            .pack('V', 0x184D2A5F).pack('V', 3).'abc'
            .TarBuilder::zstdFrame(substr($tar, 1000), 17);

        self::assertSame(['a' => 'file:'.str_repeat('a', 200000), 'b' => 'file:b'], $this->extract($method, $data));
    }

    /**
     * libzstd decodes frames declaring a small content size in a single pass, without applying the window limit.
     *
     * @dataProvider provideMethods
     */
    public function testSmallFrameDeclaringHugeWindowIsAccepted(string $method): void
    {
        $tar = (new TarBuilder())->file('a', 'x')->build();
        self::assertSame(['a' => 'file:x'], $this->extract($method, TarBuilder::zstdFrame($tar, 30)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideMethodsAllModes(): iterable
    {
        foreach (self::availableMethods() as $method) {
            yield $method => [$method, false];
            if ($method === Extractor::METHOD_BUNDLED || $method === Extractor::METHOD_ZSTD_CLI) {
                yield $method.' [async]' => [$method, true];
            }
        }
    }

    /**
     * @dataProvider provideMethodsAllModes
     */
    public function testMaxSize(string $method, bool $async): void
    {
        $tar = (new TarBuilder())->file('a', str_repeat('x', 3000))->build();
        $size = \strlen($tar);
        $archives = [
            'declared size' => [TarBuilder::zstdFrame($tar), $size],
            'undeclared size' => [TarBuilder::zeroBomb(5 << 20), 5 << 20],
            'several frames' => [TarBuilder::zstdFrame(substr($tar, 0, 1000)).TarBuilder::zeroBomb(1 << 20).TarBuilder::zstdFrame(substr($tar, 1000)), $size + (1 << 20)],
        ];
        foreach ($archives as $name => [$data, $total]) {
            $archive = $this->writeArchive($data);
            foreach ([$total => true, $total - 1 => false] as $maxSize => $accepted) {
                $dest = $this->tmp.'/dest';
                $extractor = $this->extractor($method, $maxSize);
                try {
                    $extraction = $extractor->prepare($archive, $dest);
                    $async ? $this->runLikeProcessRunner($extraction) : $extraction->run();
                    self::assertTrue($accepted, $name.' over the limit is rejected');
                    Filesystem::remove($dest);
                } catch (ExtractionException $e) {
                    self::assertFalse($accepted, $name.' at the limit is accepted: '.$e->getMessage());
                    self::assertSame('Decompressed size exceeds the limit of '.$maxSize.' bytes', $e->getMessage());
                    self::assertFalse(file_exists($dest), 'destination is removed on failure');
                }
                self::assertSame([], $extractor->getUnavailableReasons());
            }
        }
    }

    public function testMaxSizeDefaultsTo1024MB(): void
    {
        self::assertSame(1024 * 1024 * 1024, Extractor::DEFAULT_MAX_SIZE);
        $this->expectException(\InvalidArgumentException::class);
        new Extractor(null, null, null, null, 0);
    }

    /**
     * @dataProvider provideMethods
     */
    public function testDestinationMustBeEmpty(string $method): void
    {
        mkdir($this->tmp.'/dest');
        file_put_contents($this->tmp.'/dest/keep', 'x');
        try {
            $this->extractor($method)->extract($this->writeArchive((new TarBuilder())->file('a')->zst()), $this->tmp.'/dest');
            self::fail('Expected an exception');
        } catch (ExtractionException $e) {
            self::assertStringContainsString('must be empty', $e->getMessage());
        }
        self::assertSame(['keep' => 'file:x'], self::tree($this->tmp.'/dest'));
    }

    /**
     * @dataProvider provideMethods
     */
    public function testExistingEmptyDestinationIsEmptiedOnFailure(string $method): void
    {
        mkdir($this->tmp.'/dest');
        try {
            $this->extractor($method)->extract($this->writeArchive((new TarBuilder())->file('a', 'x')->file('../b')->zst()), $this->tmp.'/dest');
            self::fail('Expected an exception');
        } catch (ExtractionException $e) {
        }
        self::assertSame([], self::tree($this->tmp.'/dest'));
    }

    /**
     * Real zstd compressed fixtures produce the same tree with every method.
     */
    public function testAllMethodsProduceIdenticalTrees(): void
    {
        self::requireLongPaths();
        $fixtures = self::glob(__DIR__.'/Fixtures/*.tar.zst');
        self::assertNotEmpty($fixtures);
        $reference = null;
        foreach ($fixtures as $fixture) {
            foreach (self::availableMethods() as $method) {
                $dest = $this->tmp.'/'.basename($fixture).'-'.$method;
                $extractor = $this->extractor($method);
                $extractor->extract($fixture, $dest);
                self::assertSame($method, $extractor->getLastMethod());
                $tree = self::tree($dest);
                if ($reference === null) {
                    $reference = $tree;
                    self::assertSame(self::link('sub/run.sh'), $tree['link-to-script']);
                    self::assertSame(self::link('../mem.h'), $tree['sub/up-link']);
                    self::assertSame(file_get_contents(\dirname(__DIR__).'/native/zstd/common/mem.h'), file_get_contents($dest.'/mem.h'));
                }
                self::assertSame($reference, $tree, basename($fixture).' via '.$method);
            }
        }
    }

    public function testDefaultMethodOrderFallsBackToPhp(): void
    {
        $extractor = new Extractor($this->tmp.'/cache', [Extractor::METHOD_BUNDLED, Extractor::METHOD_ZSTD_CLI, Extractor::METHOD_PHP], $this->tmp.'/missing', $this->tmp.'/missing-zstd');
        $extractor->extract($this->writeArchive((new TarBuilder())->file('a', 'x')->zst()), $this->tmp.'/dest');
        self::assertSame(Extractor::METHOD_PHP, $extractor->getLastMethod());
        self::assertSame([Extractor::METHOD_BUNDLED, Extractor::METHOD_ZSTD_CLI], array_keys($extractor->getUnavailableReasons()));
    }

    public function testNoMethodAvailable(): void
    {
        $extractor = new Extractor(null, [Extractor::METHOD_BUNDLED], $this->tmp.'/missing');
        $this->expectException(ExtractionException::class);
        $this->expectExceptionMessage('No extraction method is available');
        $extractor->extract($this->writeArchive((new TarBuilder())->file('a', 'x')->zst()), $this->tmp.'/dest');
    }
}
