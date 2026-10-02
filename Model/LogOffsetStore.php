<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Tiny per-file read cursor used by Reporter\LogReporter to read only newly-appended bytes on
 * each run instead of streaming the whole file every time. One row per monitored log file
 * name - every file this module reads lives under var/log via DirectoryList::LOG, so the name
 * alone is already a stable, unique key; a raw ResourceConnection upsert is simpler than a
 * full Model/ResourceModel pair for what's really just a tiny key-value store.
 */
class LogOffsetStore
{
    private const TABLE = 'stacknuts_stackgauge_log_offset';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * The stored cursor for $filePath, or null if this file has never been read before.
     *
     * @param string $filePath
     * @return array{byte_offset: int, running_count: int}|null
     */
    public function get(string $filePath): ?array
    {
        $connection = $this->resourceConnection->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE), ['byte_offset', 'running_count'])
                ->where('file_path = ?', $filePath)
        );

        if ($row === false) {
            return null;
        }

        return ['byte_offset' => (int)$row['byte_offset'], 'running_count' => (int)$row['running_count']];
    }

    /**
     * Persists the new cursor position and running count for $filePath.
     *
     * Inserts a new row the first time this file is seen.
     *
     * @param string $filePath
     * @param int $byteOffset
     * @param int $runningCount
     */
    public function save(string $filePath, int $byteOffset, int $runningCount): void
    {
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['file_path' => $filePath, 'byte_offset' => $byteOffset, 'running_count' => $runningCount],
            ['byte_offset', 'running_count']
        );
    }
}
