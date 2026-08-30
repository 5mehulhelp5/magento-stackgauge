<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StackNuts\ViewGento\Api\ReporterInterface;
use StackNuts\ViewGento\Model\Config;
use StackNuts\ViewGento\Model\ReporterPool;

class ReporterPoolTest extends TestCase
{
    private function fakeReporter(string $name, string $schemaVersion, array $status): ReporterInterface
    {
        return new class ($name, $schemaVersion, $status) implements ReporterInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $schemaVersion,
                private readonly array $status
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getSchemaVersion(): string
            {
                return $this->schemaVersion;
            }

            public function getStatus(): array
            {
                return $this->status;
            }
        };
    }

    private function throwingReporter(string $name, string $message): ReporterInterface
    {
        return new class ($name, $message) implements ReporterInterface {
            public function __construct(private readonly string $name, private readonly string $message)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getStatus(): array
            {
                throw new RuntimeException($this->message);
            }
        };
    }

    public function testMergesSchemaVersionIntoEachBlock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '2.0', ['edition' => 'Community'])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame(
            ['core' => ['schema_version' => '2.0', 'edition' => 'Community']],
            $pool->collect()
        );
    }

    public function testDisabledBuiltInReporterIsSkipped(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['modules']); // "core" not enabled

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '1.0', ['edition' => 'Community'])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $pool->collect());
    }

    public function testThirdPartyReporterNameAlwaysRunsRegardlessOfEnabledList(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn([]); // nothing built-in enabled

        $pool = new ReporterPool(
            [$this->fakeReporter('cloudflare', '1.0', ['purge_queue_backlog' => 0])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame(
            ['cloudflare' => ['schema_version' => '1.0', 'purge_queue_backlog' => 0]],
            $pool->collect()
        );
    }

    public function testAFailingReporterProducesAnErrorBlockWithoutBlockingOthers(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core', 'cron']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $pool = new ReporterPool(
            [
                $this->throwingReporter('core', 'boom'),
                $this->fakeReporter('cron', '1.0', ['alive' => true]),
            ],
            $config,
            $logger
        );

        $this->assertSame(
            [
                'core' => ['error' => 'boom'],
                'cron' => ['schema_version' => '1.0', 'alive' => true],
            ],
            $pool->collect()
        );
    }
}
