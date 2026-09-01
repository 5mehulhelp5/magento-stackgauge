<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

/**
 * Aggregated hourly order counts and revenue for a recent window.
 */
final class OrderStatsReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    private const HOURS = 24;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getName(): string
    {
        return 'order_stats';
    }

    public function getLabel(): string
    {
        return 'Order Stats';
    }

    public function getDescription(): string
    {
        return 'Hourly order counts and revenue totals for the recent window.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $windowStart = $now->modify(sprintf('-%d hours', self::HOURS));

        $select = $connection->select()
            ->from($table, [
                'hour_bucket' => new Expression("DATE_FORMAT(created_at, '%Y-%m-%dT%H:00:00+00:00')"),
                'cnt' => new Expression('COUNT(*)'),
                'revenue' => new Expression('SUM(base_grand_total)'),
            ])
            ->where('created_at >= ?', $windowStart->format('Y-m-d H:i:s'))
            ->group('hour_bucket');

        $rows = $connection->fetchAll($select);

        // Prepare empty buckets
        $buckets = [];
        $cursor = $windowStart;
        while ($cursor <= $now) {
            $buckets[$cursor->format('Y-m-d\TH:00:00+00:00')] = ['hour' => $cursor->format('Y-m-d\TH:00:00+00:00'), 'count' => 0, 'revenue' => 0.0];
            $cursor = $cursor->modify('+1 hour');
        }

        foreach ($rows as $row) {
            $key = (string) $row['hour_bucket'];
            if (isset($buckets[$key])) {
                $buckets[$key]['count'] = (int) $row['cnt'];
                $buckets[$key]['revenue'] = round((float) $row['revenue'], 2);
            }
        }

        // Return as array of Field::array entries
        $fields = [];
        foreach (array_values($buckets) as $bucket) {
            $fields[] = Field::array('', [
                'hour' => Field::varchar('Hour', $bucket['hour']),
                'count' => Field::number('Count', $bucket['count']),
                'revenue' => Field::number('Revenue', $bucket['revenue']),
            ]);
        }

        return ['orders_hourly' => Field::array('Orders hourly', $fields)];
    }
}
