<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Field\FieldInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;

class CacheReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '4.0';

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
                'name' => Field::varchar('Name', (string)$id),
                'type' => Field::varchar('Type', $id),
                'enabled' => Field::bool('Enabled', (bool)($info['status'] ?? 0), criticalWhen: false),
            ]);
        }

        // Full Page Cache first - it's the one FPC-specific setting an agency actually wants
        // to check first ("which cache backend is active"), before the full per-cache-type
        // breakdown below it.
        return [
            'full_page_cache' => Section::facts(
                'full_page_cache',
                'Full Page Cache',
                'Which Full Page Cache type is active.',
                $this->getFullPageCacheStatus()
            ),
            'types' => Section::table('types', 'Cache Types', 'Per-cache-type enabled/disabled status.', $types),
        ];
    }

    /**
     * No network call, unlike RedisReporter/SearchReporter - just the same config value
     * Magento's own admin uses to pick which caching_application is active. The sibling
     * StackNuts_CloudflareCache module registers a real option here (its
     * Plugin\Model\System\Config\Source\ApplicationPlugin adds "Cloudflare" /
     * Model\Config::TYPE_CLOUDFLARE = 3 to Magento's Application source), so a site running
     * Cloudflare as its FPC shows type_id 3, not "built_in". Any other unrecognised type_id
     * is reported as "custom" - the raw type_id stays available via the sibling field.
     *
     * @return array<string, FieldInterface>
     */
    private function getFullPageCacheStatus(): array
    {
        $typeId = (int)$this->pageCacheConfig->getType();

        $typeLabel = match ($typeId) {
            PageCacheConfig::BUILT_IN => 'built_in',
            PageCacheConfig::VARNISH => 'varnish',
            default => 'custom',
        };

        return [
            'enabled' => Field::bool('Enabled', $this->pageCacheConfig->isEnabled()),
            'type_id' => Field::number('Type ID', $typeId),
            'type_label' => Field::varchar('Type Label', $typeLabel),
        ];
    }
}
