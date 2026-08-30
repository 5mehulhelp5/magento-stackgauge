<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Api;

use StackNuts\ViewGento\Api\Field\FieldInterface;

/**
 * Extension point for third-party modules to contribute a named block to the ViewGento
 * status payload without this module needing to know about the integration in advance.
 * Implement this in your own module and register it against the "reporters" array argument
 * on \StackNuts\ViewGento\Model\ReporterPool via your own di.xml - Magento merges array
 * arguments across modules, so no change to StackNuts_ViewGento is needed.
 *
 * getStatus() must return every value wrapped in a typed Field (see Api\Field\Field) -
 * Field::bool(), Field::varchar(), Field::number(), or Field::array() of more Fields. This
 * isn't just a style preference: a dashboard consumer can render any field correctly
 * without knowing this reporter's shape in advance, because the field itself says what it
 * is; each Field type validates and/or cleans its own value at construction time (length
 * caps, tag-stripping, finite-number checks, nesting-depth limits - see each class); and it
 * gives every reporter, built-in or third-party, the same shape discipline rather than
 * relying on a docblock nobody enforces. A reporter that returns anything other than a
 * Field (or throws while building one) has its whole block replaced with an
 * {"error": ...} marker by ReporterPool - exactly like a reporter that throws for any other
 * reason - so a bad reporter degrades gracefully rather than corrupting the payload.
 *
 * Do not use the keys "schema_version", "label", or "description" in the array returned by
 * getStatus() - ReporterPool wraps the array under those reserved keys itself.
 */
interface ReporterInterface
{
    /**
     * Payload key this reporter contributes under, e.g. "cloudflare". Must be unique across
     * every registered reporter.
     */
    public function getName(): string;

    /**
     * Short human-readable name for this reporter, e.g. "Redis" - shown as a heading on the
     * dashboard so a third-party reporter's block is self-explanatory, not just a raw key.
     */
    public function getLabel(): string;

    /**
     * One or two sentences on what this reporter covers, e.g. "Reachability and version of
     * Redis-backed cache and session backends." Shown alongside getLabel() on the dashboard.
     */
    public function getDescription(): string;

    /**
     * This reporter's own schema version, opaque to ViewGento - only the dashboard
     * interprets it. Lets a third-party reporter evolve its own shape independently of the
     * core module's payload schema_version. Bump this whenever a field's name, type, or
     * meaning changes - self-describing Fields mean the dashboard's generic rendering
     * doesn't need this to display an unfamiliar field safely, but any version-aware logic
     * (a specific hand-built view, a future health-rollup rule) still does.
     */
    public function getSchemaVersion(): string;

    /**
     * @return array<string, FieldInterface>
     */
    public function getStatus(): array;
}
