<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ScopeInterface as AppScopeInterface;
use Magento\Store\Model\ScopeInterface as StoreScopeInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;

class ThemeReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the themes reporter.
     */
    public function getName(): string
    {
        return 'themes';
    }

    /**
     * Human-readable label for the themes reporter block.
     */
    public function getLabel(): string
    {
        return 'Themes';
    }

    /**
     * One-line summary of what the themes reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Active frontend and admin themes.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the active theme_id for the frontend store scope and the admin default scope.
     */
    public function getStatus(): array
    {
        $frontend = (string)$this->scopeConfig->getValue('design/theme/theme_id', StoreScopeInterface::SCOPE_STORE);
        $admin = (string)$this->scopeConfig->getValue('design/theme/theme_id', AppScopeInterface::SCOPE_DEFAULT);

        $themes = [
            $this->field->array('frontend', [
                'name' => $this->field->varchar('Name', 'frontend'),
                'theme_id' => $this->field->varchar('Theme ID', $frontend),
            ]),
            $this->field->array('adminhtml', [
                'name' => $this->field->varchar('Name', 'adminhtml'),
                'theme_id' => $this->field->varchar('Theme ID', $admin),
            ]),
        ];

        return ['themes' => $this->section->table('themes', 'Active Themes', $this->getDescription(), $themes)];
    }
}
