<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use StackNuts\StackGauge\Model\Reporter\AbandonedCartsReporter;

class AbandonedCartsReporterTest extends TestCase
{
    public function testGetStatusReturnsCountsAndSamples(): void
    {
        $colCount = $this->createMock(QuoteCollection::class);
        $colCount->method('addFieldToFilter')->willReturnSelf();
        $colCount->method('getSize')->willReturn(3);

        $sampleItem = new class { public function getId() { return 101; } };
        $colSample = $this->createMock(QuoteCollection::class);
        $colSample->method('addFieldToFilter')->willReturnSelf();
        $colSample->method('setPageSize')->willReturnSelf();
        $colSample->method('getItems')->willReturn([$sampleItem]);

        $factory = $this->createMock(QuoteCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($colCount, $colSample);

        $reporter = new AbandonedCartsReporter($factory);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('general', $status);
        $this->assertArrayHasKey('samples', $status);
        $this->assertSame(3, $status['general']->getFields()['count']->getValue());
        $this->assertCount(1, $status['samples']->getRows());
    }
}
