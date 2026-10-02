<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

/**
 * Minimal Filesystem\File\ReadInterface double: hands back $content one line at a time via
 * readLine()/eof(), the same contract LogReporter streams against. tell()/seek() work in
 * terms of byte offsets exactly like a real file handle, so LogReporter's incremental-read
 * logic (seek to the last saved offset, tell() the new one at the end) can be exercised for
 * real rather than stubbed out. Methods LogReporter never calls intentionally throw, so a
 * future accidental whole-file read shows up as a test failure rather than silently working
 * against the fake.
 */
class FakeLineStream implements \Magento\Framework\Filesystem\File\ReadInterface
{
    /** @var list<string> */
    private array $lines;

    /**
     * cumulativeBytes[$i] is the byte offset immediately after reading $i lines - i.e. where
     * readLine() would next resume. Precomputed once so tell()/seek() don't need to care how
     * each line's bytes were produced.
     *
     * @var list<int>
     */
    private array $cumulativeBytes;

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

        $this->cumulativeBytes = [0];
        $running = 0;
        foreach ($this->lines as $line) {
            // readLine() always re-appends "\n" below, so every line costs its length plus one -
            // matching the real file on disk (this fake never represents content without
            // trailing newlines on every line, which is fine for everything this reporter does).
            $running += strlen($line) + 1;
            $this->cumulativeBytes[] = $running;
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

    public function tell()
    {
        return $this->cumulativeBytes[$this->position];
    }

    public function seek($length, $whence = SEEK_SET)
    {
        $index = array_search($length, $this->cumulativeBytes, true);

        if ($index === false) {
            throw new \LogicException(
                "FakeLineStream::seek() called with offset {$length}, which doesn't land on a line "
                . 'boundary - only offsets produced by this fake\'s own tell() are supported.'
            );
        }

        $this->position = $index;

        return $length;
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
        throw new \LogicException(
            'FakeLineStream does not support readAll() - LogReporter should only use readLine().'
        );
    }

    public function readCsv($length = 0, $delimiter = ',', $enclosure = '"', $escape = "\0")
    {
        throw new \LogicException('FakeLineStream does not support readCsv().');
    }

    public function stat()
    {
        throw new \LogicException(
            'FakeLineStream does not support stat() - LogReporter reads size via the directory-level '
            . 'stat(), not the open file handle\'s.'
        );
    }
}
