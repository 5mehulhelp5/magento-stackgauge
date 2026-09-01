<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use StackNuts\ViewGento\Model\Reporter\SecurityReporter;

class SecurityReporterTest extends TestCase
{
    public function testGetStatusReturnsSecurityInfo(): void
    {
        $col1 = $this->createMock(UserCollection::class);
        $col1->method('getSize')->willReturn(3);

        $col2 = $this->createMock(UserCollection::class);
        $col2->method('addFieldToFilter')->willReturnSelf();
        $col2->method('getSize')->willReturn(1);

        $factory = $this->createMock(UserCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($col1, $col2);

        $reporter = new SecurityReporter($factory);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('security', $status);
    }
}
