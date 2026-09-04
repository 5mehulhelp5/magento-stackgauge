<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field;

/**
 * A short display string. Content is cleaned at construction time - strip_tags() plus
 * control-character removal, then truncated - not because this is the authoritative XSS
 * defense (that's escape-on-output on the dashboard side, always), but because stripping
 * rather than encoding here means the value stays plain text that's still safe to pass
 * through a normal auto-escaping template afterward with no double-encoding risk. A value
 * that legitimately needs a literal "<" (e.g. a raw composer constraint string like "<8.4")
 * will lose it here - none of this module's own built-in reporters need that, but a
 * third-party reporter with a genuine need for it should use a different representation
 * (e.g. two varchar fields, "operator" and "version") rather than relying on angle brackets
 * surviving this field type.
 */
final class VarcharField implements FieldInterface
{
    public const MAX_LENGTH = 500;

    private readonly string $value;

    public function __construct(
        private readonly string $label,
        string $value
    ) {
        $this->value = self::clean($value);
    }

    public function getType(): string
    {
        return Field::TYPE_VARCHAR;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    private static function clean(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->getType(), 'label' => $this->label, 'value' => $this->value];
    }
}
