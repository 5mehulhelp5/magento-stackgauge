<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * A point in time, always UTC, always "Y-m-d H:i:s" (or empty string for "never happened").
 * Locale-specific display formatting is a dashboard-side concern, not handled here.
 */
class DateTimeField implements FieldInterface
{
    /**
     * @param string $label
     * @param string $value UTC "Y-m-d H:i:s", or '' for "never".
     */
    public function __construct(
        private readonly string $label,
        private readonly string $value
    ) {
    }

    /**
     * Always Field::TYPE_DATETIME.
     */
    public function getType(): string
    {
        return Field::TYPE_DATETIME;
    }

    /**
     * The label passed to the constructor.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The value passed to the constructor.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Wire representation of this field.
     *
     * @return array{type: string, label: string, value: string}
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
