<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model;

use DateTimeImmutable;
use Magento\Framework\Module\ModuleListInterface;
use StackNuts\ViewGento\Api\DeclaresCadenceInterface;
use StackNuts\ViewGento\Api\MetricDefinition;

/**
 * Assembles the full-collection envelope sent by Cron\SendReport/SendDailyReport, the CLI
 * command, and the admin Test Ping button - and the much smaller config-sync envelope sent by
 * Cron\SendConfigSync, the ConfigSyncOnSectionSave observer, and Test Ping. schema_version
 * covers the envelope shape itself (not any one reporter's data - see
 * Api\ReporterInterface::getSchemaVersion() for that), so the dashboard can evolve
 * independently of every installed module version left in the field.
 */
class PayloadBuilder
{
    /**
     * Bumped to 2.0 when every reporter's "fields" moved from raw scalars to typed Field
     * values (see Api\Field) - a genuine envelope-shape break, not just a data change.
     */
    private const SCHEMA_VERSION = '2.0';
    private const MODULE_NAME = 'StackNuts_ViewGento';

    public function __construct(
        private readonly ReporterPool $reporterPool,
        private readonly MetricCatalogPool $metricCatalogPool,
        private readonly Config $config,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): array
    {
        return [
            'type' => 'full',
            'cadence' => $cadence,
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $this->getModuleVersion(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'reporters' => $this->reporterPool->collect($cadence),
        ];
    }

    /**
     * The much smaller "what's trackable, and what's a sensible starting alert rule"
     * envelope - deliberately does NOT carry any editable alert-rule state, only the
     * catalog + suggested defaults. See docs on Api\MetricCatalogInterface and
     * MetricDefinition for why the dashboard, not this module, owns the editable rule.
     *
     * @return array<string, mixed>
     */
    public function buildConfigSync(): array
    {
        return [
            'type' => 'config',
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $this->getModuleVersion(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'site' => [
                'identifier' => $this->config->getSiteId(),
            ],
            'metrics' => array_values(array_map(
                static fn (MetricDefinition $metric): array => $metric->jsonSerialize(),
                $this->metricCatalogPool->collect()
            )),
        ];
    }

    private function getModuleVersion(): ?string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);

        return $module['setup_version'] ?? null;
    }
}
