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
use Composer\Zstd\ZstdCli;
use Composer\Zstd\ZstdDecoder;

/**
 * Differential tests of the pure PHP decoder against the reference zstd CLI: for the same input, both must
 * either produce identical output or both reject it.
 */
class ConformanceTest extends TestCase
{
    /** @var ZstdCli|null */
    private $cli;

    protected function setUp(): void
    {
        parent::setUp();
        $cli = new ZstdCli(self::zstdCli());
        if (!$cli->isAvailable()) {
            self::markTestSkipped('No zstd CLI available: '.$cli->getUnavailableReason());
        }
        $this->cli = $cli;
    }

    /**
     * @return array{bool, string|null} success, output
     */
    private function viaCli(string $data): array
    {
        \assert($this->cli !== null);
        $file = $this->writeArchive($data);
        try {
            return [true, $this->cli->decompress($file, \PHP_INT_MAX)];
        } catch (ExtractionException $e) {
            return [false, null];
        } finally {
            unlink($file);
        }
    }

    /**
     * @return array{bool, string|null}
     */
    private static function viaPhp(string $data): array
    {
        try {
            return [true, ZstdDecoder::decompress($data)];
        } catch (ExtractionException $e) {
            return [false, null];
        }
    }

    public function testFixtures(): void
    {
        foreach (self::glob(__DIR__.'/Fixtures/*.tar.zst') as $fixture) {
            $data = (string) file_get_contents($fixture);
            [$ok, $out] = $this->viaCli($data);
            self::assertTrue($ok, basename($fixture));
            self::assertSame(hash('sha256', (string) $out), hash('sha256', (string) self::viaPhp($data)[1]), basename($fixture));
        }
    }

    /**
     * Corruptions of checksummed streams. Without checksums, corrupted input may decode to garbage, and which
     * garbage depends on libzstd's internal decoding paths, so only checksummed input can be compared exactly.
     */
    public function testMutatedInputs(): void
    {
        if (!ZstdDecoder::canVerifyChecksums()) {
            self::markTestSkipped('Checksums can only be verified on PHP 8.1+');
        }
        $sources = [];
        foreach (self::glob(__DIR__.'/Fixtures/*.tar.zst') as $fixture) {
            if (strpos($fixture, 'level19') === false) {
                $sources[] = (string) file_get_contents($fixture);
            }
        }
        mt_srand(1234);
        for ($i = 0; $i < 150; $i++) {
            $data = $sources[$i % \count($sources)];
            $kind = $i % 4;
            if ($kind === 0) {
                $pos = mt_rand(0, \strlen($data) - 1);
                $data[$pos] = \chr(mt_rand(0, 255));
            } elseif ($kind === 1) {
                $pos = mt_rand(0, 300);
                $data[$pos] = \chr(\ord($data[$pos]) ^ (1 << mt_rand(0, 7)));
            } elseif ($kind === 2) {
                $data = substr($data, 0, mt_rand(0, \strlen($data) - 1));
            } else {
                $data .= \chr(mt_rand(0, 255));
            }

            [$cliOk, $cliOut] = $this->viaCli($data);
            [$phpOk, $phpOut] = self::viaPhp($data);
            self::assertSame($cliOk, $phpOk, 'mutation #'.$i.' accepted by '.($cliOk ? 'zstd' : 'PHP').' only');
            self::assertSame($cliOut, $phpOut, 'mutation #'.$i.' output');
        }
    }
}
