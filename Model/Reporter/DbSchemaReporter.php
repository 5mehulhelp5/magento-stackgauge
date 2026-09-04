<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ModuleResource;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;

/**
 * Flags modules where the code's declared setup_version (module.xml) has moved ahead of
 * what's actually recorded in the setup_module DB table - the classic "deploy ran but
 * setup:upgrade never did" drift, invisible from the outside and easy to miss per-site
 * without an inventory like this.
 */
class DbSchemaReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '3.0';

    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ModuleResource $moduleResource
    ) {
    }

    public function getName(): string
    {
        return 'db_schema';
    }

    public function getLabel(): string
    {
        return 'DB Schema Drift';
    }

    public function getDescription(): string
    {
        return 'Modules whose code setup_version has moved ahead of what setup:upgrade has actually applied.';
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
        $drifted = [];
        $inSyncCount = 0;

        foreach ($this->moduleList->getAll() as $name => $info) {
            $codeVersion = $info['setup_version'] ?? null;
            if (!$codeVersion) {
                continue;
            }

            $dbVersion = $this->moduleResource->getDbVersion($name);
            if ($dbVersion === false) {
                continue;
            }

            if ($dbVersion !== $codeVersion) {
                $drifted[] = Field::array($name, [
                    'name' => Field::varchar('Name', $name),
                    'module' => Field::varchar('Module', $name),
                    'code_version' => Field::varchar('Code Version', $codeVersion),
                    'db_version' => Field::varchar('DB Version', $dbVersion),
                ]);
            } else {
                $inSyncCount++;
            }
        }

        return [
            'general' => Section::facts('general', 'General', $this->getDescription(), [
                'in_sync_count' => Field::number('In-Sync Module Count', $inSyncCount),
            ]),
            'drifted' => Section::table('drifted', 'Drifted Modules', 'Modules with a code/DB schema version mismatch.', $drifted),
        ];
    }
}
