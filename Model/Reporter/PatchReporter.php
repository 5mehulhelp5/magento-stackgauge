<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Shell;
use Psr\Log\LoggerInterface;
use StackNuts\ViewGento\Api\DeclaresCadenceInterface;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Applied Adobe Quality Patches, via the official `vendor/bin/patch-status` CLI from the
 * magento/quality-patches package - not a proprietary technique, just Adobe's own supported
 * tool. Reports "detectable: false" rather than guessing when the binary isn't present
 * (Cloud Patches applied via composer patches aren't covered by this tool at all, and QPT
 * itself is only installed on stores that pulled it in) - matches the doc's original "if
 * detectable" hedge rather than overstating confidence.
 */
class PatchReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '2.0';
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
            return ['detectable' => Field::bool('Detectable', false), 'raw_output' => Field::array('Output', [])];
        }

        try {
            $output = $this->shell->execute($binary);
        } catch (Throwable $e) {
            $this->logger->warning('ViewGento: vendor/bin/patch-status execution failed: ' . $e->getMessage());

            return ['detectable' => Field::bool('Detectable', false), 'raw_output' => Field::array('Output', [])];
        }

        $lines = array_slice(
            array_values(array_filter(array_map('trim', explode("\n", $output)))),
            0,
            self::MAX_OUTPUT_LINES
        );

        return [
            'detectable' => Field::bool('Detectable', true),
            'raw_output' => Field::array('Output', array_map(
                static fn (string $line): \StackNuts\ViewGento\Api\Field\VarcharField => Field::varchar(
                    '',
                    substr($line, 0, self::MAX_LINE_LENGTH)
                ),
                $lines
            )),
        ];
    }
}
