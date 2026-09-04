<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Shell;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use Throwable;

/**
 * Applied Adobe Quality Patches, via the official `vendor/bin/patch-status` CLI from the
 * magento/quality-patches package. Reports "detectable: false" rather than guessing when the
 * binary isn't present - Cloud Patches applied via composer aren't covered by this tool at
 * all, and QPT itself is only installed on stores that pulled it in.
 */
class PatchReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '3.0';
    private const MAX_OUTPUT_LINES = 30;
    private const MAX_LINE_LENGTH = 120;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Shell $shell,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'patches';
    }

    public function getLabel(): string
    {
        return 'Patches';
    }

    public function getDescription(): string
    {
        return "Applied Adobe Quality Patches, via vendor/bin/patch-status when it's present.";
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getCadence(): string
    {
        return self::CADENCE_DAILY;
    }

    public function getStatus(): array
    {
        $binary = rtrim($this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath(), '/')
            . '/vendor/bin/patch-status';

        if (!is_file($binary) || !is_executable($binary)) {
            return $this->result(false, []);
        }

        try {
            $output = $this->shell->execute($binary);
        } catch (Throwable $e) {
            $this->logger->warning('StackGauge: vendor/bin/patch-status execution failed: ' . $e->getMessage());

            return $this->result(false, []);
        }

        $trimmedOutput = trim($output);

        // Some environments' patch-status build (or a wrapping shell alias) emits a single
        // pretty-printed JSON blob instead of the plain per-patch text table this reporter
        // expects - splitting that by line would produce one fake "patch" row per JSON line.
        // Surface it as one opaque field instead of fabricating a patch list out of it.
        if ($trimmedOutput !== '' && ($trimmedOutput[0] === '{' || $trimmedOutput[0] === '[')) {
            return $this->result(true, [], substr($trimmedOutput, 0, self::MAX_LINE_LENGTH * self::MAX_OUTPUT_LINES));
        }

        $lines = array_slice(
            array_values(array_filter(array_map('trim', explode("\n", $output)))),
            0,
            self::MAX_OUTPUT_LINES
        );

        return $this->result(true, array_map(
            static fn (string $line) => Field::array($line, [
                'line' => Field::varchar('Line', substr($line, 0, self::MAX_LINE_LENGTH)),
            ]),
            $lines
        ));
    }

    /**
     * @param list<\StackNuts\StackGauge\Api\Field\ArrayField> $rawOutputRows
     * @return array<string, \StackNuts\StackGauge\Api\Section\SectionInterface>
     */
    private function result(bool $detectable, array $rawOutputRows, ?string $unrecognizedJsonOutput = null): array
    {
        $generalFields = ['detectable' => Field::bool('Detectable', $detectable)];
        if ($unrecognizedJsonOutput !== null) {
            $generalFields['unrecognized_json_output'] = Field::varchar('Unrecognized JSON Output', $unrecognizedJsonOutput);
        }

        return [
            'general' => Section::facts('general', 'General', '', $generalFields),
            'raw_output' => Section::table('raw_output', 'Output', '', $rawOutputRows),
        ];
    }
}
