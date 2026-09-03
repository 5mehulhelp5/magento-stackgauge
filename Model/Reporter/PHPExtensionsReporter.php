<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

final class PHPExtensionsReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '2.0';

    private array $extensions = [
        'intl', 'gd', 'opcache', 'json', 'curl', 'mbstring'
    ];

    public function getName(): string
    {
        return 'php_extensions';
    }

    public function getLabel(): string
    {
        return 'PHP Extensions';
    }

    public function getDescription(): string
    {
        return 'Presence and versions of important PHP extensions.';
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
        $list = [];
        foreach ($this->extensions as $ext) {
            $enabled = extension_loaded($ext);
            $version = $enabled ? (string)phpversion($ext) : '';
            $list[] = Field::array('', [
                'name' => Field::varchar('Name', $ext),
                'enabled' => Field::bool('Enabled', $enabled),
                'version' => Field::varchar('Version', $version),
            ]);
        }

        $settings = [
            'memory_limit' => Field::varchar('Memory Limit', (string)ini_get('memory_limit')),
            'opcache_enabled' => Field::bool('OPcache Enabled', (bool)ini_get('opcache.enable'), criticalWhen: false),
        ];

        return [
            'php_extensions' => Section::table('php_extensions', 'PHP Extensions', 'Presence and versions of important PHP extensions.', $list),
            'php_settings' => Section::facts('php_settings', 'PHP Settings', '', $settings),
        ];
    }
}
