<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Api\Field;

final class BoolField implements FieldInterface
{
    public function __construct(
        private readonly string $label,
        private readonly bool $value
    ) {
    }

    public function getType(): string
    {
        return Field::TYPE_BOOL;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): bool
    {
        return $this->value;
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
