<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;

final class LogReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '2.0';

    private const SYSTEM_LOG = 'system.log';
    private const EXCEPTION_LOG = 'exception.log';

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function getName(): string
    {
        return 'logs';
    }

    public function getLabel(): string
    {
        return 'Logs';
    }

    public function getDescription(): string
    {
        return 'Recent log activity and exception summaries.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $logDir = $this->filesystem->getDirectoryRead(DirectoryList::LOG);

        $systemLines = $this->readLineCount($logDir, self::SYSTEM_LOG);
        $exceptionChunk = $this->readFileContents($logDir, self::EXCEPTION_LOG);

        $recentExceptions = $this->extractExceptionMessages($exceptionChunk);

        return [
            'general' => Section::facts('general', 'General', '', [
                'system_new_lines' => Field::number('System new lines', $systemLines),
            ]),
            // No keyName override: identical exception messages recurring in the log window
            // are common and not a reporter bug, so this deliberately skips the
            // duplicate-row check (which needs every row to share the missing "name" column).
            'recent_exceptions' => Section::table('recent_exceptions', 'Recent exceptions', '', array_map(
                fn($m) => Field::array($m, [
                    'message' => Field::varchar('Message', $m),
                ]),
                $recentExceptions
            )),
        ];
    }

    private function readLineCount($dir, string $file): int
    {
        try {
            if (! $dir->isExist($file)) {
                return 0;
            }

            $content = $dir->readFile($file);
            if (! is_string($content) || $content === '') {
                return 0;
            }

            return substr_count($content, "\n");
        } catch (\Throwable) {
            return 0;
        }
    }

    private function readFileContents($dir, string $file): string
    {
        try {
            if (! $dir->isExist($file)) {
                return '';
            }

            $content = $dir->readFile($file);
            return is_string($content) ? $content : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return string[] */
    private function extractExceptionMessages(string $chunk): array
    {
        if ($chunk === '') {
            return [];
        }

        if (preg_match_all('/(?:Exception|Error):\s*(.{5,200})/m', $chunk, $matches)) {
            $msgs = array_map('trim', $matches[1]);
            $unique = array_values(array_slice(array_unique($msgs), 0, 5));
            return $unique;
        }

        return [];
    }
}
