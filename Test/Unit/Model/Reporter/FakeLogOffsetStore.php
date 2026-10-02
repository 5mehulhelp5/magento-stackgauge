<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use StackNuts\StackGauge\Model\LogOffsetStore;

/**
 * In-memory LogOffsetStore double - a real one needs a database, and LogReporterTest needs to
 * simulate multiple runs against the same persisted cursor to exercise incremental reads for
 * real rather than stubbing them out.
 */
class FakeLogOffsetStore extends LogOffsetStore
{
    /**
     * @var array<string, array{byte_offset: int, running_count: int}>
     */
    private array $state = [];

    public function __construct()
    {
        // Deliberately skips the parent constructor - no ResourceConnection to give it, and
        // this fake never needs one.
    }

    public function get(string $filePath): ?array
    {
        return $this->state[$filePath] ?? null;
    }

    public function save(string $filePath, int $byteOffset, int $runningCount): void
    {
        $this->state[$filePath] = ['byte_offset' => $byteOffset, 'running_count' => $runningCount];
    }
}
