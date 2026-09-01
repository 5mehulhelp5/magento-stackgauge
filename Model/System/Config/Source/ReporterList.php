<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for the admin "Enabled Reporters" multiselect. Lists only this module's own
 * built-in reporter codes (the "name" each Reporter\* class returns) - third-party
 * reporters registered via di.xml are always-on and not offered here, per the scope note
 * in Api\ReporterInterface.
 */
class ReporterList implements OptionSourceInterface
{
    /**
     * Canonical list of built-in reporter codes - also consumed by Model\ReporterPool to
     * decide which reporter names are subject to the admin toggle at all (a third-party
     * reporter's name won't appear here, so it stays always-on regardless of this setting).
     */
    public const CODES = [
        'core',
        'modules',
        'composer',
        'db_schema',
        'patches',
        'indexers',
        'cron',
        'cache',
        'security',
        'redis',
        'search',
        'rabbitmq',
        'disk',
        'database',
        'sales',
        'catalog',
    ];

    public function toOptionArray(): array
    {
        return [
            ['value' => 'core', 'label' => __('Core (edition, version, PHP, deploy mode)')],
            ['value' => 'modules', 'label' => __('Modules')],
            ['value' => 'composer', 'label' => __('Composer')],
            ['value' => 'db_schema', 'label' => __('DB Schema Drift')],
            ['value' => 'patches', 'label' => __('Patches')],
            ['value' => 'indexers', 'label' => __('Indexers')],
            ['value' => 'cron', 'label' => __('Cron')],
            ['value' => 'cache', 'label' => __('Cache (incl. Full Page Cache type)')],
            ['value' => 'security', 'label' => __('Security')],
            ['value' => 'redis', 'label' => __('Redis (cache + session backends)')],
            ['value' => 'search', 'label' => __('Search engine (Elasticsearch/OpenSearch)')],
            ['value' => 'rabbitmq', 'label' => __('RabbitMQ (per-queue depth, if configured)')],
            ['value' => 'disk', 'label' => __('Disk space (var/log, var/cache, media)')],
            ['value' => 'database', 'label' => __('Database (MySQL/MariaDB version)')],
            ['value' => 'sales', 'label' => __('Sales (lifetime order/quote counts)')],
            ['value' => 'catalog', 'label' => __('Catalog (enabled product count)')],
        ];
    }
}
