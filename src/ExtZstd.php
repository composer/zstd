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
 * Decompresses with the zstd PHP extension (https://github.com/kjdev/php-ext-zstd).
 *
 * zstd_uncompress() handles one frame, and depending on the extension version it ignores what follows the first
 * frame or returns partial output for truncated input. Frames are therefore validated and split by
 * ZstdDecoder::scanFrames() and fed one at a time, which leaves libzstd to decode and verify each of them.
 *
 * It also cannot limit its output. A frame declares its size, or the framing bounds it (128KB per block). Only
 * when that bound is over the limit, the pure PHP decoder runs first to check the actual size.
 *
 * @internal
 */
final class ExtZstd
{
    public static function isAvailable(): bool
    {
        return \function_exists('zstd_uncompress');
    }

    /**
     * @param int|null $maxSize rejects input decompressing to more bytes than this
     * @throws ExtractionException
     */
    public static function decompress(string $data, ?int $maxSize = null): string
    {
        $frames = ZstdDecoder::scanFrames($data);
        if ($maxSize !== null && !ZstdDecoder::fitsWithin($frames, $maxSize)) {
            ZstdDecoder::decompress($data, '', $maxSize);
        }
        $result = '';
        foreach ($frames as $frame) {
            error_clear_last();
            $decoded = @zstd_uncompress(substr($data, $frame['start'], $frame['end'] - $frame['start']));
            if (!\is_string($decoded)) {
                $error = error_get_last();
                throw new ExtractionException('zstd: '.($error !== null ? preg_replace('{^zstd_uncompress\(\): }', '', $error['message']) : 'decompression failed'));
            }
            $result .= $decoded;
        }

        return $result;
    }
}
