<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Api\Field;

use InvalidArgumentException;

/**
 * A NumberField whose value is tracked over time for alerting - carries a stable metric_key
 * (matching a MetricDefinition declared via Api\MetricCatalogInterface) and the aggregation
 * used to combine multiple samples of it over a time window. Additive to the wire shape:
 * getType() still returns "number", so anything only reading the base four Field types
 * renders this correctly with no changes; metric_key/aggregation are extra keys a consumer
 * that cares about alerting can look for and everyone else ignores. Can't extend NumberField
 * directly since that class is final - implements FieldInterface itself instead.
 */
final class TrackableNumberField implements FieldInterface
{
    private readonly int|float $value;

    public function __construct(
        private readonly string $label,
        int|float $value,
        private readonly string $metricKey,
        private readonly string $aggregation
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" must be finite.");
        }

        if ($metricKey === '') {
            throw new InvalidArgumentException("Trackable number field \"{$label}\" needs a non-empty metric_key.");
        }

        $this->value = $value;
    }

    public function getType(): string
    {
        return Field::TYPE_NUMBER;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): int|float
    {
        return $this->value;
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->getType(),
            'label' => $this->label,
            'value' => $this->value,
            'metric_key' => $this->metricKey,
            'aggregation' => $this->aggregation,
        ];
    }
}
