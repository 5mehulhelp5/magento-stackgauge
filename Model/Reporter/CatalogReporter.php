<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;

/**
 * Enabled product count - a simple "the catalog hasn't been wiped" signal, checked daily
 * since catalog size doesn't need hourly monitoring. Product "status" is an EAV attribute,
 * not a flat column, so this goes through the product collection (still a single COUNT(*)
 * query via getSize(), as long as items are never loaded) rather than hand-rolled SQL against
 * the EAV tables.
 */
class CatalogReporter implements ReporterInterface, MetricCatalogInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '2.0';
    private const METRIC_PRODUCTS_ENABLED = 'catalog.products_enabled_count';

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'catalog';
    }

    public function getLabel(): string
    {
        return 'Catalog';
    }

    public function getDescription(): string
    {
        return 'Enabled product count.';
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
        $enabledCount = $this->productCollectionFactory->create()
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->getSize();

        return ['general' => Section::facts('general', 'General', $this->getDescription(), [
            'products_enabled_count' => Field::trackableNumber(
                'Products Enabled',
                $enabledCount,
                self::METRIC_PRODUCTS_ENABLED,
                MetricDefinition::AGGREGATION_LATEST
            ),
        ])];
    }

    public function getTrackableMetrics(): array
    {
        return [
            // A daily-cadence metric's default window must comfortably outlive the ~24h gap
            // between samples, or the "latest" sample ages out of the window before the next
            // one arrives and the rule spuriously sees no data at all.
            new MetricDefinition(
                self::METRIC_PRODUCTS_ENABLED,
                'Products Enabled',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                1,
                1500
            ),
        ];
    }
}
