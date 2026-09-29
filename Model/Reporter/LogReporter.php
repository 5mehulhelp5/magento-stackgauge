<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use Throwable;

/**
 * exception.log is always parsed for its own distilled "Recent exceptions" list and
 * trackable occurrence count, regardless of admin configuration - that behavior is specific
 * to this one file's content, not something an admin can switch off. Which other files get a
 * raw recent-lines tail is admin-controlled via System Configuration > Advanced > StackGauge
 * > Agent > "Monitored Log Files" (see Config::getMonitoredLogFiles()).
 *
 * Every file is read line-by-line via Filesystem\File\ReadInterface, never loaded whole into
 * memory - a real exception.log on a long-running store can reach hundreds of MB, and reading
 * it whole (plus exploding it into a line array) reliably exhausted PHP-FPM's memory_limit.
 * CLI/cron kept working throughout (unlimited memory_limit there), which is why this only
 * ever surfaced via the admin "Send Now" button.
 */
class LogReporter implements ReporterInterface, MetricCatalogInterface, DeclaresSectionInterface
{
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_EXCEPTION_COUNT = 'logs.exception_count';

    private const SYSTEM_LOG = 'system.log';
    private const EXCEPTION_LOG = 'exception.log';

    /**
     * Enough to feel like a real log tail without ballooning the payload - ArrayField's own
     * MAX_ITEMS (500) is far more than any of these files' section ever needs.
     */
    private const TAIL_LINE_COUNT = 50;

    /**
     * Generous cap for a single line (a stack trace frame, say) - bounds worst-case memory
     * for one readLine() call without truncating anything realistic.
     */
    private const MAX_LINE_LENGTH = 65536;

    /**
     * @param Filesystem $filesystem
     * @param Config $config
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Config $config,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the logs reporter.
     */
    public function getName(): string
    {
        return 'logs';
    }

    /**
     * Human-readable label for the logs reporter block.
     */
    public function getLabel(): string
    {
        return 'Logs';
    }

    /**
     * One-line summary of what the logs reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Recent log activity and exception summaries.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports system.log new-line count, exception.log's distilled summary, and a raw tail per admin-monitored file.
     */
    public function getStatus(): array
    {
        $logDir = $this->filesystem->getDirectoryRead(DirectoryList::LOG);

        $systemLines = $this->countLines($logDir, self::SYSTEM_LOG);
        $exceptionScan = $this->scanExceptionLog($logDir, self::EXCEPTION_LOG);

        $sections = [
            'general' => $this->section->facts('general', 'General', '', [
                'system_new_lines' => $this->field->number('System new lines', $systemLines),
                'exception_count' => $this->field->trackableNumber(
                    'Exception Count',
                    $exceptionScan['total'],
                    self::METRIC_EXCEPTION_COUNT,
                    MetricDefinition::AGGREGATION_DELTA
                ),
            ]),
            // No keyName override: identical exception messages recurring in the log window
            // are common and not a reporter bug, so this deliberately skips the
            // duplicate-row check (which needs every row to share the missing "name" column).
            'recent_exceptions' => $this->section->table('recent_exceptions', 'Recent exceptions', '', array_map(
                fn($m) => $this->field->array($m, [
                    'message' => $this->field->varchar('Message', $m),
                ]),
                $exceptionScan['recent']
            )),
        ];

        foreach ($this->config->getMonitoredLogFiles() as $file => $name) {
            $key = $this->tailSectionKey($file);
            // exception.log's tail was already collected above in the same streaming pass -
            // reuse it rather than streaming the same (potentially huge) file a second time.
            $lines = $file === self::EXCEPTION_LOG ? $exceptionScan['tail'] : $this->tailLines($logDir, $file);
            $sections[$key] = $this->section->table($key, $name, '', array_map(
                fn(string $line) => $this->field->array('', ['line' => $this->field->varchar('Line', $line)]),
                $lines
            ));
        }

        return $sections;
    }

    /**
     * Alertable metric for the logs reporter: new exception.log occurrences since the last sample.
     */
    public function getTrackableMetrics(): array
    {
        return [
            // exception.log is append-only between rotations, so AGGREGATION_DELTA over the
            // window yields "how many new exception occurrences" since the last sample.
            new MetricDefinition(
                self::METRIC_EXCEPTION_COUNT,
                'Logs: Exception Count',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                20,
                360
            ),
        ];
    }

    /**
     * Derives a payload section key from a monitored log file name, e.g. "system.log" -> "tail_system_log".
     *
     * @param string $file
     */
    private function tailSectionKey(string $file): string
    {
        return 'tail_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($file));
    }

    /**
     * Reads the last TAIL_LINE_COUNT non-empty lines of $file, streamed rather than loaded whole into memory.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @return list<string>
     */
    private function tailLines(ReadInterface $dir, string $file): array
    {
        $tail = [];

        $this->streamLines($dir, $file, function (string $line) use (&$tail): void {
            if ($line === '') {
                return;
            }

            $tail[] = $line;
            if (count($tail) > self::TAIL_LINE_COUNT) {
                array_shift($tail);
            }
        });

        return $tail;
    }

    /**
     * Counts the lines in $file, streamed rather than loaded whole into memory.
     *
     * @param ReadInterface $dir
     * @param string $file
     */
    private function countLines(ReadInterface $dir, string $file): int
    {
        $count = 0;

        $this->streamLines($dir, $file, static function () use (&$count): void {
            $count++;
        });

        return $count;
    }

    /**
     * One streaming pass doing all three exception.log jobs at once (total occurrence count,
     * up to 5 unique recent messages, last 50 raw lines) - the file is only ever read once
     * regardless of how many of these are needed.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @return array{total: int, recent: string[], tail: list<string>}
     */
    private function scanExceptionLog(ReadInterface $dir, string $file): array
    {
        $total = 0;
        $recent = [];
        $tail = [];

        $this->streamLines($dir, $file, function (string $line) use (&$total, &$recent, &$tail): void {
            if ($line !== '') {
                $tail[] = $line;
                if (count($tail) > self::TAIL_LINE_COUNT) {
                    array_shift($tail);
                }
            }

            if (preg_match('/(?:Exception|Error):\s*(.{5,200})/', $line, $m)) {
                $total++;
                $message = trim($m[1]);
                if (count($recent) < 5 && !in_array($message, $recent, true)) {
                    $recent[] = $message;
                }
            }
        });

        return ['total' => $total, 'recent' => $recent, 'tail' => $tail];
    }

    /**
     * Streams $file one line at a time, calling $onLine for each line with its line-ending
     * stripped. A missing file or any read error is treated the same as an empty file - this
     * reporter must never fail a whole report over one unreadable log.
     *
     * @param ReadInterface $dir
     * @param string $file
     * @param callable $onLine
     */
    private function streamLines(ReadInterface $dir, string $file, callable $onLine): void
    {
        try {
            if (!$dir->isExist($file)) {
                return;
            }

            $stream = $dir->openFile($file);

            try {
                while (!$stream->eof()) {
                    $line = $stream->readLine(self::MAX_LINE_LENGTH);
                    if ($line === false) {
                        break;
                    }

                    $onLine(rtrim($line, "\r\n"));
                }
            } finally {
                $stream->close();
            }
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (Throwable) {
            // Best-effort - an unreadable log must not fail this reporter or the whole report.
        }
    }
}
