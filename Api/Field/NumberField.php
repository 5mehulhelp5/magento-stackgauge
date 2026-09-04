<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;

final class NumberField implements FieldInterface
{
    private readonly int|float $value;

    public function __construct(
        private readonly string $label,
        int|float $value
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("Number field \"{$label}\" must be finite.");
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

    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
