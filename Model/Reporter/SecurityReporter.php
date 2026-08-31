<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Module\ModuleListInterface;
use StackNuts\ViewGento\Api\DeclaresCadenceInterface;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

class SecurityReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '2.0';
    private const DEFAULT_ADMIN_PATH = 'admin';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    public function getName(): string
    {
        return 'security';
    }

    public function getLabel(): string
    {
        return 'Security';
    }

    public function getDescription(): string
    {
        return 'Whether the admin path is still the default, maintenance-mode flag, sample-data modules present.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getCadence(): string
    {
        return self::CADENCE_DAILY;
    }

    public function getStatus(): array
    {
        $sampleDataModules = array_values(array_filter(
            array_keys($this->moduleList->getAll()),
            static fn (string $name): bool => str_contains($name, 'SampleData')
        ));

        return [
            // Deliberately a boolean, not the actual admin path string - sending every
            // client's real (deliberately obscured) admin URL to a third-party dashboard
            // would concentrate exactly the secret that obscurity is meant to protect.
            'is_default_admin_path' => Field::bool(
                'Is Default Admin Path',
                $this->getAdminFrontName() === self::DEFAULT_ADMIN_PATH
            ),
            'maintenance_mode' => Field::bool('Maintenance Mode', $this->maintenanceMode->isOn()),
            'sample_data_present' => Field::bool('Sample Data Present', $sampleDataModules !== []),
            'sample_data_modules' => Field::array('Sample Data Modules', array_map(
                static fn (string $name) => Field::varchar($name, $name),
                $sampleDataModules
            )),
        ];
    }

    private function getAdminFrontName(): string
    {
        return (string)($this->deploymentConfig->get('backend/frontName') ?? self::DEFAULT_ADMIN_PATH);
    }
}
