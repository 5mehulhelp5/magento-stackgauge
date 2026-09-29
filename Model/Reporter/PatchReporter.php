<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Shell;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Section\SectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use Throwable;

/**
 * Applied official Adobe Commerce security patches, via `vendor/bin/patch-status` - a tool
 * bundled with Adobe Commerce itself, not the separate `magento/quality-patches` package
 * (which applies arbitrary bug-fix patches, unrelated to security). Reports "detectable:
 * false" rather than guessing when the binary isn't present. --format=json is passed
 * explicitly since the tool's own output format isn't guaranteed stable, and every field from
 * the decoded payload is read defensively (missing/wrong-typed keys are skipped, not fatal)
 * in case a future release reshapes it.
 */
class PatchReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const MAX_UNRECOGNIZED_OUTPUT_LENGTH = 3600;

    /**
     * @param Filesystem $filesystem
     * @param File $filesystemDriver
     * @param Shell $shell
     * @param Json $json
     * @param LoggerInterface $logger
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly File $filesystemDriver,
        private readonly Shell $shell,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the patches reporter.
     */
    public function getName(): string
    {
        return 'patches';
    }

    /**
     * Human-readable label for the patches reporter block.
     */
    public function getLabel(): string
    {
        return 'Patches';
    }

    /**
     * One-line summary of what the patches reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return "Applied official Adobe Commerce security patches, via vendor/bin/patch-status when it's present.";
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Runs vendor/bin/patch-status --format=json and reports its decoded output.
     *
     * Reports "detectable: false" instead if the binary is missing or its output can't be parsed.
     */
    public function getStatus(): array
    {
        $binary = rtrim($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath(), '/')
            . '/vendor/bin/patch-status';

        // is_executable() has no Magento\Framework\Filesystem\DriverInterface equivalent -
        // the driver abstraction only wraps read/write/stat operations, not exec-bit checks.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        if (!$this->filesystemDriver->isFile($binary) || !is_executable($binary)) {
            return $this->result(false);
        }

        try {
            $output = $this->shell->execute($binary . ' %s', ['--format=json']);
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: vendor/bin/patch-status execution failed: ' . $e->getMessage());

            return $this->result(false);
        }

        try {
            $data = $this->json->unserialize($output);
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: vendor/bin/patch-status returned unparseable JSON: ' . $e->getMessage());
            $data = null;
        }

        if (!is_array($data)) {
            return $this->result(true, substr(trim($output), 0, self::MAX_UNRECOGNIZED_OUTPUT_LENGTH));
        }

        return $this->result(true, null, $data);
    }

    /**
     * Assembles the getStatus() payload from patch-status's decoded output (or lack of one).
     *
     * @param bool $detectable
     * @param string|null $unrecognizedOutput
     * @param array<string,mixed>|null $data
     * @return array<string, SectionInterface>
     */
    private function result(bool $detectable, ?string $unrecognizedOutput = null, ?array $data = null): array
    {
        $generalFields = ['detectable' => $this->field->bool('Detectable', $detectable)];

        if (is_string($data['base_version'] ?? null)) {
            $generalFields['base_version'] = $this->field->varchar('Base Version', $data['base_version']);
        }

        if (is_string($data['registry_source'] ?? null)) {
            $generalFields['registry_source'] = $this->field->varchar('Registry Source', $data['registry_source']);
        }

        if ($unrecognizedOutput !== null) {
            $generalFields['unrecognized_output'] = $this->field->varchar('Unrecognized Output', $unrecognizedOutput);
        }

        return [
            'general' => $this->section->facts('general', 'General', '', $generalFields),
            'installed_components' => $this->section->table(
                'installed_components',
                'Installed Components',
                'Component versions reported by patch-status.',
                $this->componentRows(is_array($data['installed_components'] ?? null) ? $data['installed_components'] : []),
                keyName: 'component'
            ),
            'applied_patches' => $this->section->table(
                'applied_patches',
                'Applied Patches',
                'Patches patch-status confirms are applied.',
                $this->patchIdRows(is_array($data['applied_patches'] ?? null) ? $data['applied_patches'] : []),
                keyName: 'patch_id'
            ),
            'missing_patches' => $this->section->table(
                'missing_patches',
                'Missing Patches',
                'Patches patch-status expects but did not find applied.',
                $this->patchIdRows(is_array($data['missing_patches'] ?? null) ? $data['missing_patches'] : []),
                keyName: 'patch_id'
            ),
            'unknown_patches' => $this->section->table(
                'unknown_patches',
                'Unknown Patches',
                'Applied patches patch-status does not recognize.',
                $this->patchIdRows(is_array($data['unknown_patches'] ?? null) ? $data['unknown_patches'] : []),
                keyName: 'patch_id'
            ),
            'vulnerability_status' => $this->section->table(
                'vulnerability_status',
                'Vulnerability Status',
                'Per-CVE protection status derived from applied/missing patches.',
                $this->vulnerabilityRows(
                    is_array($data['vulnerability_status'] ?? null) ? $data['vulnerability_status'] : []
                ),
                keyName: 'cve'
            ),
            'warnings' => $this->section->table(
                'warnings',
                'Warnings',
                'Warnings emitted by patch-status itself (e.g. registry fetch or auth issues).',
                $this->warningRows(is_array($data['warnings'] ?? null) ? $data['warnings'] : [])
            ),
        ];
    }

    /**
     * Builds one row per installed component reported by patch-status.
     *
     * @param array<mixed,mixed> $components
     * @return list<ArrayField>
     */
    private function componentRows(array $components): array
    {
        $rows = [];

        foreach ($components as $component => $version) {
            if (!is_string($component) || $component === '' || !is_scalar($version)) {
                continue;
            }

            $rows[] = $this->field->array($component, [
                'component' => $this->field->varchar('Component', $component),
                'version' => $this->field->varchar('Version', (string)$version),
            ]);
        }

        return $rows;
    }

    /**
     * Builds one deduplicated row per patch ID reported by patch-status.
     *
     * @param array<mixed,mixed> $patchIds
     * @return list<ArrayField>
     */
    private function patchIdRows(array $patchIds): array
    {
        $rows = [];

        foreach ($patchIds as $patchId) {
            if (!is_string($patchId) || $patchId === '' || isset($rows[$patchId])) {
                continue;
            }

            $rows[$patchId] = $this->field->array($patchId, [
                'patch_id' => $this->field->varchar('Patch ID', $patchId),
            ]);
        }

        return array_values($rows);
    }

    /**
     * Builds one row per CVE with its protection status, reported by patch-status.
     *
     * @param array<mixed,mixed> $statuses
     * @return list<ArrayField>
     */
    private function vulnerabilityRows(array $statuses): array
    {
        $rows = [];

        foreach ($statuses as $cve => $entry) {
            $status = is_array($entry) ? ($entry['status'] ?? null) : $entry;

            if (!is_string($cve) || $cve === '' || !is_string($status) || $status === '') {
                continue;
            }

            $rows[] = $this->field->array($cve, [
                'cve' => $this->field->varchar('CVE', $cve),
                'status' => $this->field->varchar('Status', $status, criticalValues: ['VULNERABLE']),
            ]);
        }

        return $rows;
    }

    /**
     * Builds one row per warning message emitted by patch-status itself.
     *
     * @param array<mixed,mixed> $warnings
     * @return list<ArrayField>
     */
    private function warningRows(array $warnings): array
    {
        $rows = [];

        foreach ($warnings as $warning) {
            if (!is_string($warning) || $warning === '') {
                continue;
            }

            $rows[] = $this->field->array($warning, [
                'message' => $this->field->varchar('Message', $warning),
            ]);
        }

        return $rows;
    }
}
