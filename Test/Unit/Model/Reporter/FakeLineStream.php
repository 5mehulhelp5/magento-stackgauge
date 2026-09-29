<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

/**
 * Minimal Filesystem\File\ReadInterface double: hands back $content one line at a time via
 * readLine()/eof(), the same contract LogReporter streams against. Methods it never calls
 * intentionally throw, so a future accidental whole-file read shows up as a test failure
 * rather than silently working against the fake.
 */
class FakeLineStream implements \Magento\Framework\Filesystem\File\ReadInterface
{
    /** @var list<string> */
    private array $lines;

    /**
     * @var int
     */
    private int $position = 0;

    public function __construct(string $content)
    {
        $this->lines = $content === '' ? [] : explode("\n", $content);

        // A trailing "\n" produces one trailing empty element from explode() - a real file
        // stream's eof() flips true right after the last real line, not one call later.
        if ($this->lines !== [] && end($this->lines) === '') {
            array_pop($this->lines);
        }
    }

    public function eof()
    {
        return $this->position >= count($this->lines);
    }

    public function readLine($length, $ending = null)
    {
        if ($this->eof()) {
            return false;
        }

        return $this->lines[$this->position++] . "\n";
    }

    public function close()
    {
        return true;
    }

    public function read($length)
    {
        throw new \LogicException('FakeLineStream does not support read() - LogReporter should only use readLine().');
    }

    public function readAll($flag = null, $context = null)
    {
        throw new \LogicException('FakeLineStream does not support readAll() - LogReporter should only use readLine().');
    }

    public function readCsv($length = 0, $delimiter = ',', $enclosure = '"', $escape = "\0")
    {
        throw new \LogicException('FakeLineStream does not support readCsv().');
    }

    public function tell()
    {
        throw new \LogicException('FakeLineStream does not support tell().');
    }

    public function seek($length, $whence = SEEK_SET)
    {
        throw new \LogicException('FakeLineStream does not support seek().');
    }

    public function stat()
    {
        throw new \LogicException('FakeLineStream does not support stat().');
    }
}
