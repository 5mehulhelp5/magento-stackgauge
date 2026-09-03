<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\Indexer\Collection;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use StackNuts\StackGauge\Model\Reporter\IndexerReporter;

class IndexerReporterTest extends TestCase
{
    private function indexer(string $id, string $title, string $status, string $updatedAt): object
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($title);
        $indexer->method('getStatus')->willReturn($status);
        $indexer->method('isScheduled')->willReturn(true);
        $indexer->method('getLatestUpdated')->willReturn($updatedAt);

        return $indexer;
    }

    public function testValidIsABoolAndTitleIsNotDuplicated(): void
    {
        $indexer = $this->indexer('catalog_product_price', 'Product Price', StateInterface::STATUS_VALID, '2026-09-02 17:38:11');

        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$indexer]);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $reporter = new IndexerReporter($factory);
        $rows = $reporter->getStatus()['indexers']->getRows();

        $this->assertCount(1, $rows);
        $fields = $rows[0]->getValue();

        $this->assertSame('Product Price', $fields['title']->getValue());
        $this->assertArrayNotHasKey('name', $fields);
        $this->assertSame('datetime', $fields['updated_at']->getType());
        $this->assertSame('2026-09-02 17:38:11', $fields['updated_at']->getValue());
        $this->assertTrue($fields['valid']->getValue());
        $this->assertFalse($fields['valid']->jsonSerialize()['critical_when']);
    }

    public function testAnInvalidIndexerReportsValidAsFalse(): void
    {
        $indexer = $this->indexer('cataloginventory_stock', 'Stock', StateInterface::STATUS_INVALID, '2026-09-02 17:38:11');

        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$indexer]);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $reporter = new IndexerReporter($factory);
        $fields = $reporter->getStatus()['indexers']->getRows()[0]->getValue();

        $this->assertFalse($fields['valid']->getValue());
    }
}
