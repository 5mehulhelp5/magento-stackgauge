<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

/**
 * Summarizes recent var/report exception dumps (short message + class).
 */
final class ReportReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function getName(): string
    {
        return 'reports';
    }

    public function getLabel(): string
    {
        return 'Reports';
    }

    public function getDescription(): string
    {
        return 'Recent var/report summaries.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $varDir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        try {
            if (! $varDir->isExist('report')) {
                return ['reports' => Field::array('Reports', [])];
            }

            $files = $varDir->read('report') ?? [];
        } catch (\Throwable) {
            return ['reports' => Field::array('Reports', [])];
        }

        $recent = [];
        $count = 0;
        foreach ($files as $file) {
            if ($count >= 5) {
                break;
            }

            try {
                $content = $varDir->readFile('report/' . $file);
            } catch (\Throwable) {
                continue;
            }

            $message = $this->extractMessage($content);
            $recent[] = Field::array('', [
                'file' => Field::varchar('File', (string) $file),
                'message' => Field::varchar('Message', $message),
            ]);

            $count++;
        }

        return ['reports' => Field::array('Reports', $recent)];
    }

    private function extractMessage(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        // Try a simple parse for "Exception: message" patterns
        if (preg_match('/(?:Exception|Error):\s*(.{1,300})/m', $content, $m)) {
            return trim($m[1]);
        }

        return mb_substr($content, 0, 200);
    }
}
