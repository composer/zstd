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
use Composer\Zstd\Filesystem;
use Composer\Zstd\TarExtractor;

/**
 * Windows rules, testable on any platform through the PHP implementation.
 */
class TarExtractorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideWindowsUnsafeNames(): array
    {
        return [
            'device name' => ['aux'],
            'device name with extension' => ['dir/CON.txt'],
            'numbered device' => ['com1.php'],
            'console device' => ['conin$'],
            'superscript numbered device' => ["LPT\u{b9}.txt"],
            'drive relative' => ['c:evil'],
            'alternate data stream' => ['file.txt:stream'],
            'trailing dot' => ['dir./file'],
            'trailing space' => ['file '],
            'wildcard' => ['a*b'],
        ];
    }

    /**
     * @dataProvider provideWindowsUnsafeNames
     */
    public function testWindowsRules(string $name): void
    {
        mkdir($this->tmp.'/dest');
        $this->expectException(ExtractionException::class);
        $this->expectExceptionMessage('Windows');
        (new TarExtractor(true))->extract((new TarBuilder())->file($name, 'x')->build(), $this->tmp.'/dest');
    }

    public function testWindowsStoresSymlinksAsFiles(): void
    {
        mkdir($this->tmp.'/dest');
        (new TarExtractor(true))->extract((new TarBuilder())->file('a', 'x')->symlink('l', 'a')->build(), $this->tmp.'/dest');
        self::assertSame(['a' => 'file:x', 'l' => 'file:a'], self::tree($this->tmp.'/dest'));
    }

    public function testWindowsNamesAreFineElsewhere(): void
    {
        if (Filesystem::isWindows()) {
            self::markTestSkipped('a:b would be an alternate data stream');
        }
        mkdir($this->tmp.'/dest');
        (new TarExtractor(false))->extract((new TarBuilder())->file('aux.php', 'x')->file('a:b', 'y')->build(), $this->tmp.'/dest');
        self::assertSame(['a:b' => 'file:y', 'aux.php' => 'file:x'], self::tree($this->tmp.'/dest'));
    }
}
