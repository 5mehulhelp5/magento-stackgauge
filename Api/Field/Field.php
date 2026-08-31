<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Api\Field;

/**
 * Entry point for building a reporter's status fields - construct instances via these
 * factories, not the concrete Field classes directly:
 *
 *     Field::bool('Cron Alive', true)
 *     Field::varchar('Magento Version', '2.4.9')
 *     Field::number('Free Disk (%)', 47.9)
 *     Field::array('Modules', [
 *         Field::array('', [
 *             'name' => Field::varchar('Name', 'Magento_Catalog'),
 *             'version' => Field::varchar('Version', '103.0.5'),
 *             'enabled' => Field::bool('Enabled', true),
 *         ]),
 *     ])
 *
 * Every field type validates and/or cleans its value at construction time (see each
 * class's own docblock) - a reporter that passes something invalid gets a thrown
 * InvalidArgumentException, which ReporterPool catches the same way it catches any other
 * reporter failure.
 */
final class Field
{
    public const TYPE_BOOL = 'bool';
    public const TYPE_VARCHAR = 'varchar';
    public const TYPE_NUMBER = 'number';
    public const TYPE_ARRAY = 'array';

    public static function bool(string $label, bool $value): BoolField
    {
        return new BoolField($label, $value);
    }

    public static function varchar(string $label, string $value): VarcharField
    {
        return new VarcharField($label, $value);
    }

    public static function number(string $label, int|float $value): NumberField
    {
        return new NumberField($label, $value);
    }

    /**
     * A NumberField also tracked over time for alerting - see TrackableNumberField and
     * Api\MetricCatalogInterface. $metricKey should match a MetricDefinition this reporter
     * declares via getTrackableMetrics(); $aggregation is how the dashboard combines
     * multiple samples of this metric over a time window (one of
     * MetricDefinition::AGGREGATION_*).
     */
    public static function trackableNumber(
        string $label,
        int|float $value,
        string $metricKey,
        string $aggregation
    ): TrackableNumberField {
        return new TrackableNumberField($label, $value, $metricKey, $aggregation);
    }

    /**
     * @param array<int|string, FieldInterface> $value
     */
    public static function array(string $label, array $value): ArrayField
    {
        return new ArrayField($label, $value);
    }
}
