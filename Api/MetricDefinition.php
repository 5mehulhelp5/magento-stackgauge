<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Declares one trackable metric a reporter exposes: a stable machine key, a human label, an
 * aggregation for combining samples over a time window, and a suggested starting alert rule
 * (default operator/threshold/window). This is a proposal, not a permanent setting: the
 * dashboard seeds a new alert rule from these defaults the first time it sees the metric via
 * config-sync, and only ever reapplies them via an explicit "reset to default" action - an
 * edited threshold survives every subsequent sync. See Api\MetricCatalogInterface.
 */
class MetricDefinition implements JsonSerializable
{
    public const AGGREGATION_SUM = 'sum';
    public const AGGREGATION_AVG = 'avg';
    public const AGGREGATION_LATEST = 'latest';
    public const AGGREGATION_MIN = 'min';
    public const AGGREGATION_MAX = 'max';

    /**
     * max(value) - min(value) over the window - "how much did this monotonically increasing
     * counter grow" (e.g. lifetime order count), without needing a calendar-boundary concept
     * like "since midnight". For a non-decreasing series this equals latest - earliest.
     */
    public const AGGREGATION_DELTA = 'delta';

    public const OPERATOR_LT = 'lt';
    public const OPERATOR_LTE = 'lte';
    public const OPERATOR_GT = 'gt';
    public const OPERATOR_GTE = 'gte';
    public const OPERATOR_EQ = 'eq';

    private const VALID_AGGREGATIONS = [
        self::AGGREGATION_SUM,
        self::AGGREGATION_AVG,
        self::AGGREGATION_LATEST,
        self::AGGREGATION_MIN,
        self::AGGREGATION_MAX,
        self::AGGREGATION_DELTA,
    ];

    private const VALID_OPERATORS = [
        self::OPERATOR_LT,
        self::OPERATOR_LTE,
        self::OPERATOR_GT,
        self::OPERATOR_GTE,
        self::OPERATOR_EQ,
    ];

    /**
     * @param string $metricKey Stable machine key, matched against Field::trackableNumber()'s metricKey.
     * @param string $label
     * @param string $aggregation One of self::AGGREGATION_*.
     * @param string $defaultOperator One of self::OPERATOR_*.
     * @param int|float $defaultThreshold
     * @param int $defaultWindowMinutes
     * @param string|null $cadence One of DeclaresCadenceInterface::CADENCE_*; stamped by MetricCatalogPool from the
     *     owning reporter, so reporter authors never set it. The dashboard uses it so the evaluation window
     *     never drops below the interval the metric is actually reported at.
     * @param string|null $description Plain-language "what happened" sentence for alert notifications. May use
     *     the placeholders {value}, {threshold} and {window}, filled in by the dashboard from the live rule.
     * @param string|null $impact Plain-language "why this matters" sentence; same placeholders as $description.
     */
    public function __construct(
        private readonly string $metricKey,
        private readonly string $label,
        private readonly string $aggregation,
        private readonly string $defaultOperator,
        private readonly int|float $defaultThreshold,
        private readonly int $defaultWindowMinutes,
        private readonly ?string $cadence = null,
        private readonly ?string $description = null,
        private readonly ?string $impact = null
    ) {
        if ($metricKey === '') {
            throw new InvalidArgumentException('Metric key must not be empty.');
        }

        if (!in_array($aggregation, self::VALID_AGGREGATIONS, true)) {
            throw new InvalidArgumentException("Metric \"{$metricKey}\" has unknown aggregation \"{$aggregation}\".");
        }

        if (!in_array($defaultOperator, self::VALID_OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Metric \"{$metricKey}\" has unknown default operator \"{$defaultOperator}\"."
            );
        }

        if ($defaultWindowMinutes <= 0) {
            throw new InvalidArgumentException(
                "Metric \"{$metricKey}\" default window must be a positive number of minutes."
            );
        }
    }

    /**
     * The metric_key passed to the constructor.
     */
    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    /**
     * The label passed to the constructor.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The aggregation passed to the constructor.
     */
    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    /**
     * The default operator passed to the constructor.
     */
    public function getDefaultOperator(): string
    {
        return $this->defaultOperator;
    }

    /**
     * The default threshold passed to the constructor.
     */
    public function getDefaultThreshold(): int|float
    {
        return $this->defaultThreshold;
    }

    /**
     * The default window (in minutes) passed to the constructor.
     */
    public function getDefaultWindowMinutes(): int
    {
        return $this->defaultWindowMinutes;
    }

    /**
     * The owning reporter's cadence, or null if not yet stamped by MetricCatalogPool.
     */
    public function getCadence(): ?string
    {
        return $this->cadence;
    }

    /**
     * The description passed to the constructor, with its placeholders unexpanded.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * The impact passed to the constructor, with its placeholders unexpanded.
     */
    public function getImpact(): ?string
    {
        return $this->impact;
    }

    /**
     * Copy of this definition tagged with the owning reporter's cadence.
     */
    public function withCadence(string $cadence): self
    {
        return new self(
            $this->metricKey,
            $this->label,
            $this->aggregation,
            $this->defaultOperator,
            $this->defaultThreshold,
            $this->defaultWindowMinutes,
            $cadence,
            $this->description,
            $this->impact
        );
    }

    /**
     * Wire representation of this metric definition.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'metric_key' => $this->metricKey,
            'label' => $this->label,
            'aggregation' => $this->aggregation,
            'default_operator' => $this->defaultOperator,
            'default_threshold' => $this->defaultThreshold,
            'default_window_minutes' => $this->defaultWindowMinutes,
            'cadence' => $this->cadence,
            'description' => $this->description,
            'impact' => $this->impact,
        ];
    }
}
