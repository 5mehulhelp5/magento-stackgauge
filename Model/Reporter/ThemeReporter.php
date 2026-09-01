<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

final class ThemeReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function getName(): string
    {
        return 'themes';
    }

    public function getLabel(): string
    {
        return 'Themes';
    }

    public function getDescription(): string
    {
        return 'Active frontend and admin themes.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $frontend = (string)$this->scopeConfig->getValue('design/theme/theme_id', ScopeInterface::SCOPE_STORE);
        $admin = (string)$this->scopeConfig->getValue('design/theme/theme_id', ScopeInterface::SCOPE_DEFAULT);

        $themes = [
            Field::array('frontend', [
                'theme_id' => Field::varchar('Theme ID', $frontend),
            ]),
            Field::array('adminhtml', [
                'theme_id' => Field::varchar('Theme ID', $admin),
            ]),
        ];

        return ['themes' => Field::array('Themes', $themes)];
    }
}
