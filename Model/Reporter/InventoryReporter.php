<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;

/**
 * "In stock" isn't a product EAV attribute - it lives on cataloginventory_stock_item, one
 * row per product - so this counts directly against that table rather than loading a
 * stock-status collection.
 */
class InventoryReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_OUT_OF_STOCK = 'inventory.out_of_stock_count';

    /**
     * @param ProductCollectionFactory $productCollectionFactory
     * @param ResourceConnection $resourceConnection
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the inventory reporter.
     */
    public function getName(): string
    {
        return 'inventory';
    }

    /**
     * Human-readable label for the inventory reporter block.
     */
    public function getLabel(): string
    {
        return 'Inventory';
    }

    /**
     * One-line summary of what the inventory reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Basic inventory counts (in-stock / out-of-stock).';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports total product count plus in-stock/out-of-stock counts read from cataloginventory_stock_item.
     */
    public function getStatus(): array
    {
        $total = $this->productCollectionFactory->create()->getSize();

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        $inStock = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('is_in_stock = ?', 1)
        );

        $outOfStock = max(0, $total - $inStock);

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'total_products' => $this->field->number('Total Products', $total),
            'in_stock' => $this->field->number('In Stock', $inStock),
            'out_of_stock' => $this->field->trackableNumber(
                'Out of Stock',
                $outOfStock,
                self::METRIC_OUT_OF_STOCK,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

    /**
     * Alertable metric for the inventory reporter: out-of-stock product count.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // Window comfortably outlives the ~24h gap between daily-cadence samples.
            // Threshold is a rough default - catalog sizes vary hugely.
            new MetricDefinition(
                self::METRIC_OUT_OF_STOCK,
                'Inventory: Out of Stock',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                50,
                1500,
                null,
                description: '{value} products are out of stock, above the limit of {threshold}.',
                impact: 'Customers see unbuyable products, which loses sales.'
            ),
        ];
    }
}
