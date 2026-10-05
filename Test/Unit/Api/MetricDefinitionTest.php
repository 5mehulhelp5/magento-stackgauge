<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\MetricDefinition;

class MetricDefinitionTest extends TestCase
{
    public function testJsonSerializeReturnsEveryDeclaredField(): void
    {
        $metric = new MetricDefinition(
            'disk.media.free_percent',
            'Disk Space: Media Free %',
            MetricDefinition::AGGREGATION_LATEST,
            MetricDefinition::OPERATOR_LT,
            10,
            15
        );

        $this->assertSame(
            [
                'metric_key' => 'disk.media.free_percent',
                'label' => 'Disk Space: Media Free %',
                'aggregation' => 'latest',
                'default_operator' => 'lt',
                'default_threshold' => 10,
                'default_window_minutes' => 15,
                'cadence' => null,
                'description' => null,
                'impact' => null,
            ],
            $metric->jsonSerialize()
        );
    }

    public function testDescriptionAndImpactSurviveWithCadenceAndSerialize(): void
    {
        $metric = (new MetricDefinition(
            'a.b',
            'A B',
            'latest',
            'gt',
            1,
            15,
            null,
            'Value {value} is over {threshold}.',
            'Customers are affected.'
        ))->withCadence('daily');

        $this->assertSame('Value {value} is over {threshold}.', $metric->getDescription());
        $this->assertSame('Customers are affected.', $metric->jsonSerialize()['impact']);
    }

    public function testWithCadenceStampsCadenceOntoTheWireRepresentation(): void
    {
        $metric = (new MetricDefinition('a.b', 'A B', 'latest', 'gt', 1, 15))->withCadence('hourly');

        $this->assertSame('hourly', $metric->getCadence());
        $this->assertSame('hourly', $metric->jsonSerialize()['cadence']);
    }

    public function testRejectsAnEmptyMetricKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('', 'Label', MetricDefinition::AGGREGATION_SUM, MetricDefinition::OPERATOR_LT, 1, 60);
    }

    public function testRejectsAnUnknownAggregation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('key', 'Label', 'median', MetricDefinition::OPERATOR_LT, 1, 60);
    }

    public function testRejectsAnUnknownDefaultOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('key', 'Label', MetricDefinition::AGGREGATION_SUM, 'between', 1, 60);
    }

    public function testRejectsANonPositiveDefaultWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MetricDefinition('key', 'Label', MetricDefinition::AGGREGATION_SUM, MetricDefinition::OPERATOR_LT, 1, 0);
    }
}
