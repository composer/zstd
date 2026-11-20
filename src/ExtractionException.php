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
 * Thrown when an archive is invalid, unsafe to extract, or extraction fails.
 */
class ExtractionException extends \RuntimeException
{
}
