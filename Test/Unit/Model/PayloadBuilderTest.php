<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model;

use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\ViewGento\Model\Config;
use StackNuts\ViewGento\Model\PayloadBuilder;
use StackNuts\ViewGento\Model\ReporterPool;

class PayloadBuilderTest extends TestCase
{
    public function testBuildAssemblesTheFullEnvelope(): void
    {
        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('collect')->willReturn([
            'core' => ['schema_version' => '1.0', 'edition' => 'Community'],
        ]);

        $config = $this->createStub(Config::class);
        $config->method('getSiteId')->willReturn('site-123');

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '1.0.0']);

        $builder = new PayloadBuilder($reporterPool, $config, $moduleList);
        $payload = $builder->build();

        $this->assertSame('full', $payload['type']);
        $this->assertSame('1.0', $payload['schema_version']);
        $this->assertSame('1.0.0', $payload['module_version']);
        $this->assertSame(['identifier' => 'site-123'], $payload['site']);
        $this->assertSame(['core' => ['schema_version' => '1.0', 'edition' => 'Community']], $payload['reporters']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['generated_at']
        );
    }

    public function testModuleVersionIsNullWhenNotAvailable(): void
    {
        $reporterPool = $this->createStub(ReporterPool::class);
        $reporterPool->method('collect')->willReturn([]);

        $config = $this->createStub(Config::class);
        $config->method('getSiteId')->willReturn(null);

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn([]);

        $builder = new PayloadBuilder($reporterPool, $config, $moduleList);
        $payload = $builder->build();

        $this->assertNull($payload['module_version']);
        $this->assertNull($payload['site']['identifier']);
    }
}
