<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Data\Collection;
use StackNuts\ViewGento\Model\Reporter\ProductHealthReporter;

class ProductHealthReporterTest extends TestCase
{
    public function testGetStatusReturnsCounts(): void
    {
        $col1 = $this->createMock(Collection::class);
        $col1->method('addAttributeToFilter')->willReturnSelf();
        $col1->method('getSize')->willReturn(5);

        $col2 = $this->createMock(Collection::class);
        $col2->method('addAttributeToFilter')->willReturnSelf();
        $col2->method('getSize')->willReturn(2);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($col1, $col2);

        $reporter = new ProductHealthReporter($factory);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('product_issues', $status);
    }
}
