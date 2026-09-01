<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

final class StoreViewsReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'store_views';
    }

    public function getLabel(): string
    {
        return 'Store Views';
    }

    public function getDescription(): string
    {
        return 'Configured store views and basic locale/currency information.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $id = (int)$store->getId();
            $baseUrl = method_exists($store, 'getBaseUrl') ? (string)$store->getBaseUrl() : '';
            $currency = (string)$this->scopeConfig->getValue('currency/options/default', 'stores', $id);
            $locale = (string)$this->scopeConfig->getValue('general/locale/code', 'stores', $id);

            $stores[] = Field::array('', [
                'id' => Field::number('ID', $id),
                'code' => Field::varchar('Code', (string)$store->getCode()),
                'name' => Field::varchar('Name', (string)$store->getName()),
                'base_url' => Field::varchar('Base URL', $baseUrl),
                'locale' => Field::varchar('Locale', $locale),
                'currency' => Field::varchar('Currency', $currency),
            ]);
        }

        return ['store_views' => Field::array('Store Views', $stores)];
    }
}
