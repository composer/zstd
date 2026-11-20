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
 * A prepared extraction, see Extractor::prepare().
 *
 * Either call run(), or, when getCommand() returns one, run that command with your own process runner (e.g.
 * asynchronously, to extract several archives in parallel) and pass its result to complete().
 */
final class Extraction
{
    /** @var Extractor */
    private $extractor;
    /** @var string */
    private $archive;
    /** @var string */
    private $destination;
    /** @var bool */
    private $createdDestination;
    /** @var Extractor::METHOD_* */
    private $method;
    /** @var bool */
    private $finished = false;

    /**
     * @internal use Extractor::prepare()
     * @param Extractor::METHOD_* $method
     */
    public function __construct(Extractor $extractor, string $archive, string $destination, bool $createdDestination, string $method)
    {
        $this->extractor = $extractor;
        $this->archive = $archive;
        $this->destination = $destination;
        $this->createdDestination = $createdDestination;
        $this->method = $method;
    }

    /**
     * @return Extractor::METHOD_*
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    public function getArchive(): string
    {
        return $this->archive;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    /**
     * The command to run for process based methods (bundled, zstd-cli), null for in-process ones (call run()).
     *
     * The zstd-cli command writes the decompressed data to stdout, without any limit. The maximum size is only
     * checked by complete(), while run() stops zstd when it goes over.
     *
     * @return list<string>|null
     */
    public function getCommand(): ?array
    {
        return $this->extractor->getCommandFor($this->method, $this->archive, $this->destination);
    }

    /**
     * Runs the extraction synchronously.
     *
     * @throws ExtractionException
     */
    public function run(): void
    {
        $this->guard(function (): void {
            $this->extractor->runFrom($this->method, $this->archive, $this->destination);
        });
    }

    /**
     * Finishes the extraction after the command from getCommand() ran.
     *
     * When the command could not run at all (e.g. the binary cannot be executed here), the next available method
     * is used synchronously instead.
     *
     * @param string $output      the command's stdout (holds the decompressed data for zstd-cli)
     * @param string $errorOutput the command's stderr
     * @throws ExtractionException
     */
    public function complete(int $exitCode, string $output, string $errorOutput): void
    {
        $this->guard(function () use ($exitCode, $output, $errorOutput): void {
            $this->extractor->completeFrom($this->method, $this->archive, $this->destination, $exitCode, $output, $errorOutput);
        });
    }

    /**
     * @param callable(): void $operation
     */
    private function guard(callable $operation): void
    {
        if ($this->finished) {
            throw new \LogicException('This extraction already ran');
        }
        $this->finished = true;
        try {
            $operation();
        } catch (\Throwable $e) {
            try {
                $this->createdDestination ? Filesystem::remove($this->destination) : Filesystem::clear($this->destination);
            } catch (ExtractionException $cleanup) {
                // keep the original error
            }
            if ($e instanceof ExtractionException || $e instanceof \LogicException) {
                throw $e;
            }
            throw new ExtractionException($e->getMessage(), 0, $e);
        }
    }
}
