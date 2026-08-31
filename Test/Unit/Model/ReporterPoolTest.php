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
use StackNuts\ViewGento\Api\DeclaresCadenceInterface;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;
use StackNuts\ViewGento\Model\Config;
use StackNuts\ViewGento\Model\ReporterPool;

class ReporterPoolTest extends TestCase
{
    /**
     * @param array<string, mixed> $status
     */
    private function fakeReporterWithCadence(string $name, string $cadence, array $status): ReporterInterface
    {
        return new class ($name, $cadence, $status) implements ReporterInterface, DeclaresCadenceInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $cadence,
                private readonly array $status
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getCadence(): string
            {
                return $this->cadence;
            }

            public function getStatus(): array
            {
                return $this->status;
            }
        };
    }

    /**
     * @param array<string, mixed> $status
     */
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

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
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

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
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

    /**
     * A badly-behaved reporter returning a raw scalar instead of a Field - exactly the
     * mistake ReporterPool's validation exists to catch.
     */
    private function malformedReporter(string $name): ReporterInterface
    {
        return $this->fakeReporter($name, '1.0', ['edition' => 'Community']);
    }

    public function testWrapsFieldsUnderTheReporterEnvelope(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '2.0', ['edition' => Field::varchar('Edition', 'Community')])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertEquals(
            [
                'core' => [
                    'schema_version' => '2.0',
                    'label' => 'Core',
                    'description' => 'A fake reporter for tests.',
                    'fields' => ['edition' => Field::varchar('Edition', 'Community')],
                ],
            ],
            $pool->collect()
        );
    }

    public function testDisabledBuiltInReporterIsSkipped(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['modules']); // "core" not enabled

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '1.0', ['edition' => Field::varchar('Edition', 'Community')])],
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
            [$this->fakeReporter('cloudflare', '1.0', ['purge_queue_backlog' => Field::number('Backlog', 0)])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $result = $pool->collect();
        $this->assertArrayHasKey('cloudflare', $result);
        $this->assertSame('1.0', $result['cloudflare']['schema_version']);
        $this->assertEquals(['purge_queue_backlog' => Field::number('Backlog', 0)], $result['cloudflare']['fields']);
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
                $this->fakeReporter('cron', '1.0', ['alive' => Field::bool('Alive', true)]),
            ],
            $config,
            $logger
        );

        $result = $pool->collect();
        $this->assertSame(['error' => 'boom'], $result['core']);
        $this->assertSame('1.0', $result['cron']['schema_version']);
    }

    public function testAReporterReturningARawScalarInsteadOfAFieldProducesAnErrorBlock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $pool = new ReporterPool([$this->malformedReporter('core')], $config, $logger);

        $result = $pool->collect();
        $this->assertArrayHasKey('error', $result['core']);
        $this->assertStringContainsString('FieldInterface', $result['core']['error']);
    }

    public function testAReporterWithNoCadenceDeclarationIsAlwaysTreatedAsHourly(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '1.0', ['edition' => Field::varchar('Edition', 'Community')])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertArrayHasKey('core', $pool->collect(DeclaresCadenceInterface::CADENCE_HOURLY));
        $this->assertArrayNotHasKey('core', $pool->collect(DeclaresCadenceInterface::CADENCE_DAILY));
    }

    public function testADailyCadenceReporterIsExcludedFromAnHourlyCollection(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['modules']);

        $pool = new ReporterPool(
            [
                $this->fakeReporterWithCadence(
                    'modules',
                    DeclaresCadenceInterface::CADENCE_DAILY,
                    ['count' => Field::number('Count', 42)]
                ),
            ],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $pool->collect(DeclaresCadenceInterface::CADENCE_HOURLY));
        $this->assertArrayHasKey('modules', $pool->collect(DeclaresCadenceInterface::CADENCE_DAILY));
    }

    public function testGetReportersReturnsEveryRegisteredReporterRegardlessOfEnabledState(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn([]);

        $reporters = [
            $this->fakeReporter('core', '1.0', []),
            $this->fakeReporter('cloudflare', '1.0', []),
        ];

        $pool = new ReporterPool($reporters, $config, $this->createStub(LoggerInterface::class));

        $this->assertSame($reporters, $pool->getReporters());
    }
}
