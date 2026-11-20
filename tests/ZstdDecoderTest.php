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
use Composer\Zstd\ZstdDecoder;

class ZstdDecoderTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideFixtures(): array
    {
        $result = [];
        foreach (self::glob(__DIR__.'/Fixtures/*.tar.zst') as $file) {
            $result[basename($file)] = [$file];
        }

        return $result;
    }

    /**
     * @dataProvider provideFixtures
     */
    public function testFixtures(string $file): void
    {
        $expected = trim((string) file_get_contents(__DIR__.'/Fixtures/source.tar.sha256'));
        self::assertSame($expected, hash('sha256', ZstdDecoder::decompress((string) file_get_contents($file))));
    }

    public function testRawFrames(): void
    {
        self::assertSame('', ZstdDecoder::decompress(TarBuilder::zstdFrame('')));
        $data = random_bytes(300000);
        self::assertSame($data, ZstdDecoder::decompress(TarBuilder::zstdFrame($data)));
    }

    public function testInvalidMagic(): void
    {
        $this->expectException(ExtractionException::class);
        $this->expectExceptionMessage('magic');
        ZstdDecoder::decompress('not a zstd stream');
    }

    /**
     * Corrupted input must fail with an ExtractionException (or decode to garbage), never hang or crash.
     */
    public function testCorruptedInputFailsCleanly(): void
    {
        $src = (string) file_get_contents(__DIR__.'/Fixtures/level19.tar.zst');
        mt_srand(42);
        $failures = 0;
        for ($i = 0; $i < 60; $i++) {
            $corrupt = $src;
            for ($k = 0; $k < 3; $k++) {
                $pos = mt_rand(0, \strlen($src) - 1);
                $corrupt[$pos] = \chr(mt_rand(0, 255));
            }
            if ($i % 3 === 0) {
                $corrupt = substr($corrupt, 0, mt_rand(1, \strlen($corrupt)));
            }
            try {
                ZstdDecoder::decompress($corrupt);
            } catch (ExtractionException $e) {
                $failures++;
            }
        }
        self::assertGreaterThan(30, $failures);
    }
}
