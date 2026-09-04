<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * A point in time, always UTC, always "Y-m-d H:i:s" (or empty string for "never happened") -
 * the same raw shape as everywhere else in this module a timestamp already flows (e.g.
 * cron_schedule's own columns). Locale-specific display formatting (the dashboard renders
 * these UK-style) is deliberately a dashboard-side concern, not baked in here, the same way
 * BoolField's critical_when is a display hint rather than the value itself changing shape.
 */
final class DateTimeField implements FieldInterface
{
    public function __construct(
        private readonly string $label,
        private readonly string $value
    ) {
    }

    public function getType(): string
    {
        return Field::TYPE_DATETIME;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
