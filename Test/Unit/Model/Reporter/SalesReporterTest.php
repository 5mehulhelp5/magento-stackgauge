<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use StackNuts\ViewGento\Model\Reporter\SalesReporter;

class SalesReporterTest extends TestCase
{
    public function testGetStatusReturnsCountsFromDb(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);

        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        // Both calls (orders and quotes) return the same mocked value.
        $connection->method('fetchOne')->willReturn('123');

        $reporter = new SalesReporter($resource);

        $status = $reporter->getStatus();

        $this->assertArrayHasKey('orders_lifetime_count', $status);
        $this->assertSame(123, $status['orders_lifetime_count']->getValue());

        $this->assertArrayHasKey('quotes_with_items_lifetime_count', $status);
        $this->assertSame(123, $status['quotes_with_items_lifetime_count']->getValue());
    }
}
