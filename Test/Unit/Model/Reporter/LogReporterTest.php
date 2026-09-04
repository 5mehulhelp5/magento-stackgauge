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
use StackNuts\StackGauge\Model\Reporter\LogReporter;

class LogReporterTest extends TestCase
{
    public function testEmptyWhenLogsMissing(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $logDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($logDir);
        $logDir->method('isExist')->willReturn(false);

        $reporter = new LogReporter($filesystem);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('system_new_lines', $status['general']->getFields());
        $this->assertSame(0, $status['general']->getFields()['system_new_lines']->getValue());
        $this->assertArrayHasKey('recent_exceptions', $status);
        $this->assertSame([], $status['recent_exceptions']->getRows());
    }

    public function testParsesExceptionMessages(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $logDir = $this->createMock(ReadInterface::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($logDir);

        $logDir->method('isExist')->willReturn(true);
        $logDir->method('readFile')->willReturnOnConsecutiveCalls(
            "line1\nline2\n",
            "RuntimeException: Something went wrong\nAnotherException: oops\n"
        );

        $reporter = new LogReporter($filesystem);
        $status = $reporter->getStatus();

        $this->assertSame(2, $status['general']->getFields()['system_new_lines']->getValue());
        $recent = $status['recent_exceptions']->getRows();
        $this->assertCount(2, $recent);
    }
}
