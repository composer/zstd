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
 * Safely extracts .tar.zst archives with the best available method.
 *
 * All methods produce the same result: decompression behaves like the reference zstd implementation, and tar
 * entries go through the same rules (implemented in C for the bundled binary, in PHP for the other methods).
 */
final class Extractor
{
    /** the zstd PHP extension, decompressing in memory, then tar extraction in PHP */
    public const METHOD_EXT_ZSTD = 'ext-zstd';
    /** a zstd executable from PATH, then tar extraction in PHP */
    public const METHOD_ZSTD_CLI = 'zstd-cli';
    /** the bundled zstd-extract binary, streaming decompression and extraction */
    public const METHOD_BUNDLED = 'bundled';
    /** the pure PHP decoder, then tar extraction in PHP, much slower than the others */
    public const METHOD_PHP = 'php';

    /** 1024MB */
    public const DEFAULT_MAX_SIZE = 1 << 30;

    public const METHODS = [self::METHOD_BUNDLED, self::METHOD_EXT_ZSTD, self::METHOD_ZSTD_CLI, self::METHOD_PHP];

    /** @var list<self::METHOD_*>|null */
    private $methods;
    /** @var NativeExtractor */
    private $bundled;
    /** @var ZstdCli */
    private $cli;
    /** @var self::METHOD_*|null */
    private $lastMethod;
    /** @var array<string, string> */
    private $unavailable = [];
    /** @var int */
    private $maxSize;

