<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\MetricCatalogInterface;
use StackNuts\ViewGento\Api\MetricDefinition;
use StackNuts\ViewGento\Api\ReporterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * Lifetime order/quote counts - single COUNT(*) queries via ResourceConnection, same pattern
 * as DatabaseReporter's SELECT VERSION(). Reported as ever-increasing lifetime counters
 * rather than "since midnight" - see MetricDefinition::AGGREGATION_DELTA for why: a
 * calendar-boundary reset needs store-timezone handling and false-positives every night just
 * after midnight, while a rolling delta over these lifetime counters ("fewer than 5 new
 * orders in the last 6 hours") gives the same signal without either problem.
 */
class SalesReporter implements ReporterInterface, MetricCatalogInterface
{
    private const SCHEMA_VERSION = '1.0';

    private const METRIC_ORDERS_LIFETIME = 'sales.orders_lifetime_count';
    private const METRIC_QUOTES_WITH_ITEMS_LIFETIME = 'sales.quotes_with_items_lifetime_count';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'sales';
    }

    public function getLabel(): string
    {
        return 'Sales';
    }

    public function getDescription(): string
    {
        return 'Lifetime order count and lifetime count of quotes that ever had an item added.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        return [
            'orders_lifetime_count' => Field::trackableNumber(
                'Orders (Lifetime)',
                $this->count('sales_order'),
                self::METRIC_ORDERS_LIFETIME,
                MetricDefinition::AGGREGATION_DELTA
            ),
            'quotes_with_items_lifetime_count' => Field::trackableNumber(
                'Quotes With Items (Lifetime)',
                $this->count('quote', 'items_count > 0'),
                self::METRIC_QUOTES_WITH_ITEMS_LIFETIME,
                MetricDefinition::AGGREGATION_DELTA
            ),
        ];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_ORDERS_LIFETIME,
                'Orders (Lifetime)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_LT,
                5,
                360
            ),
            new MetricDefinition(
                self::METRIC_QUOTES_WITH_ITEMS_LIFETIME,
                'Quotes With Items (Lifetime)',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_LT,
                3,
                360
            ),
        ];
    }

    /**
     * Deliberately does not catch failures here - a query failure should surface as this
     * whole reporter's block becoming {"error": ...} via ReporterPool's own error isolation,
     * not silently report "0" as if that were a real (and highly alertable) order count.
     */
    private function count(string $table, ?string $where = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        $select = $connection->select()
            ->from($tableName, ['cnt' => new Expression('COUNT(*)')]);

        if ($where !== null) {
            $select->where($where);
        }

        return (int) $connection->fetchOne($select);
    }
}
