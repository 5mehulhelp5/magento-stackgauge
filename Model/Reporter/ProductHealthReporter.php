<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

final class ProductHealthReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface
{
    private const SCHEMA_VERSION = '2.0';
    private const METRIC_MISSING_IMAGE = 'product_health.missing_image_count';
    private const METRIC_NO_PRICE = 'product_health.no_price_count';

    public function __construct(private readonly ProductCollectionFactory $productCollectionFactory)
    {
    }

    public function getName(): string
    {
        return 'product_health';
    }

    public function getLabel(): string
    {
        return 'Product Health';
    }

    public function getDescription(): string
    {
        return 'Counts of common product data issues.';
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
        $missingImage = $this->productCollectionFactory->create()
            ->addAttributeToFilter('image', ['eq' => 'no_selection'])
            ->getSize();

        $noPrice = $this->productCollectionFactory->create()
            ->addAttributeToFilter('price', ['lte' => 0])
            ->getSize();

        return ['general' => Section::facts('general', 'General', $this->getDescription(), [
            'missing_image' => Field::trackableNumber(
                'Missing Image',
                $missingImage,
                self::METRIC_MISSING_IMAGE,
                MetricDefinition::AGGREGATION_LATEST
            ),
            'no_price' => Field::trackableNumber(
                'No Price',
                $noPrice,
                self::METRIC_NO_PRICE,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

    public function getTrackableMetrics(): array
    {
        return [
            // Daily cadence, so the window must comfortably outlive the ~24h gap between
            // samples - see CatalogReporter for the same reasoning.
            new MetricDefinition(
                self::METRIC_MISSING_IMAGE,
                'Missing Image',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                20,
                1500
            ),
            new MetricDefinition(
                self::METRIC_NO_PRICE,
                'No Price',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                5,
                1500
            ),
        ];
    }
}
