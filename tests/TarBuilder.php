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

/**
 * Builds (possibly malicious) tar archives for tests, and wraps them in uncompressed zstd frames.
 */
final class TarBuilder
{
    /** @var string */
    private $data = '';

    public function file(string $name, string $content = '', int $mode = 0644): self
    {
        return $this->entry($name, '0', $content, $mode);
    }

    public function dir(string $name): self
    {
        return $this->entry($name, '5', '', 0755);
    }

    public function symlink(string $name, string $target): self
    {
        return $this->entry($name, '2', '', 0777, $target);
    }

    /**
     * GNU long name (and long link) entries.
     */
    public function gnuLong(string $name, string $type = '0', string $content = '', string $linkname = ''): self
    {
        $this->entry('././@LongLink', 'L', $name."\0");
        if (\strlen($linkname) >= 100) {
            $this->entry('././@LongLink', 'K', $linkname."\0");
        }

        return $this->entry(substr($name, 0, 99), $type, $content, 0644, substr($linkname, 0, 99));
    }

    /**
     * @param array<string, string> $records
     */
    public function pax(array $records, string $name, string $type = '0', string $content = '', string $linkname = ''): self
    {
        $data = '';
        foreach ($records as $key => $value) {
            $record = ' '.$key.'='.$value."\n";
            // the length prefix counts its own digits
            $len = \strlen($record) + 1;
            while ($len !== \strlen($record) + \strlen((string) $len)) {
                $len++;
            }
            $data .= $len.$record;
        }
        $this->entry('PaxHeader/x', 'x', $data);

        return $this->entry($name, $type, $content, 0644, $linkname);
    }

    public function entry(string $name, string $type, string $content = '', int $mode = 0644, string $linkname = '', string $prefix = '', bool $badChecksum = false): self
    {
        $h = str_pad($name, 100, "\0")
            .sprintf("%07o\0", $mode)
            ."0000000\0"
            ."0000000\0"
            .sprintf("%011o\0", \strlen($content))
            ."00000000000\0"
            .'        '
            .$type
            .str_pad($linkname, 100, "\0")
            ."ustar\0"
            .'00'
            .str_repeat("\0", 32 + 32 + 8 + 8)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);
        \assert(\strlen($h) === 512);
        $sum = array_sum(array_map('ord', str_split($h))) + ($badChecksum ? 1 : 0);
        $h = substr_replace($h, sprintf("%06o\0 ", $sum), 148, 8);
        $this->data .= $h.$content.str_repeat("\0", (512 - \strlen($content) % 512) % 512);

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->data .= $bytes;

        return $this;
    }

    public function build(bool $end = true): string
    {
        return $this->data.($end ? str_repeat("\0", 1024) : '');
    }

    public function zst(bool $end = true): string
    {
        return self::zstdFrame($this->build($end));
    }

    /**
     * A zstd frame made of raw (uncompressed) blocks.
     *
     * @param int|null $windowLog  use a window descriptor instead of a single segment frame
     * @param bool     $checksum   append the XXH64 content checksum (requires PHP 8.1+)
     * @param int      $dictId     dictionary id to declare in the header
     */
    public static function zstdFrame(string $data, ?int $windowLog = null, bool $checksum = false, int $dictId = 0): string
    {
        $fhd = 0xC0 | ($checksum ? 4 : 0) | ($dictId !== 0 ? 3 : 0);
        $header = '';
        if ($windowLog === null) {
            $fhd |= 0x20;
        } else {
            $header .= \chr(($windowLog - 10) << 3);
        }
        if ($dictId !== 0) {
            $header .= pack('V', $dictId);
        }
        $out = pack('V', 0xFD2FB528).\chr($fhd).$header.pack('P', \strlen($data));
        $chunks = $data === '' ? [''] : str_split($data, 1 << 17);
        foreach ($chunks as $i => $chunk) {
            $last = $i === \count($chunks) - 1 ? 1 : 0;
            $bh = $last | (\strlen($chunk) << 3);
            $out .= \chr($bh & 0xFF).\chr(($bh >> 8) & 0xFF).\chr($bh >> 16).$chunk;
        }
        if ($checksum) {
            $out .= strrev(substr(hash('xxh64', $data, true), 4));
        }

        return $out;
    }

    /**
     * A frame whose compressed block holds $count maximum length matches, each 131074 bytes.
     */
    public static function matchBomb(int $count): string
    {
        $out = pack('V', 0xFD2FB528)."\0".\chr((17 - 10) << 3);
        // a raw block giving the matches something to copy
        $out .= \chr(4 << 3)."\0\0".'xxxx';
        // no literals; RLE tables: literal length 0, repeat offset, match length code 52 with all 16 extra bits set
        $block = "\0".\chr(0x80 | ($count >> 8)).\chr($count & 0xFF)."\x54\0\0\x34".str_repeat("\xFF", $count * 2)."\x01";
        $bh = 1 | (2 << 1) | (\strlen($block) << 3);

        return $out.\chr($bh & 0xFF).\chr(($bh >> 8) & 0xFF).\chr($bh >> 16).$block;
    }

    /**
     * A tiny frame of RLE blocks decompressing to $size zero bytes (an empty tar), without declaring its size.
     */
    public static function zeroBomb(int $size): string
    {
        $out = pack('V', 0xFD2FB528)."\0".\chr((17 - 10) << 3);
        for ($left = $size; $left > 0; $left -= $block) {
            $block = min($left, 1 << 17);
            $bh = ($left === $block ? 1 : 0) | (1 << 1) | ($block << 3);
            $out .= \chr($bh & 0xFF).\chr(($bh >> 8) & 0xFF).\chr($bh >> 16)."\0";
        }

        return $out;
    }
}
