<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model;

use DateTimeImmutable;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Assembles the full-collection envelope sent by Cron\SendReport, the CLI command, and the
 * admin Test Ping button. schema_version covers the envelope shape itself (not any one
 * reporter's data - see Api\ReporterInterface::getSchemaVersion() for that), so the
 * dashboard can evolve independently of every installed module version left in the field.
 */
class PayloadBuilder
{
    private const SCHEMA_VERSION = '1.0';
    private const MODULE_NAME = 'StackNuts_ViewGento';

    public function __construct(
        private readonly ReporterPool $reporterPool,
        private readonly Config $config,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'type' => 'full',
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $this->getModuleVersion(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'reporters' => $this->reporterPool->collect(),
        ];
    }

    private function getModuleVersion(): ?string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);

        return $module['setup_version'] ?? null;
    }
}
