<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

/**
 * "in stock" isn't a product EAV attribute (addAttributeToFilter('is_in_stock', ...) throws
 * "invalid attribute name") - it lives on cataloginventory_stock_item, one row per product,
 * so a direct COUNT against that table (same ResourceConnection pattern as DatabaseReporter/
 * SalesReporter) is both correct and cheaper than loading a stock-status collection.
 */
final class InventoryReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '2.0';

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'inventory';
    }

    public function getLabel(): string
    {
        return 'Inventory';
    }

    public function getDescription(): string
    {
        return 'Basic inventory counts (in-stock / out-of-stock).';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getCadence(): string
    {
        return self::CADENCE_DAILY;
    }

    public function getStatus(): array
    {
        $total = $this->productCollectionFactory->create()->getSize();

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $inStock = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('is_in_stock = ?', 1)
        );

        $outOfStock = max(0, $total - $inStock);

        return ['general' => Section::facts('general', 'General', $this->getDescription(), [
            'total_products' => Field::number('Total Products', $total),
            'in_stock' => Field::number('In Stock', $inStock),
            'out_of_stock' => Field::number('Out of Stock', $outOfStock),
        ])];
    }
}
