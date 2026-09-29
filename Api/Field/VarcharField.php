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
 * A short display string. Content is cleaned at construction time - strip_tags() plus
 * control-character removal, then truncated. This is not an XSS defense (escape-on-output
 * on the dashboard side is); it just means a literal "<" (e.g. a raw composer constraint
 * like "<8.4") won't survive - use a different representation (e.g. separate "operator" and
 * "version" fields) if a value genuinely needs one.
 *
 * Two mutually exclusive, optional coloring hints:
 *
 *  - $criticalValues: a finite set of exact values that count as "something's wrong" (e.g.
 *    a status field where "Suspended" is critical but "Ready" isn't). For enum-shaped values.
 *  - $severity: one of Field::SEVERITY_* directly, for a value not drawn from a small fixed
 *    set where the reporter has already graduated it into ok/warning/critical itself.
 *
 * Both left null (the default) for a varchar with no health meaning of its own.
 */
class VarcharField implements FieldInterface
{
    use ValidatesSeverityTrait;

    public const MAX_LENGTH = 500;

    /**
     * @var string
     */
    private readonly string $value;

    /**
     * @param string $label
     * @param string $value
     * @param list<string>|null $criticalValues
     * @param string|null $severity
     */
    public function __construct(
        private readonly string $label,
        string $value,
        private readonly ?array $criticalValues = null,
        private readonly ?string $severity = null
    ) {
        if ($criticalValues !== null && $severity !== null) {
            throw new InvalidArgumentException(
                "Varchar field \"{$label}\" cannot set both \$criticalValues and \$severity."
            );
        }

        $this->assertValidSeverity('Varchar', $label, $severity);

        $this->value = $this->clean($value);
    }

    /**
     * Always Field::TYPE_VARCHAR.
     */
    public function getType(): string
    {
        return Field::TYPE_VARCHAR;
    }

    /**
     * The label passed to the constructor.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The cleaned value - see clean().
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Strips tags and control characters, then truncates to MAX_LENGTH.
     *
     * See this class's own docblock for why this isn't an XSS defense.
     *
     * @param string $value
     */
    private function clean(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            // mb_convert_encoding(..., 'UTF-8', 'UTF-8') scrubs invalid byte sequences rather
            // than throwing. mb_scrub() is more explicit but needs PHP 8.2+, and this module
            // still supports 8.1 (see composer.json) - don't switch to it without dropping 8.1.
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    /**
     * Wire representation of this field.
     *
     * @return array{type: string, label: string, value: string, critical_values?: list<string>, severity?: string}
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];

        if ($this->criticalValues !== null) {
            $data['critical_values'] = $this->criticalValues;
        }

        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }

        return $data;
    }
}
