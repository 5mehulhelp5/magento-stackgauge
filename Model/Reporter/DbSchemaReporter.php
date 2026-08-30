<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ModuleResource;
use StackNuts\ViewGento\Api\ReporterInterface;

/**
 * Flags modules where the code's declared setup_version (module.xml) has moved ahead of
 * what's actually recorded in the setup_module DB table - the classic "deploy ran but
 * setup:upgrade never did" drift, invisible from the outside and easy to miss per-site
 * without an inventory like this.
 */
class DbSchemaReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ModuleResource $moduleResource
    ) {
    }

    public function getName(): string
    {
        return 'db_schema';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
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
                $drifted[] = [
                    'module' => $name,
                    'code_version' => $codeVersion,
                    'db_version' => $dbVersion,
                ];
            } else {
                $inSyncCount++;
            }
        }

        return ['drifted' => $drifted, 'in_sync_count' => $inSyncCount];
    }
}
