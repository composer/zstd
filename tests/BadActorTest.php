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
use Composer\Zstd\ZstdDecoder;

/**
 * Malicious and broken archives. Every case runs through every available extraction method, and each must
 * reject the archive, leave nothing behind and never write outside of the destination.
 */
class BadActorTest extends TestCase
{
    /**
     * @return array<string, array{TarBuilder|string, string}> archive (a tar to compress, or raw bytes) => expected message pattern
     */
    private static function cases(): array
    {
        $cases = [
            // paths
            'absolute path' => [(new TarBuilder())->file('/etc/evil', 'x'), '{absolute path}'],
            'parent reference' => [(new TarBuilder())->file('../outside/evil', 'x'), '{parent directory reference}'],
            'parent reference deep' => [(new TarBuilder())->file('a/b/../../../outside/evil', 'x'), '{parent directory reference}'],
            'parent reference staying inside' => [(new TarBuilder())->file('a/../b', 'x'), '{parent directory reference}'],
            'parent reference in dir entry' => [(new TarBuilder())->dir('../outside/x'), '{parent directory reference}'],
            'parent reference via gnu long name' => [(new TarBuilder())->gnuLong(str_repeat('a/', 60).str_repeat('../', 70).'outside/evil', '0', 'x'), '{parent directory reference}'],
            'parent reference via pax path' => [(new TarBuilder())->pax(['path' => '../outside/evil'], 'harmless', '0', 'x'), '{parent directory reference}'],
            'parent reference via ustar prefix' => [(new TarBuilder())->entry('evil', '0', 'x', 0644, '', '../outside'), '{parent directory reference}'],
            'backslash' => [(new TarBuilder())->file('..\\outside\\evil', 'x'), '{forbidden character}'],
            'control character' => [(new TarBuilder())->pax(['path' => "a\nb"], 'x'), '{forbidden character}'],
            'nul in pax path' => [(new TarBuilder())->pax(['path' => "a\0b"], 'x'), '{NUL byte}'],
            'empty name' => [(new TarBuilder())->file('', 'x'), '{empty path}'],
            'only dots' => [(new TarBuilder())->file('./.', 'x'), '{empty path}'],
            // error messages must stay short (a full stderr pipe would hang the binary) and free of control characters
            'huge unsafe name' => [(new TarBuilder())->gnuLong('../'.str_repeat('A', 200000), '0', 'x'), '{^Unsafe entry "\.\./A{197}\.\.\.": parent directory reference$}'],
            'escape sequence in name' => [(new TarBuilder())->pax(['path' => "../\e[2J"], 'x'), '{^Unsafe entry "\.\./\?\[2J"}'],
            'escape sequence in symlink target' => [(new TarBuilder())->pax(['linkpath' => "/\e[2J"], 'l', '2'), '{^Unsafe symlink "l" -> "/\?\[2J"$}'],
            'unprintable entry type' => [(new TarBuilder())->entry('a', "\e"), '{^Unsupported entry type \'\?\' for "a"$}'],

            // symlinks
            'absolute symlink' => [(new TarBuilder())->symlink('evil', '/etc/passwd'), '{unsafe symlink}i'],
            'backslash symlink' => [(new TarBuilder())->symlink('evil', '..\\outside'), '{unsafe symlink}i'],
            'empty symlink target' => [(new TarBuilder())->symlink('evil', ''), '{unsafe symlink}i'],
            'file through symlink' => [(new TarBuilder())->symlink('x', '../outside')->file('x/evil', 'x'), '{conflicts|not a directory}'],
            'symlink over file' => [(new TarBuilder())->file('f', 'x')->symlink('f', 'g'), '{conflicts}'],
            'symlink over directory' => [(new TarBuilder())->dir('d')->symlink('d', '../outside'), '{conflicts}'],
            'symlink under symlink' => [(new TarBuilder())->dir('sub')->symlink('a', 'sub')->symlink('a/b', 'c'), '{not a directory}'],
            'symlink under escaping symlink' => [(new TarBuilder())->symlink('a', '../outside')->symlink('a/b', 'c'), '{not a directory|resolves outside}'],

            // entry types and headers
            'hardlink' => [(new TarBuilder())->file('a', 'x')->entry('b', '1', '', 0644, 'a'), '{unsupported entry type \'1\'}i'],
            'char device' => [(new TarBuilder())->entry('dev', '3'), '{unsupported entry type \'3\'}i'],
            'block device' => [(new TarBuilder())->entry('dev', '4'), '{unsupported entry type \'4\'}i'],
            'fifo' => [(new TarBuilder())->entry('fifo', '6'), '{unsupported entry type \'6\'}i'],
            'sparse file' => [(new TarBuilder())->entry('sparse', 'S'), '{unsupported entry type \'S\'}i'],
            'bad checksum' => [(new TarBuilder())->entry('a', '0', 'x', 0644, '', '', true), '{checksum}'],
            'pax size override' => [(new TarBuilder())->pax(['size' => '1'], 'x'), '{size override}'],
            'pax record length overflow' => [(new TarBuilder())->entry('PaxHeader/x', 'x', "18446744073709551645 path=ok\n")->file('x'), '{malformed pax}i'],
            'malformed pax record' => [(new TarBuilder())->entry('PaxHeader/x', 'x', "99 path=a\n")->file('x'), '{malformed pax}i'],
            'huge metadata entry' => [(new TarBuilder())->entry('././@LongLink', 'L', str_repeat('a', (1 << 20) + 1))->file('x'), '{metadata entry too large}i'],
        ];
        if (!Filesystem::isWindows()) {
            $cases += [
                'symlink escaping' => [(new TarBuilder())->symlink('evil', '../outside'), '{resolves outside}'],
                'symlink escaping deep' => [(new TarBuilder())->symlink('a/b/evil', '../../../outside/x'), '{resolves outside}'],
                'symlink escaping through dir link' => [(new TarBuilder())->symlink('deep/deep/root', '../..')->symlink('evil', 'deep/deep/root/../../../outside'), '{resolves outside}'],
                'symlink escaping through later link' => [(new TarBuilder())->symlink('evil', 'c/../outside')->symlink('c', '.'), '{resolves outside}'],
                'symlink escaping through chain' => [(new TarBuilder())->symlink('a', 'b')->symlink('b', 'c/..')->symlink('c', '../outside'), '{resolves outside}'],
                'symlink loop' => [(new TarBuilder())->symlink('a', 'b')->symlink('b', 'a'), '{resolves outside}'],
                'symlink self loop' => [(new TarBuilder())->symlink('a', 'a'), '{resolves outside}'],
                'symlinks too expensive to verify' => [self::symlinkMaze(), '{^Symlinks take too many steps to verify$}'],
            ];
        }

        // tar structure
        $valid = (new TarBuilder())->file('a', str_repeat('x', 3000));
        $cases += [
            'truncated tar' => [TarBuilder::zstdFrame(substr($valid->build(), 0, 1500)), '{truncated}i'],
            'missing tar end blocks' => [TarBuilder::zstdFrame($valid->build(false)), '{truncated}i'],
            'empty tar' => [TarBuilder::zstdFrame(''), '{truncated}i'],
        ];

        // zstd stream
        $zst = $valid->zst();
        $cases += [
            'empty file' => ['', '{truncated|unexpected end}i'],
            'not zstd' => [$valid->build(), '{magic|unknown frame|unsupported format}i'],
            'truncated zstd' => [substr($zst, 0, -10), '{truncated|unexpected end|premature end}i'],
            'truncated zstd header' => [substr($zst, 0, 7), '{truncated|unexpected end|premature end}i'],
            'trailing garbage' => [$zst.'garbage', '{magic|unknown frame|unsupported format}i'],
            'trailing zeros' => [$zst."\0\0\0\0", '{magic|unknown frame|unsupported format}i'],
            'truncated skippable frame' => [$zst.pack('V', 0x184D2A50).pack('V', 100).'short', '{truncated|unexpected end|premature end}i'],
            'skippable frame only' => [pack('V', 0x184D2A50).pack('V', 0), '{truncated}i'],
            'window too large' => [TarBuilder::zstdFrame(str_pad($valid->build(), 200000, "\0"), 28), '{too much memory|window|memory limit}i'],
            'dictionary required' => [TarBuilder::zstdFrame($valid->build(), null, false, 1234), '{dictionary}i'],
            'reserved header bit' => [substr_replace($zst, \chr(\ord($zst[4]) | 8), 4, 1), '{reserved|unsupported|frame parameter|corrupt}i'],
            'reserved block type' => [substr_replace($zst, \chr(\ord($zst[13]) | 6), 13, 1), '{reserved|corrupt}i'],
            'content size over 2^63' => [substr_replace(TarBuilder::zstdFrame($valid->build(), 20), pack('P', -5), 6, 8), '{size|corrupt}i'],
            'content size mismatch' => [substr_replace($zst, pack('P', 9999), 5, 8), '{size|corrupt}i'],
        ];
        if (ZstdDecoder::canVerifyChecksums()) {
            $sum = TarBuilder::zstdFrame($valid->build(), null, true);
            $cases['bad content checksum'] = [substr($sum, 0, -1).\chr(\ord(substr($sum, -1)) ^ 1), '{checksum}i'];
            // libzstd may decode corrupted data to garbage without noticing, only the checksum reliably catches it
            $fixture = (string) file_get_contents(__DIR__.'/Fixtures/level1.tar.zst');
            $cases['corrupted compressed block'] = [substr_replace($fixture, \chr(\ord($fixture[5000]) ^ 0x10), 5000, 1), '{checksum|corrupt}i'];
        }

        return $cases;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideBadArchives(): iterable
    {
        foreach (self::cases() as $name => [$archive, $pattern]) {
            $data = $archive instanceof TarBuilder ? $archive->zst() : $archive;
            foreach (self::availableMethods() as $method) {
                yield $name.' ('.$method.')' => [$method, $data, $pattern];
            }
        }
    }

    /**
     * Symlinks with 401 component targets (macOS caps targets at 1023 bytes), enough to walk over a million components.
     */
    private static function symlinkMaze(): TarBuilder
    {
        $tar = (new TarBuilder())->dir('d');
        $target = str_repeat('d/../', 200).'d';
        for ($i = 0; $i < 2600; $i++) {
            $tar->gnuLong('l'.$i, '2', '', $target);
        }

        return $tar;
    }

    /**
     * The same cases through prepare()/complete(), as run by an asynchronous process runner.
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function provideBadArchivesAllModes(): iterable
    {
        foreach (self::provideBadArchives() as $name => [$method, $data, $pattern]) {
            yield $name => [$method, $data, $pattern, false];
            if ($method === Extractor::METHOD_BUNDLED || $method === Extractor::METHOD_ZSTD_CLI) {
                yield $name.' [async]' => [$method, $data, $pattern, true];
            }
        }
    }

    /**
     * @dataProvider provideBadArchivesAllModes
     */
    public function testBadArchiveIsRejected(string $method, string $archive, string $messagePattern, bool $async): void
    {
        mkdir($this->tmp.'/outside');
        $dest = $this->tmp.'/dest';
        $extractor = $this->extractor($method);
        try {
            if ($async) {
                $this->runLikeProcessRunner($extractor->prepare($this->writeArchive($archive), $dest));
            } else {
                $extractor->extract($this->writeArchive($archive), $dest);
            }
            self::fail('Archive should have been rejected');
        } catch (ExtractionException $e) {
            self::assertSame(1, preg_match($messagePattern, $e->getMessage()), sprintf('"%s" matches %s', $e->getMessage(), $messagePattern));
        }
        self::assertSame([], $extractor->getUnavailableReasons(), 'the method itself rejected the archive');
        self::assertFalse(file_exists($dest) || is_link($dest), 'destination is removed on failure');
        self::assertSame([], self::tree($this->tmp.'/outside'), 'nothing was written outside');
    }
}
