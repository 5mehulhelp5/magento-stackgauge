<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

use InvalidArgumentException;
use StackNuts\StackGauge\Api\Field\Concern\ValidatesSeverityTrait;

/**
 * $severity is one of Field::SEVERITY_* - the reporter's own pre-graduated ok/warning/
 * critical conclusion for this number, using whatever thresholds only the reporter knows
 * are meaningful. Left null (the default) for a number with no health meaning of its own,
 * which the dashboard renders as plain text.
 */
class NumberField implements FieldInterface
{
    use ValidatesSeverityTrait;

    /**
     * @var int|float
     */
    private readonly int|float $value;

    /**
     * @param string $label
     * @param int|float $value
     * @param string|null $severity See this class's own docblock for what this declares.
     */
    public function __construct(
        private readonly string $label,
        int|float $value,
        private readonly ?string $severity = null
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException("Number field \"{$label}\" must be finite.");
        }

        $this->assertValidSeverity('Number', $label, $severity);

        $this->value = $value;
    }

    /**
     * Always Field::TYPE_NUMBER.
     */
    public function getType(): string
    {
        return Field::TYPE_NUMBER;
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
    public function getValue(): int|float
    {
        return $this->value;
    }

    /**
     * Wire representation of this field.
     *
     * @return array{type: string, label: string, value: int|float, severity?: string}
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];

        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }

        return $data;
    }
}
