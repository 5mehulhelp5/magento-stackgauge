<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\LogOffsetStore;

class LogOffsetStoreTest extends TestCase
{
    public function testGetReturnsNullWhenNoRowStoredYet(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new LogOffsetStore($resourceConnection);

        $this->assertNull($store->get('exception.log'));
    }

    public function testGetReturnsTheStoredCursor(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(['byte_offset' => '1024', 'running_count' => '7']);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new LogOffsetStore($resourceConnection);

        $this->assertSame(['byte_offset' => 1024, 'running_count' => 7], $store->get('exception.log'));
    }

    public function testSaveUpsertsByFilePath(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'stacknuts_stackgauge_log_offset',
                ['file_path' => 'exception.log', 'byte_offset' => 2048, 'running_count' => 9],
                ['byte_offset', 'running_count']
            );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $store = new LogOffsetStore($resourceConnection);
        $store->save('exception.log', 2048, 9);
    }
}
