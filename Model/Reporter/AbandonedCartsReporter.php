<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

final class AbandonedCartsReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface
{
    private const SCHEMA_VERSION = '2.0';
    private const METRIC_COUNT = 'abandoned_carts.count';
    private const SAMPLE_LIMIT = 5;
    private const HOURS_OLD = 24;

    public function __construct(private readonly QuoteCollectionFactory $quoteCollectionFactory)
    {
    }

    public function getName(): string
    {
        return 'abandoned_carts';
    }

    public function getLabel(): string
    {
        return 'Abandoned Carts';
    }

    public function getDescription(): string
    {
        return 'Counts of carts with items that appear abandoned (no activity in last 24h).';
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
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $threshold = $now->modify(sprintf('-%d hours', self::HOURS_OLD))->format('Y-m-d H:i:s');

        $countCollection = $this->quoteCollectionFactory->create();
        $countCollection->addFieldToFilter('items_count', ['gt' => 0]);
        $countCollection->addFieldToFilter('updated_at', ['lt' => $threshold]);
        $countCollection->addFieldToFilter('is_active', ['eq' => 1]);
        $total = $countCollection->getSize();

        $sampleCollection = $this->quoteCollectionFactory->create();
        $sampleCollection->addFieldToFilter('items_count', ['gt' => 0]);
        $sampleCollection->addFieldToFilter('updated_at', ['lt' => $threshold]);
        $sampleCollection->addFieldToFilter('is_active', ['eq' => 1]);
        $sampleCollection->setPageSize(self::SAMPLE_LIMIT);
        $items = $sampleCollection->getItems();

        $sampleIds = [];
        foreach ($items as $item) {
            if (method_exists($item, 'getId')) {
                $sampleIds[] = (string) $item->getId();
            }
        }

        $sampleFields = [];
        foreach ($sampleIds as $id) {
            $sampleFields[] = Field::array('', [
                'name' => Field::varchar('Name', $id),
                'quote_id' => Field::varchar('Quote ID', $id),
            ]);
        }

        return [
            'general' => Section::facts('general', 'General', $this->getDescription(), [
                'count' => Field::trackableNumber('Count', $total, self::METRIC_COUNT, MetricDefinition::AGGREGATION_LATEST),
            ]),
            'samples' => Section::table('samples', 'Samples', 'Recently abandoned cart IDs.', $sampleFields),
        ];
    }

    public function getTrackableMetrics(): array
    {
        return [
            // Daily cadence, so the window must comfortably outlive the ~24h gap between
            // samples - see CatalogReporter for the same reasoning.
            new MetricDefinition(
                self::METRIC_COUNT,
                'Abandoned Carts',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                20,
                1500
            ),
        ];
    }
}
