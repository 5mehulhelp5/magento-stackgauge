<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Reporter\LogReporter;

class LogReporterTest extends TestCase
{
    /**
     * @param array<string, string> $contentsByFile
     * @param array<string, string> $monitoredLogFiles filename => display name
     */
    private function reporter(array $contentsByFile, array $monitoredLogFiles = []): LogReporter
    {
        $filesystem = $this->createMock(Filesystem::class);
        $logDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($logDir);

        $logDir->method('isExist')->willReturnCallback(
            fn (string $file) => isset($contentsByFile[$file])
        );
        // openFile(), not readFile() - LogReporter streams line-by-line so it never loads a
        // whole (potentially huge) log file into memory. See FakeLineStream below.
        $logDir->method('openFile')->willReturnCallback(
            fn (string $file) => new FakeLineStream($contentsByFile[$file] ?? '')
        );

        $config = $this->createMock(Config::class);
        $config->method('getMonitoredLogFiles')->willReturn($monitoredLogFiles);

        return new LogReporter($filesystem, $config, new Field(), new Section());
    }

    public function testEmptyWhenLogsMissing(): void
    {
        $status = $this->reporter([])->getStatus();

        $this->assertArrayHasKey('system_new_lines', $status['general']->getFields());
        $this->assertSame(0, $status['general']->getFields()['system_new_lines']->getValue());
        $this->assertArrayHasKey('recent_exceptions', $status);
        $this->assertSame([], $status['recent_exceptions']->getRows());
    }

    public function testParsesExceptionMessages(): void
    {
        $status = $this->reporter([
            'system.log' => "line1\nline2\n",
            'exception.log' => "RuntimeException: Something went wrong\nAnotherException: oops\n",
        ])->getStatus();

        $this->assertSame(2, $status['general']->getFields()['system_new_lines']->getValue());
        $recent = $status['recent_exceptions']->getRows();
        $this->assertCount(2, $recent);
    }

    /**
     * exception.log's own extraction is unconditional - it must keep working even if an
     * admin has removed exception.log from the "Monitored Log Files" list (which only
     * controls the recent-lines tail sections, nothing else).
     */
    public function testExceptionParsingIsUnaffectedByWhatsConfiguredForTailSections(): void
    {
        $status = $this->reporter(
            ['exception.log' => "RuntimeException: Something went wrong\n"],
            monitoredLogFiles: []
        )->getStatus();

        $this->assertCount(1, $status['recent_exceptions']->getRows());
        $this->assertArrayNotHasKey('tail_exception_log', $status);
    }

    public function testExceptionCountReflectsTotalOccurrencesNotJustTheCappedRecentList(): void
    {
        // 7 total occurrences (with a repeat), but "recent" caps at 5 unique messages -
        // exception_count should reflect the 7, not the capped/deduped list's size. Each
        // message needs 5+ chars to satisfy the extraction regex's own minimum.
        $status = $this->reporter([
            'exception.log' => 'Exception: message one
Exception: message two
Exception: message three
Exception: message four
Exception: message five
Exception: message six
Exception: message one',
        ])->getStatus();

        $this->assertSame(7, $status['general']->getFields()['exception_count']->getValue());
        $this->assertCount(5, $status['recent_exceptions']->getRows());
    }

    public function testDeclaresExceptionCountAsATrackableMetric(): void
    {
        $metrics = $this->reporter([])->getTrackableMetrics();

        $this->assertCount(1, $metrics);
        $this->assertSame('logs.exception_count', $metrics[0]->getMetricKey());
    }

    public function testNoTailSectionsWhenNothingIsConfigured(): void
    {
        $status = $this->reporter(['system.log' => "a line\n"], monitoredLogFiles: [])->getStatus();

        $this->assertSame(['general', 'recent_exceptions'], array_keys($status));
    }

    public function testAConfiguredLogFileGetsATailSectionLabeledWithItsDisplayName(): void
    {
        $status = $this->reporter(
            ['system.log' => "first line\nsecond line\n"],
            monitoredLogFiles: ['system.log' => 'System Log']
        )->getStatus();

        $this->assertArrayHasKey('tail_system_log', $status);
        $this->assertSame('System Log', $status['tail_system_log']->getLabel());
        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $status['tail_system_log']->getRows()
        );
        $this->assertSame(['first line', 'second line'], $lines);
    }

    public function testTailIsCappedAtTheMostRecentFiftyLines(): void
    {
        $lines = array_map(fn (int $i) => "line {$i}", range(1, 60));
        $status = $this->reporter(
            ['system.log' => implode("\n", $lines)],
            monitoredLogFiles: ['system.log' => 'System Log']
        )->getStatus();

        $rows = $status['tail_system_log']->getRows();
        $this->assertCount(50, $rows);
        $this->assertSame('line 11', $rows[0]->getValue()['line']->getValue());
        $this->assertSame('line 60', $rows[49]->getValue()['line']->getValue());
    }

    public function testACustomConfiguredLogFileGetsItsOwnTailSection(): void
    {
        $status = $this->reporter(
            ['payment-gateway.log' => "gateway line one\ngateway line two\n"],
            monitoredLogFiles: ['payment-gateway.log' => 'Payment Gateway']
        )->getStatus();

        $this->assertArrayHasKey('tail_payment_gateway_log', $status);
        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $status['tail_payment_gateway_log']->getRows()
        );
        $this->assertSame(['gateway line one', 'gateway line two'], $lines);
        $this->assertSame('Payment Gateway', $status['tail_payment_gateway_log']->getLabel());
    }

    /**
     * Reproduces the production crash: a log line containing invalid UTF-8 (a raw/corrupted
     * Bearer token, in the real case) must not survive into the varchar field untouched -
     * VarcharField::clean() scrubs it, but this proves the whole reporter path holds up too.
     */
    public function testTailedLinesWithInvalidUtf8DontBreakSerialization(): void
    {
        $badLine = "Bearer \xD1\x40 is not valid header value.";
        $status = $this->reporter(
            ['system.log' => "ok line\n{$badLine}\n"],
            monitoredLogFiles: ['system.log' => 'System Log']
        )->getStatus();

        $lines = array_map(
            fn ($row) => $row->getValue()['line']->getValue(),
            $status['tail_system_log']->getRows()
        );

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertNotFalse(json_encode($status['tail_system_log']->getRows()));
    }

    public function testDoesNotLoadTheWholeFileIntoMemoryToTailIt(): void
    {
        // The whole point of streaming: a file far larger than would ever fit comfortably in
        // memory twice over must still resolve to just the last 50 lines. FakeLineStream
        // hands back one line at a time, exactly like a real file handle would.
        $lineCount = 200000;
        $content = implode("\n", array_map(fn (int $i) => "line {$i}", range(1, $lineCount)));

        $status = $this->reporter(
            ['big.log' => $content],
            monitoredLogFiles: ['big.log' => 'Big Log']
        )->getStatus();

        $rows = $status['tail_big_log']->getRows();
        $this->assertCount(50, $rows);
        $this->assertSame("line {$lineCount}", $rows[49]->getValue()['line']->getValue());
    }
}
