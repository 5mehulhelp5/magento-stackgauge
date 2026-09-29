<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Module\ModuleListInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

class SecurityReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const DEFAULT_ADMIN_PATH = 'admin';

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param MaintenanceMode $maintenanceMode
     * @param ModuleListInterface $moduleList
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly ModuleListInterface $moduleList,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the security reporter.
     */
    public function getName(): string
    {
        return 'security';
    }

    /**
     * Human-readable label for the security reporter block.
     */
    public function getLabel(): string
    {
        return 'Security';
    }

    /**
     * One-line summary of what the security reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Whether the admin path is still the default, maintenance-mode flag, sample-data modules present.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports whether the admin path is still the default, maintenance-mode state, and any installed sample-data modules.
     */
    public function getStatus(): array
    {
        $sampleDataModules = array_values(array_filter(
            array_keys($this->moduleList->getAll()),
            static fn (string $name): bool => str_contains($name, 'SampleData')
        ));

        return [
            'general' => $this->section->facts('general', 'General', '', [
                // Deliberately a boolean, not the actual admin path string - sending every
                // client's real (deliberately obscured) admin URL to a third-party dashboard
                // would concentrate exactly the secret that obscurity is meant to protect.
                'is_default_admin_path' => $this->field->bool(
                    'Is Default Admin Path',
                    $this->getAdminFrontName() === self::DEFAULT_ADMIN_PATH,
                    criticalWhen: true
                ),
                'maintenance_mode' => $this->field->bool(
                    'Maintenance Mode',
                    $this->maintenanceMode->isOn(),
                    criticalWhen: true
                ),
                'sample_data_present' => $this->field->bool(
                    'Sample Data Present',
                    $sampleDataModules !== [],
                    criticalWhen: true
                ),
            ]),
            'sample_data_modules' => $this->section->table('sample_data_modules', 'Sample Data Modules', '', array_map(
                fn (string $name) => $this->field->array($name, [
                    'module' => $this->field->varchar('Module', $name),
                ]),
                $sampleDataModules
            ), keyName: 'module'),
        ];
    }

    /**
     * Resolves the configured admin frontName, falling back to DEFAULT_ADMIN_PATH when unset.
     */
    private function getAdminFrontName(): string
    {
        return (string)($this->deploymentConfig->get('backend/frontName') ?? self::DEFAULT_ADMIN_PATH);
    }
}
