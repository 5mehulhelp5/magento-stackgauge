<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * $criticalWhen declares which value (true or false) is the "something's wrong" state, e.g.
 * false for "Alive"/"Reachable", true for "Maintenance Mode"/"Sample Data Present" - a plain
 * Yes/No can't be colored consistently otherwise, since some bools are good-when-true and
 * others are good-when-false. Left null (the default) for fields with no health meaning at
 * all (e.g. a module's "Enabled" flag), which the dashboard renders as a neutral badge.
 */
class BoolField implements FieldInterface
{
    /**
     * @param string $label
     * @param bool $value
     * @param bool|null $criticalWhen See this class's own docblock for what this declares.
     */
    public function __construct(
        private readonly string $label,
        private readonly bool $value,
        private readonly ?bool $criticalWhen = null
    ) {
    }

    /**
     * Always Field::TYPE_BOOL.
     */
    public function getType(): string
    {
        return Field::TYPE_BOOL;
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
    public function getValue(): bool
    {
        return $this->value;
    }

    /**
     * Wire representation of this field.
     *
     * @return array{type: string, label: string, value: bool, critical_when?: bool}
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];

        if ($this->criticalWhen !== null) {
            $data['critical_when'] = $this->criticalWhen;
        }

        return $data;
    }
}
