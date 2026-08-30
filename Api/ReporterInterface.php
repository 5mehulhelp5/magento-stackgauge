<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Api;

/**
 * Extension point for third-party modules to contribute a named block to the ViewGento
 * status payload without this module needing to know about the integration in advance.
 * Implement this in your own module and register it against the "reporters" array argument
 * on \StackNuts\ViewGento\Model\ReporterPool via your own di.xml - Magento merges array
 * arguments across modules, so no change to StackNuts_ViewGento is needed.
 *
 * getStatus() must return a plain array of JSON-safe values only (strings, ints, floats,
 * bools, null, and nested arrays of those) - no objects, resources, or closures. Do not
 * include a "schema_version" key: ReporterPool injects getSchemaVersion() at that key
 * itself, so it's reserved. Prefer ISO-8601 strings for timestamps. If there's nothing to
 * report yet, return a small neutral array (e.g. ['status' => 'idle']) rather than
 * throwing - a thrown exception is treated as a failure and replaces the whole block with
 * an error marker, dropping this cycle's data. Keep blocks small: this rides an hourly/
 * 5-minute report, not a bulk export - no raw file contents, no PII, no binary blobs.
 */
interface ReporterInterface
{
    /**
     * Payload key this reporter contributes under, e.g. "cloudflare". Must be unique across
     * every registered reporter.
     */
    public function getName(): string;

    /**
     * This reporter's own schema version, opaque to ViewGento - only the dashboard
     * interprets it. Lets a third-party reporter evolve its own shape independently of the
     * core module's payload schema_version.
     */
    public function getSchemaVersion(): string;

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array;
}
