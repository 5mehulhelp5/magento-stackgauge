<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use StackNuts\ViewGento\Api\ReporterInterface;

class CacheReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly PageCacheConfig $pageCacheConfig
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $types = [];

        foreach ($this->cacheTypeList->getTypes() as $id => $info) {
            $types[] = [
                'type' => $id,
                'status' => (int)($info['status'] ?? 0),
            ];
        }

        return [
            'types' => $types,
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
    private function getFullPageCacheStatus(): array
    {
        $typeId = (int)$this->pageCacheConfig->getType();

        return [
            'enabled' => $this->pageCacheConfig->isEnabled(),
            'type_id' => $typeId,
            'type_label' => match ($typeId) {
                PageCacheConfig::BUILT_IN => 'built_in',
                PageCacheConfig::VARNISH => 'varnish',
                default => 'custom',
            },
        ];
    }
}
