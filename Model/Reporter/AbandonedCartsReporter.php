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
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\Clock;

class AbandonedCartsReporter implements ReporterInterface, DeclaresCadenceInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_COUNT = 'abandoned_carts.count';
    private const SAMPLE_LIMIT = 5;
    private const HOURS_OLD = 24;

    /**
     * @param QuoteCollectionFactory $quoteCollectionFactory
     * @param Clock $clock
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly QuoteCollectionFactory $quoteCollectionFactory,
        private readonly Clock $clock,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the abandoned-carts reporter.
     */
    public function getName(): string
    {
        return 'abandoned_carts';
    }

    /**
     * Human-readable label for the abandoned-carts reporter block.
     */
    public function getLabel(): string
    {
        return 'Abandoned Carts';
    }

    /**
     * One-line summary of the abandoned-carts reporter, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Counts of carts with items that appear abandoned (no activity in last 24h).';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the count of active quotes with items that haven't been updated in the last
     * 24h (read via QuoteCollectionFactory), plus a sample of up to five such quote IDs.
     */
    public function getStatus(): array
    {
        $now = $this->clock->now();
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
            $sampleFields[] = $this->field->array('', [
                'name' => $this->field->varchar('Name', $id),
                'quote_id' => $this->field->varchar('Quote ID', $id),
            ]);
        }

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'count' => $this->field->trackableNumber(
                    'Count',
                    $total,
                    self::METRIC_COUNT,
                    MetricDefinition::AGGREGATION_DELTA
                ),
            ]),
            'samples' => $this->section->table('samples', 'Samples', 'Recently abandoned cart IDs.', $sampleFields),
        ];
    }

    /**
     * Alertable metric for the abandoned-carts reporter: week-over-week growth in the abandoned-cart count.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // Alerts on week-over-week growth (DELTA), not the absolute count: every active
            // store carries a large, constantly-refreshing backlog of carts that count as
            // "abandoned" after just 24h but aren't cleaned up for 30 days, so the absolute
            // count is permanently high as normal behaviour. A sudden growth spike is the
            // actual signal worth paging on. Window is a full week since growth needs several
            // days of samples to mean anything.
            new MetricDefinition(
                self::METRIC_COUNT,
                'Abandoned Carts: Weekly Growth',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                50,
                10080,
                null,
                description: 'Abandoned carts grew by {value} in the last {window}, above {threshold}.',
                impact: 'Possible checkout friction or payment problems.'
            ),
        ];
    }
}
