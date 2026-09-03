<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use Throwable;

class ComposerReporter implements ReporterInterface, DeclaresCadenceInterface
{
    private const SCHEMA_VERSION = '3.0';

    /**
     * A small, deliberately fixed watch-list rather than every package in the lock file -
     * the full module-by-module inventory already comes from ModuleReporter; this is just
     * enough to correlate a deploy with its core platform/framework version. Includes both
     * Adobe/Magento Open Source and Mage-OS package names - verified against a Mage-OS dev
     * instance while building this, where the "magento/*" names never appear at all.
     */
    private const KEY_PACKAGES = [
        'magento/product-community-edition',
        'magento/product-enterprise-edition',
        'magento/framework',
        'mage-os/product-community-edition',
        'mage-os/framework',
    ];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json
    ) {
    }

    public function getName(): string
    {
        return 'composer';
    }

    public function getLabel(): string
    {
        return 'Composer';
    }

    public function getDescription(): string
    {
        return 'composer.lock hash plus a small watch-list of key platform package versions.';
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
        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);

        if (!$root->isExist('composer.lock')) {
            return [
                'general' => Section::facts('general', 'General', '', ['lock_hash' => Field::varchar('Lock Hash', '')]),
                'key_packages' => Section::facts('key_packages', 'Key Packages', '', []),
            ];
        }

        $contents = $root->readFile('composer.lock');

        return [
            'general' => Section::facts('general', 'General', '', [
                'lock_hash' => Field::varchar('Lock Hash', 'sha256:' . hash('sha256', $contents)),
            ]),
            'key_packages' => Section::facts('key_packages', 'Key Packages', '', $this->extractKeyPackages($contents)),
        ];
    }

    /**
     * @return array<string, \StackNuts\StackGauge\Api\Field\VarcharField>
     */
    private function extractKeyPackages(string $lockFileContents): array
    {
        $keyPackages = [];

        try {
            $data = $this->json->unserialize($lockFileContents);
            foreach ($data['packages'] ?? [] as $package) {
                $name = $package['name'] ?? null;
                if ($name !== null && in_array($name, self::KEY_PACKAGES, true)) {
                    $keyPackages[$name] = Field::varchar($name, (string)($package['version'] ?? ''));
                }
            }
        } catch (Throwable) {
            // Malformed composer.lock - the hash above still identifies the deploy;
            // key package versions are just omitted rather than failing the whole reporter.
        }

        return $keyPackages;
    }
}