    /**
     * @param string|null                $cacheDir     Composer's cache directory, the bundled binary gets copied to its
     *                                                 "zstd" subdirectory when it cannot run in place (from a phar);
     *                                                 defaults to the location Composer uses
     * @param list<self::METHOD_*>|null  $methods      methods to try, in order; the first available one is used
     *                                                 (defaults to getMethodOrder())
     * @param string|null                $bundledPath  overrides the bundled binary
     * @param string|null                $zstdCliPath  zstd executable to use instead of looking it up in PATH
     * @param int                        $maxSize      archives decompressing to more bytes than this (the size of the
     *                                                 tar stream) are rejected
     */
    public function __construct(?string $cacheDir = null, ?array $methods = null, ?string $bundledPath = null, ?string $zstdCliPath = null, int $maxSize = self::DEFAULT_MAX_SIZE)
    {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException('The maximum size must be positive');
        }
        $this->maxSize = $maxSize;
        foreach ($methods ?? [] as $method) {
            if (!\in_array($method, self::METHODS, true)) {
                throw new \InvalidArgumentException('Unknown extraction method '.$method);
            }
        }
        $this->methods = $methods;
        $this->bundled = new NativeExtractor($cacheDir, $bundledPath);
        $this->cli = new ZstdCli($zstdCliPath);
    }

    /**
     * Extracts a .tar.zst archive.
     *
     * A method that is not available is skipped. A method that runs and rejects the archive throws, the next
     * methods are not tried. On failure the destination is removed (or emptied if it existed before).
     *
     * @param string $destination a directory which must not exist or be empty
     * @throws ExtractionException
     */
    public function extract(string $archive, string $destination): void
    {
        $this->prepare($archive, $destination)->run();
    }

    /**
     * Prepares an extraction: checks the archive and destination (creating the latter) and picks the method.
     *
     * This allows running the extraction command with your own process runner, e.g. several in parallel:
     *
     *     $extraction = $extractor->prepare($archive, $destination);
     *     $command = $extraction->getCommand();
     *     if ($command === null) {
     *         $extraction->run();
     *     } else {
     *         // run $command, then:
     *         $extraction->complete($exitCode, $stdout, $stderr);
     *     }
     *
     * @param string $destination a directory which must not exist or be empty
     * @throws ExtractionException
     */
    public function prepare(string $archive, string $destination): Extraction
    {
        $this->lastMethod = null;
        if (!is_file($archive)) {
            throw new ExtractionException('Archive '.$archive.' does not exist');
        }

        clearstatcache(true, $destination);
        if (is_link($destination) || (file_exists($destination) && !is_dir($destination))) {
            throw new ExtractionException('Destination '.$destination.' is not a directory');
        }
        $created = false;
        if (is_dir($destination)) {
            if (!Filesystem::isEmptyDir($destination)) {
                throw new ExtractionException('Destination '.$destination.' must be empty');
            }
        }

        $method = $this->nextMethod(null);
        if ($method === null) {
            throw $this->noMethodException();
        }

        if (!is_dir($destination)) {
            if (!@mkdir($destination, 0777, true) && !is_dir($destination)) {
                throw new ExtractionException('Could not create destination '.$destination);
            }
            $created = true;
        }

        return new Extraction($this, $archive, $destination, $created, $method);
    }

    /**
     * The order in which methods are tried.
     *
     * By default the bundled binary comes first: it is the fastest, and the only method extracting entirely in
     * another process, so extractions run in parallel scale (see Extraction::getCommand()). From a phar it first
     * gets copied to the cache directory; prepare() only picks it once that worked and the copy runs.
     * ext-zstd and zstd-cli decompress natively but write files from PHP.
     *
     * @return list<self::METHOD_*>
     */
    public function getMethodOrder(): array
    {
        return $this->methods ?? self::METHODS;
    }

    /**
     * The methods that can be used here, in the order they would be tried.
     *
     * This checks each of them, which for the bundled binary means copying it out of a phar and running it once.
     * Unless another method is available, extraction falls back to the much slower METHOD_PHP.
     *
     * @return list<self::METHOD_*>
     */
    public function getAvailableMethods(): array
    {
        $available = [];
        foreach ($this->getMethodOrder() as $method) {
            if ($this->isAvailable($method)) {
                $available[] = $method;
            }
        }

        return $available;
    }

    /**
     * Which method the last successful extract() call used.
     *
     * @return self::METHOD_*|null
     */
    public function getLastMethod(): ?string
    {
        return $this->lastMethod;
    }

    /**
     * Why methods were found unavailable so far.
     *
     * @return array<string, string> method => reason
     */
    public function getUnavailableReasons(): array
    {
        return $this->unavailable;
    }

    /**
     * @param self::METHOD_* $method
     */
    private function isAvailable(string $method): bool
    {
        if (isset($this->unavailable[$method])) {
            return false;
        }
        // only the bundled binary does not parse zstd frames in PHP, which needs 64-bit ints
        if (\PHP_INT_SIZE < 8 && $method !== self::METHOD_BUNDLED) {
            $this->unavailable[$method] = 'requires a 64-bit PHP build';

            return false;
        }
        switch ($method) {
            case self::METHOD_EXT_ZSTD:
                $reason = ExtZstd::isAvailable() ? null : 'the zstd extension is not loaded';
                break;
            case self::METHOD_ZSTD_CLI:
                $reason = $this->cli->isAvailable() ? null : (string) $this->cli->getUnavailableReason();
                break;
            case self::METHOD_BUNDLED:
                $reason = $this->bundled->isAvailable() ? null : (string) $this->bundled->getUnavailableReason();
                break;
            default:
                $reason = null;
        }
        if ($reason !== null) {
            $this->unavailable[$method] = $reason;
        }

        return $reason === null;
    }

    /**
     * The first available method after $after (or the first one at all).
     *
     * @param self::METHOD_*|null $after
     * @return self::METHOD_*|null
     */
    private function nextMethod(?string $after): ?string
    {
        $order = $this->getMethodOrder();
        $index = $after === null ? 0 : (int) array_search($after, $order, true) + 1;
        for ($count = \count($order); $index < $count; $index++) {
            if ($this->isAvailable($order[$index])) {
                return $order[$index];
            }
        }

        return null;
    }

    private function noMethodException(): ExtractionException
    {
        $reasons = [];
        foreach ($this->unavailable as $method => $reason) {
            $reasons[] = $method.': '.$reason;
        }

        return new ExtractionException('No extraction method is available ('.implode(', ', $reasons).')');
    }

    /**
     * @internal used by Extraction
     * @param self::METHOD_* $method
     * @return list<string>|null
     */
    public function getCommandFor(string $method, string $archive, string $destination): ?array
    {
        switch ($method) {
            case self::METHOD_BUNDLED:
                return $this->bundled->getCommand($archive, $destination, $this->maxSize);
            case self::METHOD_ZSTD_CLI:
                return $this->cli->getCommand($archive);
            default:
                return null;
        }
    }

    /**
     * Runs $method, falling back to the next ones when it turns out it cannot run.
     *
     * @internal used by Extraction
     * @param self::METHOD_* $method
     */
    public function runFrom(string $method, string $archive, string $destination): void
    {
        switch ($method) {
            case self::METHOD_BUNDLED:
                if (!$this->bundled->extract($archive, $destination, $this->maxSize)) {
                    $this->fallBack($method, (string) $this->bundled->getUnavailableReason(), $archive, $destination);

                    return;
                }
                break;

            case self::METHOD_EXT_ZSTD:
                (new TarExtractor())->extract(ExtZstd::decompress(self::read($archive), $this->maxSize), $destination);
                break;

            case self::METHOD_ZSTD_CLI:
                (new TarExtractor())->extract($this->cli->decompress($archive, $this->maxSize), $destination);
                break;

            default:
                (new TarExtractor())->extract(ZstdDecoder::decompress(self::read($archive), '', $this->maxSize), $destination);
        }
        $this->lastMethod = $method;
    }

    /**
     * Finishes $method after its command ran.
     *
     * @internal used by Extraction
     * @param self::METHOD_* $method
     */
    public function completeFrom(string $method, string $archive, string $destination, int $exitCode, string $output, string $errorOutput): void
    {
        if ($method === self::METHOD_BUNDLED) {
            if (!$this->bundled->handleResult($exitCode, $errorOutput)) {
                $this->fallBack($method, (string) $this->bundled->getUnavailableReason(), $archive, $destination);

                return;
            }
        } elseif ($method === self::METHOD_ZSTD_CLI) {
            if (\strlen($output) > $this->maxSize) {
                throw ZstdDecoder::tooLarge($this->maxSize);
            }
            (new TarExtractor())->extract($this->cli->handleResult($exitCode, $output, $errorOutput), $destination);
        } else {
            throw new \LogicException($method.' has no command, call run() instead');
        }
        $this->lastMethod = $method;
    }

    /**
     * @param self::METHOD_* $failed
     */
    private function fallBack(string $failed, string $reason, string $archive, string $destination): void
    {
        $this->unavailable[$failed] = $reason;
        Filesystem::clear($destination);
        $next = $this->nextMethod($failed);
        if ($next === null) {
            throw $this->noMethodException();
        }
        $this->runFrom($next, $archive, $destination);
    }

    private static function read(string $archive): string
    {
        $data = @file_get_contents($archive);
        if ($data === false) {
            throw new ExtractionException('Could not read '.$archive);
        }

        return $data;
    }
}
