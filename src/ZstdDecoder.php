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
 * Pure PHP Zstandard (RFC 8878) decoder.
 *
 * Hot loops read bitstreams through a 64-bit int container refilled 32 bits at a time, and only juggle plain
 * ints and packed arrays, so they stay cheap for the interpreter and JIT friendly.
 *
 * It follows libzstd: input libzstd rejects is rejected here too (checked by differential tests against the zstd
 * CLI), and it is stricter where libzstd would decode corrupted input to garbage. Content checksums are verified
 * when ext-hash supports XXH64 (PHP 8.1+).
 */
final class ZstdDecoder
{
    /** same default limit as libzstd (ZSTD_d_windowLogMax 27) */
    private const WINDOW_SIZE_MAX = 1 << 27;
    private const BLOCK_SIZE_MAX = 1 << 17;

    private const LL_BASE = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 20, 22, 24, 28, 32, 40, 48, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384, 32768, 65536];
    private const LL_BITS = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
    private const ML_BASE = [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 37, 39, 41, 43, 47, 51, 59, 67, 83, 99, 131, 259, 515, 1027, 2051, 4099, 8195, 16387, 32771, 65539];
    private const ML_BITS = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
    private const LL_DEFAULT = [4, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1, 2, 2, 2, 2, 2, 2, 2, 2, 2, 3, 2, 1, 1, 1, 1, 1, -1, -1, -1, -1];
    private const ML_DEFAULT = [1, 4, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1, -1, -1];
    private const OF_DEFAULT = [1, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1];

    /** @var array<int, string> huffman table: decoded byte per index */
    private $huffChr = [];
    /** @var array<int, int> huffman table: bits consumed per index */
    private $huffNb = [];
    /** @var int */
    private $huffLog = 0;

    /** @var array{array<int, int>, int}|null sequence tables: [packed state entries, accuracy log] */
    private $llT;
    /** @var array{array<int, int>, int}|null */
    private $mlT;
    /** @var array{array<int, int>, int}|null */
    private $ofT;
    /** @var array{array<int, int>, int}|null */
    private static $llDefault;
    /** @var array{array<int, int>, int}|null */
    private static $mlDefault;
    /** @var array{array<int, int>, int}|null */
    private static $ofDefault;

    /** @var bool|null */
    private static $canVerifyChecksums;

    /**
     * Decompresses all frames of a zstd stream, like `zstd -d` would.
     *
     * @param string   $dict    optional raw content dictionary (as used by zstd --patch-from)
     * @param int|null $maxSize rejects input decompressing to more bytes than this
     * @throws ExtractionException on invalid input
     */
    public static function decompress(string $src, string $dict = '', ?int $maxSize = null): string
    {
        if (\PHP_INT_SIZE < 8) {
            throw new ExtractionException('The pure PHP zstd decoder requires a 64-bit PHP build');
        }

        // corrupted input can trigger warnings (e.g. reading past the end), make sure they abort decoding
        set_error_handler(static function (int $type, string $message): bool {
            throw new ExtractionException('Corrupted zstd stream: '.$message);
        });
        try {
            $maxSize = $maxSize ?? \PHP_INT_MAX;
            $frames = self::scanFrames($src);
            self::fitsWithin($frames, $maxSize);
            $result = '';
            foreach ($frames as $frame) {
                $result .= (new self())->decodeFrame($src, $frame, $dict, $maxSize - \strlen($result), $maxSize);
            }

            return $result;
        } catch (ExtractionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExtractionException('Corrupted zstd stream: '.$e->getMessage(), 0, $e);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Validates the framing of a zstd stream the way libzstd does, without decoding block contents:
     * magic numbers, skippable frames, frame headers, window size limit, block sizes, truncation and trailing data.
     *
     * @internal
     * @return list<array{start: int, blocks: int, end: int, window: int, size: int, bound: int, checksum: bool}> data
     *               frames (size is -1 when the frame header does not declare it, bound is the most it can decompress to)
     * @throws ExtractionException
     */
    public static function scanFrames(string $src): array
    {
        if (\PHP_INT_SIZE < 8) {
            throw new ExtractionException('Parsing zstd frames requires a 64-bit PHP build');
        }
        $len = \strlen($src);
        if ($len === 0) {
            throw new ExtractionException('Truncated zstd stream: unexpected end of file');
        }
        $pos = 0;
        $frames = [];
        while ($pos < $len) {
            self::need($src, $pos, 4);
            $magic = unpack('V', $src, $pos)[1];
            if (($magic & 0xFFFFFFF0) === 0x184D2A50) {
                self::need($src, $pos, 8);
                $size = unpack('V', $src, $pos + 4)[1];
                self::need($src, $pos + 8, $size);
                $pos += 8 + $size;
                continue;
            }
            if ($magic !== 0xFD2FB528) {
                throw new ExtractionException('Unknown zstd frame magic number at offset '.$pos);
            }

            $start = $pos;
            self::need($src, $pos, 5);
            $fhd = \ord($src[$pos + 4]);
            $pos += 5;
            if (($fhd & 8) !== 0) {
                throw new ExtractionException('Reserved zstd frame header bit set');
            }
            $singleSegment = ($fhd >> 5) & 1;
            $dictIdSize = [0, 1, 2, 4][$fhd & 3];
            $fcsSize = [$singleSegment, 2, 4, 8][$fhd >> 6];
            self::need($src, $pos, 1 - $singleSegment + $dictIdSize + $fcsSize);
            $window = 0;
            if ($singleSegment === 0) {
                $wd = \ord($src[$pos++]);
                $windowBase = 1 << (10 + ($wd >> 3));
                $window = $windowBase + ($windowBase >> 3) * ($wd & 7);
            }
            $dictId = 0;
            for ($i = 0; $i < $dictIdSize; $i++) {
                $dictId |= \ord($src[$pos + $i]) << (8 * $i);
            }
            if ($dictId !== 0) {
                throw new ExtractionException('zstd frame requires dictionary '.$dictId);
            }
            $pos += $dictIdSize;
            if ($fcsSize === 0) {
                $size = -1;
            } elseif ($fcsSize === 1) {
                $size = \ord($src[$pos]);
            } elseif ($fcsSize === 2) {
                $size = unpack('v', $src, $pos)[1] + 256;
            } elseif ($fcsSize === 4) {
                $size = unpack('V', $src, $pos)[1];
            } else {
                $size = unpack('P', $src, $pos)[1];
            }
            $pos += $fcsSize;
            // 2^64-1 (-1 here) means unknown to libzstd too, other sizes over 2^63 can never match
            if ($size < -1) {
                throw new ExtractionException('zstd frame content size mismatch');
            }
            if ($singleSegment !== 0) {
                $window = $size;
            }
            // like libzstd's streaming decoder: frames declaring a content size that fits its 128KB output buffer
            // are decoded in a single pass, which skips the window size limit
            if (($size < 0 || $size > self::BLOCK_SIZE_MAX) && ($window > self::WINDOW_SIZE_MAX || $window < 0)) {
                throw new ExtractionException('zstd frame requires too much memory for decoding (window larger than 128MB)');
            }

            $blocks = $pos;
            $blockMax = min($window, self::BLOCK_SIZE_MAX);
            $bound = 0;
            do {
                self::need($src, $pos, 3);
                $bh = \ord($src[$pos]) | (\ord($src[$pos + 1]) << 8) | (\ord($src[$pos + 2]) << 16);
                $pos += 3;
                $type = ($bh >> 1) & 3;
                $blockSize = $bh >> 3;
                if ($type === 3) {
                    throw new ExtractionException('Reserved zstd block type');
                }
                if ($blockSize > $blockMax) {
                    throw new ExtractionException('zstd block larger than the maximum block size');
                }
                $stored = $type === 1 ? 1 : $blockSize;
                self::need($src, $pos, $stored);
                $pos += $stored;
                $bound += $type === 2 ? $blockMax : $blockSize;
            } while (($bh & 1) === 0);
            $checksum = ($fhd & 4) !== 0;
            if ($checksum) {
                self::need($src, $pos, 4);
                $pos += 4;
            }
            $frames[] = ['start' => $start, 'blocks' => $blocks, 'end' => $pos, 'window' => $window, 'size' => $size, 'bound' => $size >= 0 ? $size : $bound, 'checksum' => $checksum];
        }

        return $frames;
    }

    /**
     * Rejects frames (from scanFrames()) declaring more content than $maxSize.
     *
     * @internal
     * @param list<array{size: int, bound: int, ...}> $frames
     * @return bool false when frames not declaring their size could still exceed the limit, the decoder must then
     *              enforce it
     * @throws ExtractionException
     */
    public static function fitsWithin(array $frames, int $maxSize): bool
    {
        $declared = $bound = 0;
        foreach ($frames as $frame) {
            $declared += max($frame['size'], 0);
            $bound += $frame['bound'];
        }
        if ($declared > $maxSize) {
            throw self::tooLarge($maxSize);
        }

        return $bound <= $maxSize;
    }

    /**
     * @internal the message must match native/src/main.c
     */
    public static function tooLarge(int $maxSize): ExtractionException
    {
        return new ExtractionException('Decompressed size exceeds the limit of '.$maxSize.' bytes');
    }

    private static function need(string $src, int $pos, int $n): void
    {
        if ($pos + $n > \strlen($src)) {
            throw new ExtractionException('Truncated zstd stream: unexpected end of file');
        }
    }

    /**
     * Content checksums use XXH64, available in ext-hash from PHP 8.1.
     */
    public static function canVerifyChecksums(): bool
    {
        if (self::$canVerifyChecksums === null) {
            self::$canVerifyChecksums = \in_array('xxh64', hash_algos(), true);
        }

        return self::$canVerifyChecksums;
    }

    /**
     * @param array{start: int, blocks: int, end: int, window: int, size: int, bound: int, checksum: bool} $frame validated by scanFrames()
     * @param int $room how much this frame may decompress to before going over $maxSize
     */
    private function decodeFrame(string $src, array $frame, string $dict, int $room, int $maxSize): string
    {
        $pos = $frame['blocks'];
        $blockMax = min($frame['window'], self::BLOCK_SIZE_MAX);
        $maxOffset = $dict === '' ? $frame['window'] : \PHP_INT_MAX;
        $out = $dict;
        $rep = [1, 4, 8];
        do {
            $bh = \ord($src[$pos]) | (\ord($src[$pos + 1]) << 8) | (\ord($src[$pos + 2]) << 16);
            $pos += 3;
            $last = $bh & 1;
            $type = ($bh >> 1) & 3;
            $size = $bh >> 3;
            if ($type === 0) {
                $out .= substr($src, $pos, $size);
                $pos += $size;
            } elseif ($type === 1) {
                $out .= str_repeat($src[$pos], $size);
                $pos++;
            } else {
                $before = \strlen($out);
                $this->decodeBlock(substr($src, $pos, $size), $out, $rep, $maxOffset, $blockMax);
                if (\strlen($out) - $before > $blockMax) {
                    throw new ExtractionException('zstd block decompresses beyond the maximum block size');
                }
                $pos += $size;
            }
            if (\strlen($out) - \strlen($dict) > $room) {
                throw self::tooLarge($maxSize);
            }
        } while ($last === 0);

        $content = $dict === '' ? $out : substr($out, \strlen($dict));
        if ($frame['size'] >= 0 && \strlen($content) !== $frame['size']) {
            throw new ExtractionException('zstd frame content size mismatch');
        }
        if ($frame['checksum'] && self::canVerifyChecksums()
            && strrev(substr(hash('xxh64', $content, true), 4)) !== substr($src, $pos, 4)
        ) {
            throw new ExtractionException('zstd content checksum mismatch');
        }

        return $content;
    }

    /**
     * @param array{int, int, int} $rep
     */
    private function decodeBlock(string $blk, string &$out, array &$rep, int $maxOffset, int $blockMax): void
    {
        $pos = 0;
        $lit = $this->decodeLiterals($blk, $pos, $blockMax);

        // sequences section header
        $b0 = \ord($blk[$pos++]);
        if ($b0 < 128) {
            $nbSeq = $b0;
        } elseif ($b0 < 255) {
            $nbSeq = (($b0 - 128) << 8) + \ord($blk[$pos++]);
        } else {
            $nbSeq = \ord($blk[$pos]) + (\ord($blk[$pos + 1]) << 8) + 0x7F00;
            $pos += 2;
        }
        if ($nbSeq === 0) {
            if ($pos !== \strlen($blk)) {
                throw new ExtractionException('zstd block has trailing bytes');
            }
            $out .= $lit;

            return;
        }
        $modes = \ord($blk[$pos++]);
        if (($modes & 3) !== 0) {
            throw new ExtractionException('Reserved zstd sequence mode bits set');
        }
        $this->llT = $this->seqTable($modes >> 6, $blk, $pos, $this->llT, self::LL_DEFAULT, 6, self::LL_BASE, self::LL_BITS, self::$llDefault);
        $this->ofT = $this->seqTable(($modes >> 4) & 3, $blk, $pos, $this->ofT, self::OF_DEFAULT, 5, null, null, self::$ofDefault);
        $this->mlT = $this->seqTable(($modes >> 2) & 3, $blk, $pos, $this->mlT, self::ML_DEFAULT, 6, self::ML_BASE, self::ML_BITS, self::$mlDefault);

        // packed state entries: nbBits | extraBits << 4 | nextBase << 9 | base << 20
        [$llT, $llLog] = $this->llT;
        [$ofT, $ofLog] = $this->ofT;
        [$mlT, $mlLog] = $this->mlT;

        // backward bitstream read through a container: $avail valid bits sit at the bottom of $c (bits above are junk,
        // every read masks), refilled 32 bits at a time from the end of $d; the zero front padding absorbs over-reads
        $n = \strlen($blk) - $pos;
        $lastByte = \ord($blk[\strlen($blk) - 1]);
        if ($n <= 0 || $lastByte === 0) {
            throw new ExtractionException('Corrupted zstd sequence bitstream');
        }
        $pad = 24 + ((4 - ($n & 3)) & 3);
        $d = str_repeat("\0", $pad).substr($blk, $pos);
        $bp = \strlen($d) - 4;
        $c = unpack('V', $d, $bp)[1];
        $avail = 24 + self::highBit($lastByte);
        $c = ($c << 32) | unpack('V', $d, $bp -= 4)[1];
        $avail += 32;

        $avail -= $llLog;
        $llS = ($c >> $avail) & ((1 << $llLog) - 1);
        $avail -= $ofLog;
        $ofS = ($c >> $avail) & ((1 << $ofLog) - 1);
        $avail -= $mlLog;
        $mlS = ($c >> $avail) & ((1 << $mlLog) - 1);

        [$r1, $r2, $r3] = $rep;
        $litPos = 0;
        $el = $em = $eo = 0;
        for ($i = 1; $i <= $nbSeq; $i++) {
            if ($avail < 32) {
                $c = ($c << 32) | unpack('V', $d, $bp -= 4)[1];
                $avail += 32;
            }
            $eo = $ofT[$ofS];
            $ofCode = $eo >> 20;
            if ($ofCode > 0) {
                $avail -= $ofCode;
                $ofv = (1 << $ofCode) + (($c >> $avail) & ((1 << $ofCode) - 1));
            } else {
                $ofv = 1;
            }
            if ($avail < 32) {
                $c = ($c << 32) | unpack('V', $d, $bp -= 4)[1];
                $avail += 32;
            }
            $em = $mlT[$mlS];
            $ml = $em >> 20;
            if (($nb = ($em >> 4) & 31) > 0) {
                $avail -= $nb;
                $ml += ($c >> $avail) & ((1 << $nb) - 1);
            }
            $el = $llT[$llS];
            $ll = $el >> 20;
            if (($nb = ($el >> 4) & 31) > 0) {
                $avail -= $nb;
                $ll += ($c >> $avail) & ((1 << $nb) - 1);
            }

            if ($ofv > 3) {
                $r3 = $r2;
                $r2 = $r1;
                $r1 = $ofv - 3;
            } else {
                $idx = $ll === 0 ? $ofv : $ofv - 1;
                if ($idx === 1) {
                    $t = $r1;
                    $r1 = $r2;
                    $r2 = $t;
                } elseif ($idx >= 2) {
                    $t = $idx === 2 ? $r3 : $r1 - 1;
                    $r3 = $r2;
                    $r2 = $r1;
                    $r1 = $t;
                }
            }

            if ($avail < 32) {
                $c = ($c << 32) | unpack('V', $d, $bp -= 4)[1];
                $avail += 32;
            }
            $nb = $el & 15;
            $avail -= $nb;
            $llS = (($el >> 9) & 2047) + (($c >> $avail) & ((1 << $nb) - 1));
            $nb = $em & 15;
            $avail -= $nb;
            $mlS = (($em >> 9) & 2047) + (($c >> $avail) & ((1 << $nb) - 1));
            $nb = $eo & 15;
            $avail -= $nb;
            $ofS = (($eo >> 9) & 2047) + (($c >> $avail) & ((1 << $nb) - 1));

            if ($r1 > \strlen($out) + $ll || $r1 <= 0 || $r1 > $maxOffset) {
                throw new ExtractionException('zstd match offset out of range');
            }
            if ($r1 >= $ll + $ml) {
                // match source lies entirely in already written output: one append for literals + match
                $out .= substr($lit, $litPos, $ll).substr($out, $ll - $r1, $ml);
                $litPos += $ll;
            } else {
                if ($ll > 0) {
                    $out .= substr($lit, $litPos, $ll);
                    $litPos += $ll;
                }
                if ($r1 >= $ml) {
                    $out .= substr($out, -$r1, $ml);
                } else {
                    $out .= substr(str_repeat(substr($out, -$r1), intdiv($ml, $r1) + 1), 0, $ml);
                }
            }
        }
        // the last state update is not part of the stream, everything else must be consumed exactly
        if (($bp - $pad) * 8 + $avail + ($el & 15) + ($em & 15) + ($eo & 15) !== 0) {
            throw new ExtractionException('Corrupted zstd sequence bitstream');
        }
        if ($litPos > \strlen($lit)) {
            throw new ExtractionException('zstd sequences use more literals than available');
        }
        $rep = [$r1, $r2, $r3];
        if ($litPos < \strlen($lit)) {
            $out .= substr($lit, $litPos);
        }
    }

    private function decodeLiterals(string $blk, int &$pos, int $blockMax): string
    {
        $h0 = \ord($blk[0]);
        $type = $h0 & 3;
        $sf = ($h0 >> 2) & 3;
        if ($type < 2) {
            if (($sf & 1) === 0) {
                $size = $h0 >> 3;
                $pos = 1;
            } elseif ($sf === 1) {
                $size = ($h0 >> 4) + (\ord($blk[1]) << 4);
                $pos = 2;
            } else {
                $size = ($h0 >> 4) + (\ord($blk[1]) << 4) + (\ord($blk[2]) << 12);
                $pos = 3;
            }
            if ($size > $blockMax) {
                throw new ExtractionException('zstd literals larger than the maximum block size');
            }
            if ($type === 0) {
                if ($pos + $size > \strlen($blk)) {
                    throw new ExtractionException('Truncated zstd literals');
                }
                $lit = substr($blk, $pos, $size);
                $pos += $size;

                return $lit;
            }
            $lit = str_repeat($blk[$pos], $size);
            $pos++;

            return $lit;
        }

        if ($sf <= 1) {
            $h = $h0 | (\ord($blk[1]) << 8) | (\ord($blk[2]) << 16);
            $regen = ($h >> 4) & 0x3FF;
            $comp = ($h >> 14) & 0x3FF;
            $pos = 3;
        } elseif ($sf === 2) {
            $h = $h0 | (\ord($blk[1]) << 8) | (\ord($blk[2]) << 16) | (\ord($blk[3]) << 24);
            $regen = ($h >> 4) & 0x3FFF;
            $comp = ($h >> 18) & 0x3FFF;
            $pos = 4;
        } else {
            $h = $h0 | (\ord($blk[1]) << 8) | (\ord($blk[2]) << 16) | (\ord($blk[3]) << 24) | (\ord($blk[4]) << 32);
            $regen = ($h >> 4) & 0x3FFFF;
            $comp = ($h >> 22) & 0x3FFFF;
            $pos = 5;
        }
        $end = $pos + $comp;
        if ($end > \strlen($blk) || $regen > $blockMax) {
            throw new ExtractionException('Corrupted zstd literals header');
        }
        if ($sf !== 0 && $regen < 6) {
            throw new ExtractionException('Corrupted zstd literals header');
        }
        if ($type === 2) {
            $this->readHuffmanTable($blk, $pos);
            if ($pos > $end) {
                throw new ExtractionException('Corrupted zstd huffman table');
            }
        } elseif ($this->huffLog === 0) {
            throw new ExtractionException('zstd treeless literals without a previous huffman table');
        }
        if ($sf === 0) {
            $lit = $this->huffStream(substr($blk, $pos, $end - $pos), $regen);
        } else {
            if ($end - $pos < 10) {
                throw new ExtractionException('Corrupted zstd literals jump table');
            }
            $s1 = \ord($blk[$pos]) | (\ord($blk[$pos + 1]) << 8);
            $s2 = \ord($blk[$pos + 2]) | (\ord($blk[$pos + 3]) << 8);
            $s3 = \ord($blk[$pos + 4]) | (\ord($blk[$pos + 5]) << 8);
            $pos += 6;
            if ($pos + $s1 + $s2 + $s3 >= $end) {
                throw new ExtractionException('Corrupted zstd literals jump table');
            }
            $seg = intdiv($regen + 3, 4);
            $lit = $this->huffStream(substr($blk, $pos, $s1), $seg)
                .$this->huffStream(substr($blk, $pos + $s1, $s2), $seg)
                .$this->huffStream(substr($blk, $pos + $s1 + $s2, $s3), $seg)
                .$this->huffStream(substr($blk, $pos + $s1 + $s2 + $s3, $end - $pos - $s1 - $s2 - $s3), $regen - 3 * $seg);
        }
        $pos = $end;

        return $lit;
    }

    /**
     * Streams must be consumed exactly. libzstd's fast 4-stream paths skip that check and decode over-reads to
     * garbage that depends on its internals, so this is stricter rather than ever returning different content.
     */
    private function huffStream(string $data, int $count): string
    {
        $n = \strlen($data);
        if ($n === 0 || $data[$n - 1] === "\0") {
            throw new ExtractionException('Corrupted zstd huffman stream');
        }
        $pad = 24 + ((4 - ($n & 3)) & 3);
        $d = str_repeat("\0", $pad).$data;
        $bp = \strlen($d) - 4;
        $c = unpack('V', $d, $bp)[1];
        $avail = 24 + self::highBit(\ord($data[$n - 1]));
        $log = $this->huffLog;
        $mask = (1 << $log) - 1;
        $chr = $this->huffChr;
        $nbs = $this->huffNb;
        $out = '';
        for ($k = 0; $k < $count; $k++) {
            if ($avail < 32) {
                $c = ($c << 32) | unpack('V', $d, $bp -= 4)[1];
                $avail += 32;
            }
            $v = ($c >> ($avail - $log)) & $mask;
            $out .= $chr[$v];
            $avail -= $nbs[$v];
        }
        if (($bp - $pad) * 8 + $avail !== 0) {
            throw new ExtractionException('Corrupted zstd huffman stream');
        }

        return $out;
    }

    private function readHuffmanTable(string $blk, int &$pos): void
    {
        $hb = \ord($blk[$pos++]);
        $weights = [];
        if ($hb >= 128) {
            $num = $hb - 127;
            for ($i = 0; $i < $num; $i += 2) {
                $byte = \ord($blk[$pos + ($i >> 1)]);
                $weights[] = $byte >> 4;
                $weights[] = $byte & 15;
            }
            $weights = \array_slice($weights, 0, $num);
            $pos += ($num + 1) >> 1;
        } else {
            // FSE compressed weights: table description followed by a backward stream, decoded with two interleaved states
            $start = $pos;
            $bitPos = 0;
            [$norm, $log] = self::readNormCounts($blk, $pos, $bitPos, 255, 6);
            $pos = $start + (($bitPos + 7) >> 3);
            [$nbT, $nextT, $symT] = self::buildFse($norm, $log);
            $n = $hb - ($pos - $start);
            $b = unpack('C*', "\0\0\0\0\0\0\0\0".substr($blk, $pos, $n)."\0\0\0\0\0\0\0\0");
            if ($b === false || $n <= 0) {
                throw new ExtractionException('Invalid huffman weights');
            }
            $p = (($n + 7) << 3) + self::highBit($b[$n + 8]);
            $mask = (1 << $log) - 1;
            $p -= $log;
            $s1 = (($b[($p >> 3) + 1] | ($b[($p >> 3) + 2] << 8)) >> ($p & 7)) & $mask;
            $p -= $log;
            $s2 = (($b[($p >> 3) + 1] | ($b[($p >> 3) + 2] << 8)) >> ($p & 7)) & $mask;
            while (true) {
                if (\count($weights) > 254) {
                    throw new ExtractionException('Too many huffman weights');
                }
                $weights[] = $symT[$s1];
                $nb = $nbT[$s1];
                $p -= $nb;
                $s1 = $nextT[$s1] + ((($b[($p >> 3) + 1] | ($b[($p >> 3) + 2] << 8)) >> ($p & 7)) & ((1 << $nb) - 1));
                if ($p < 64) {
                    $weights[] = $symT[$s2];
                    break;
                }
                $weights[] = $symT[$s2];
                $nb = $nbT[$s2];
                $p -= $nb;
                $s2 = $nextT[$s2] + ((($b[($p >> 3) + 1] | ($b[($p >> 3) + 2] << 8)) >> ($p & 7)) & ((1 << $nb) - 1));
                if ($p < 64) {
                    $weights[] = $symT[$s1];
                    break;
                }
            }
            $pos += $n;
        }

        // same validation as libzstd's HUF_readStats()
        $total = 0;
        foreach ($weights as $w) {
            if ($w > 11) {
                throw new ExtractionException('Invalid huffman table');
            }
            if ($w > 0) {
                $total += 1 << ($w - 1);
            }
        }
        $log = self::highBit($total) + 1;
        if ($total === 0 || $log > 11) {
            throw new ExtractionException('Invalid huffman table');
        }
        $rest = (1 << $log) - $total;
        if ($rest !== 1 << self::highBit($rest)) {
            throw new ExtractionException('Invalid huffman table');
        }
        $weights[] = self::highBit($rest) + 1;
        $ones = 0;
        foreach ($weights as $w) {
            if ($w === 1) {
                $ones++;
            }
        }
        if ($ones < 2 || ($ones & 1) !== 0) {
            throw new ExtractionException('Invalid huffman table');
        }

        $count = self::filled($log + 2, 0);
        foreach ($weights as $w) {
            $count[$w]++;
        }
        $start = self::filled($log + 2, 0);
        for ($w = 1, $acc = 0; $w <= $log; $w++) {
            $start[$w] = $acc;
            $acc += $count[$w] << ($w - 1);
        }
        $chr = self::filled(1 << $log, "\0");
        $nbs = self::filled(1 << $log, 0);
        foreach ($weights as $s => $w) {
            if ($w === 0) {
                continue;
            }
            $len = 1 << ($w - 1);
            $c = \chr($s);
            $nb = $log + 1 - $w;
            for ($i = $start[$w], $e = $i + $len; $i < $e; $i++) {
                $chr[$i] = $c;
                $nbs[$i] = $nb;
            }
            $start[$w] += $len;
        }
        $this->huffChr = $chr;
        $this->huffNb = $nbs;
        $this->huffLog = $log;
    }

    /**
     * @param array{array<int, int>, int}|null $prev
     * @param array<int, int> $default
     * @param array<int, int>|null $base
     * @param array<int, int>|null $bits
     * @param array{array<int, int>, int}|null $defaultCache
     * @return array{array<int, int>, int} [packed state entries, accuracy log]
     */
    private function seqTable(int $mode, string $blk, int &$pos, ?array $prev, array $default, int $defaultLog, ?array $base, ?array $bits, ?array &$defaultCache): array
    {
        $maxSymbol = $base === null ? 31 : \count($base) - 1;
        if ($mode === 0) {
            if ($defaultCache === null) {
                $defaultCache = self::seqTableFrom($default, $defaultLog, $base, $bits);
            }

            return $defaultCache;
        }
        if ($mode === 1) {
            $s = \ord($blk[$pos++]);
            if ($s > $maxSymbol) {
                throw new ExtractionException('Invalid zstd sequence symbol');
            }

            return [[$base === null || $bits === null ? $s << 20 : ($bits[$s] << 4) | ($base[$s] << 20)], 0];
        }
        if ($mode === 2) {
            $bitPos = 0;
            [$norm, $log] = self::readNormCounts($blk, $pos, $bitPos, $maxSymbol, $base === null ? 8 : 9);
            $pos += ($bitPos + 7) >> 3;

            return self::seqTableFrom($norm, $log, $base, $bits);
        }
        if ($prev === null) {
            throw new ExtractionException('zstd repeat mode without previous table');
        }

        return $prev;
    }

    /**
     * @param array<int, int> $norm
     * @param array<int, int>|null $base
     * @param array<int, int>|null $bits
     * @return array{array<int, int>, int}
     */
    private static function seqTableFrom(array $norm, int $log, ?array $base, ?array $bits): array
    {
        [$nb, $next, $sym] = self::buildFse($norm, $log);
        $t = [];
        foreach ($sym as $i => $s) {
            $t[$i] = $nb[$i] | ($next[$i] << 9) | ($base === null || $bits === null ? $s << 20 : ($bits[$s] << 4) | ($base[$s] << 20));
        }

        return [$t, $log];
    }

    /**
     * @param array<int, int> $norm
     * @return array{array<int, int>, array<int, int>, array<int, int>} [nbBits, nextBase, symbol] per state
     */
    private static function buildFse(array $norm, int $log): array
    {
        $size = 1 << $log;
        $high = $size - 1;
        $sym = self::filled($size, 0);
        $symNext = [];
        foreach ($norm as $s => $c) {
            if ($c === -1) {
                $sym[$high--] = $s;
                $symNext[$s] = 1;
            } else {
                $symNext[$s] = $c;
            }
        }
        $step = ($size >> 1) + ($size >> 3) + 3;
        $mask = $size - 1;
        $p = 0;
        foreach ($norm as $s => $c) {
            for ($i = 0; $i < $c; $i++) {
                $sym[$p] = $s;
                do {
                    $p = ($p + $step) & $mask;
                } while ($p > $high);
            }
        }
        $nb = [];
        $next = [];
        for ($u = 0; $u < $size; $u++) {
            $x = $symNext[$sym[$u]]++;
            $nb[$u] = $log - self::highBit($x);
            $next[$u] = ($x << $nb[$u]) - $size;
        }

        return [$nb, $next, $sym];
    }

    /**
     * Reads an FSE table description (forward bitstream) starting at byte $pos.
     *
     * @return array{array<int,int>, int}
     */
    private static function readNormCounts(string $src, int $pos, int &$bitPos, int $maxSymbol, int $maxLog): array
    {
        $read = static function (int $n) use ($src, $pos, &$bitPos): int {
            $j = $pos + ($bitPos >> 3);
            $v = 0;
            for ($k = 0; $k < 4; $k++) {
                $v |= (isset($src[$j + $k]) ? \ord($src[$j + $k]) : 0) << ($k * 8);
            }

            return ($v >> ($bitPos & 7)) & ((1 << $n) - 1);
        };
        $log = $read(4) + 5;
        $bitPos += 4;
        if ($log > $maxLog) {
            throw new ExtractionException('FSE table accuracy log too large');
        }
        $remaining = (1 << $log) + 1;
        $threshold = 1 << $log;
        $nbBits = $log + 1;
        $norm = [];
        $s = 0;
        $prev0 = false;
        while ($remaining > 1 && $s <= $maxSymbol) {
            if ($prev0) {
                $n0 = $s;
                while ($read(16) === 0xFFFF) {
                    $n0 += 24;
                    $bitPos += 16;
                }
                $r = $read(2);
                while ($r === 3) {
                    $n0 += 3;
                    $bitPos += 2;
                    $r = $read(2);
                }
                $n0 += $r;
                $bitPos += 2;
                while ($s < $n0) {
                    $norm[$s++] = 0;
                }
            }
            $max = (2 * $threshold - 1) - $remaining;
            $v = $read($nbBits);
            if (($v & ($threshold - 1)) < $max) {
                $count = $v & ($threshold - 1);
                $bitPos += $nbBits - 1;
            } else {
                $count = $v & (2 * $threshold - 1);
                if ($count >= $threshold) {
                    $count -= $max;
                }
                $bitPos += $nbBits;
            }
            $count--;
            $remaining -= $count < 0 ? -$count : $count;
            $norm[$s++] = $count;
            $prev0 = $count === 0;
            while ($remaining < $threshold) {
                $nbBits--;
                $threshold >>= 1;
            }
        }
        if ($remaining !== 1) {
            throw new ExtractionException('Corrupted FSE table description');
        }

        return [$norm, $log];
    }

    /**
     * @template T
     * @param T $value
     * @return list<T>
     */
    private static function filled(int $count, $value): array
    {
        return array_pad([], $count, $value);
    }

    private static function highBit(int $v): int
    {
        $r = 0;
        while ($v > 1) {
            $v >>= 1;
            $r++;
        }

        return $r;
    }
}
