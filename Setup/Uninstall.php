<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Drops this module's own table on `bin/magento module:uninstall` - nothing else. StackGauge
 * never touches catalog/sales/customer data, so uninstalling it has exactly one piece of state
 * to clean up: the log read offsets in Model\LogOffsetStore.
 */
class Uninstall implements UninstallInterface
{
    /**
     * Drops the log-offset table, if it exists.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $connection = $setup->getConnection();
        $table = $setup->getTable('stacknuts_stackgauge_log_offset');

        if ($connection->isTableExists($table)) {
            $connection->dropTable($table);
        }

        $setup->endSetup();
    }
}
