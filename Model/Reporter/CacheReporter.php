<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use StackNuts\ViewGento\Api\Field\ArrayField;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

class CacheReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '2.0';

    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly PageCacheConfig $pageCacheConfig
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function getLabel(): string
    {
        return 'Cache';
    }

    public function getDescription(): string
    {
        return 'Per-cache-type enabled/disabled status, plus which Full Page Cache type is active.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $types = [];

        foreach ($this->cacheTypeList->getTypes() as $id => $info) {
            $types[] = Field::array($id, [
                'type' => Field::varchar('Type', $id),
                'status' => Field::number('Status', (int)($info['status'] ?? 0)),
            ]);
        }

        return [
            'types' => Field::array('Cache Types', $types),
            'full_page_cache' => $this->getFullPageCacheStatus(),
        ];
    }

    /**
     * No network call, unlike RedisReporter/SearchReporter - just the same config value
     * Magento's own admin uses to pick which caching_application is active
     * (Stores > Configuration > Advanced > System > Full Page Cache). Third-party FPC types
     * (e.g. this project's own StackNuts_CloudflareCache, which claims id 3) are reported by
     * their raw type_id rather than by name, since this module has no way to know every
     * third-party type id in advance.
     */
    private function getFullPageCacheStatus(): ArrayField
    {
        $typeId = (int)$this->pageCacheConfig->getType();

        $typeLabel = match ($typeId) {
            PageCacheConfig::BUILT_IN => 'built_in',
            PageCacheConfig::VARNISH => 'varnish',
            default => 'custom',
        };

        return Field::array('Full Page Cache', [
            'enabled' => Field::bool('Enabled', $this->pageCacheConfig->isEnabled()),
            'type_id' => Field::number('Type ID', $typeId),
            'type_label' => Field::varchar('Type Label', $typeLabel),
        ]);
    }
}
