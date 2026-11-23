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
 * prepare()/getCommand()/complete(), as used to run extractions through an external (async) process runner.
 */
class ExtractionTest extends TestCase
{
    /**
     * @dataProvider provideMethods
     */
    public function testCommandsAndResults(string $method): void
    {
        self::requireLongPaths();
        $fixtures = self::glob(__DIR__.'/Fixtures/*.tar.zst');
        $reference = null;
        foreach ($fixtures as $fixture) {
            $extraction = $this->extractor($method)->prepare($fixture, $this->tmp.'/'.basename($fixture));
            self::assertSame($method, $extraction->getMethod());
            $hasCommand = $method === Extractor::METHOD_BUNDLED || $method === Extractor::METHOD_ZSTD_CLI;
            self::assertSame($hasCommand, $extraction->getCommand() !== null);

            $this->runLikeProcessRunner($extraction);
            $tree = self::tree($this->tmp.'/'.basename($fixture));
            $reference = $reference ?? $tree;
            self::assertSame($reference, $tree);
            self::assertSame(self::link('sub/run.sh'), $tree['link-to-script']);
        }
    }

    public function testParallelExtraction(): void
    {
        self::requireLongPaths();
        $method = \in_array(Extractor::METHOD_BUNDLED, self::availableMethods(), true) ? Extractor::METHOD_BUNDLED : null;
        if ($method === null) {
            self::markTestSkipped('No bundled binary available');
        }
        $extractor = $this->extractor($method);
        $running = [];
        foreach (self::glob(__DIR__.'/Fixtures/*.tar.zst') as $i => $fixture) {
            $extraction = $extractor->prepare($fixture, $this->tmp.'/out'.$i);
            $command = $extraction->getCommand();
            self::assertNotNull($command);
            $process = self::startProcess($command, [1 => ['file', Filesystem::isWindows() ? 'NUL' : '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
            $running[] = [$extraction, $process, $pipes[2]];
        }
        foreach ($running as [$extraction, $process, $stderr]) {
            $err = (string) stream_get_contents($stderr);
            fclose($stderr);
            $extraction->complete(proc_close($process), '', $err);
        }
        $trees = [];
        foreach (array_keys($running) as $i) {
            $trees[] = self::tree($this->tmp.'/out'.$i);
        }
        self::assertCount(1, array_unique(array_map('serialize', $trees)));
    }

    /**
     * When the command could not run at all, complete() falls back to the next method synchronously.
     */
    public function testCompleteFallsBackWhenTheBinaryCouldNotRun(): void
    {
        $binary = self::bundledBinary();
        if ($binary === null) {
            self::markTestSkipped('No bundled binary available');
        }
        $extractor = new Extractor($this->tmp.'/cache', [Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], $binary);
        $extraction = $extractor->prepare($this->writeArchive((new TarBuilder())->file('a', 'x')->zst()), $this->tmp.'/dest');
        $extraction->complete(127, '', 'exec format error');
        self::assertSame(Extractor::METHOD_PHP, $extractor->getLastMethod());
        self::assertArrayHasKey(Extractor::METHOD_BUNDLED, $extractor->getUnavailableReasons());
        self::assertSame(['a' => 'file:x'], self::tree($this->tmp.'/dest'));
    }

    /**
     * A killed binary (e.g. by the runner's timeout) fails the extraction, the binary stays in use for the next ones.
     */
    public function testCompleteFailsWhenTheBinaryWasKilled(): void
    {
        $binary = self::bundledBinary();
        if ($binary === null) {
            self::markTestSkipped('No bundled binary available');
        }
        $extractor = new Extractor($this->tmp.'/cache', [Extractor::METHOD_BUNDLED, Extractor::METHOD_PHP], $binary);
        $extraction = $extractor->prepare($this->writeArchive((new TarBuilder())->file('a', 'x')->zst()), $this->tmp.'/dest');
        try {
            $extraction->complete(143, '', '');
            self::fail('Expected an exception');
        } catch (ExtractionException $e) {
            self::assertSame('zstd-extract exited with code 143', $e->getMessage());
        }
        self::assertArrayNotHasKey(Extractor::METHOD_BUNDLED, $extractor->getUnavailableReasons());
    }

    /**
     * @dataProvider provideMethods
     */
    public function testRejectionCleansUp(string $method): void
    {
        $extraction = $this->extractor($method)->prepare($this->writeArchive((new TarBuilder())->file('a', 'x')->file('../evil')->zst()), $this->tmp.'/dest');
        self::assertTrue(is_dir($this->tmp.'/dest'), 'prepare() creates the destination');
        try {
            $this->runLikeProcessRunner($extraction);
            self::fail('Expected an exception');
        } catch (ExtractionException $e) {
            self::assertStringContainsString('parent directory reference', $e->getMessage());
        }
        self::assertFalse(file_exists($this->tmp.'/dest'));
    }

    public function testRunsOnlyOnce(): void
    {
        $extraction = $this->extractor(Extractor::METHOD_PHP)->prepare($this->writeArchive((new TarBuilder())->file('a', 'x')->zst()), $this->tmp.'/dest');
        $extraction->run();
        $this->expectException(\LogicException::class);
        $extraction->run();
    }

    public function testPrepareValidatesBeforeCreatingAnything(): void
    {
        $extractor = new Extractor(null, [Extractor::METHOD_BUNDLED], $this->tmp.'/missing');
        try {
            $extractor->prepare($this->writeArchive((new TarBuilder())->file('a')->zst()), $this->tmp.'/dest');
            self::fail('Expected an exception');
        } catch (ExtractionException $e) {
            self::assertStringContainsString('No extraction method is available', $e->getMessage());
        }
        self::assertFalse(file_exists($this->tmp.'/dest'));
    }
}
