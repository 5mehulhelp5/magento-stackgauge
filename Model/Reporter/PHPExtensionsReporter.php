<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

final class PHPExtensionsReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

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
            'opcache_enabled' => Field::bool('OPcache Enabled', (bool)ini_get('opcache.enable')),
        ];

        return [
            'php_extensions' => Field::array('PHP Extensions', $list),
            'php_settings' => Field::array('PHP Settings', array_map(function ($k, $v) { return $v; }, array_keys($settings), $settings)),
        ];
    }
}
