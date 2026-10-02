<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\System\Config\Source;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Model\ReporterPool;
use StackNuts\StackGauge\Model\System\Config\Source\ReporterList;

class ReporterListTest extends TestCase
{
    private function reporter(string $name, string $label): ReporterInterface
    {
        $reporter = $this->createStub(ReporterInterface::class);
        $reporter->method('getName')->willReturn($name);
        $reporter->method('getLabel')->willReturn($label);

        return $reporter;
    }

    public function testReturnsNoOptionsWhenNothingIsRegistered(): void
    {
        $pool = $this->createStub(ReporterPool::class);
        $pool->method('getReporters')->willReturn([]);

        $this->assertSame([], (new ReporterList($pool))->toOptionArray());
    }

    /**
     * A third-party reporter (e.g. one a companion module like StackGaugeCloudflareCache
     * registers) must appear here exactly like a built-in one - that's the whole point of
     * deriving options from ReporterPool rather than a hardcoded list of built-in codes.
     */
    public function testIncludesBothBuiltInAndThirdPartyReporters(): void
    {
        $pool = $this->createStub(ReporterPool::class);
        $pool->method('getReporters')->willReturn([
            $this->reporter('core', 'Core'),
            $this->reporter('cloudflare', 'Cloudflare'),
        ]);

        $this->assertSame(
            [
                ['value' => 'core', 'label' => 'Core'],
                ['value' => 'cloudflare', 'label' => 'Cloudflare'],
            ],
            (new ReporterList($pool))->toOptionArray()
        );
    }

    public function testSkipsAnyNonReporterEntryInThePool(): void
    {
        $pool = $this->createStub(ReporterPool::class);
        $pool->method('getReporters')->willReturn([$this->reporter('core', 'Core'), 'not-a-reporter']);

        $this->assertSame(
            [['value' => 'core', 'label' => 'Core']],
            (new ReporterList($pool))->toOptionArray()
        );
    }
}
