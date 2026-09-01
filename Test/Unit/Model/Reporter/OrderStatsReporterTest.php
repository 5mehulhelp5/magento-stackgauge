<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use StackNuts\ViewGento\Model\Reporter\OrderStatsReporter;

class OrderStatsReporterTest extends TestCase
{
    public function testBuildsHourlyBucketsFromDb(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $connection->method('select')->willReturn($select);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $hour = $now->format('Y-m-d\TH:00:00+00:00');

        $connection->method('fetchAll')->willReturn([
            ['hour_bucket' => $hour, 'cnt' => '3', 'revenue' => '123.45'],
        ]);

        $reporter = new OrderStatsReporter($resource);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('orders_hourly', $status);
        $buckets = $status['orders_hourly']->getValue();
        $this->assertIsArray($buckets);
    }
}
