<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api\Field\Concern;

use InvalidArgumentException;
use StackNuts\StackGauge\Api\Field\Field;

/**
 * Shared by every Field subclass's constructor that accepts an optional $severity - these
 * value objects are plain, DI-free classes constructed via `new`, so this lives in a trait
 * rather than as a shared instance method requiring an injected Field.
 */
trait ValidatesSeverityTrait
{
    /**
     * @param string $fieldKind Names the field type in the exception message (e.g. "Varchar", "Number").
     * @param string $label
     * @param string|null $severity
     */
    private function assertValidSeverity(string $fieldKind, string $label, ?string $severity): void
    {
        if ($severity !== null && !in_array($severity, Field::SEVERITIES, true)) {
            throw new InvalidArgumentException(
                "{$fieldKind} field \"{$label}\" has unknown severity \"{$severity}\"."
            );
        }
    }
}
