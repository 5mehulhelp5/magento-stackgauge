<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PlatformSectionTrait;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ComposerReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PlatformSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * A small fixed watch-list, not every package in the lock file - just enough to
     * correlate a deploy with its core platform/framework version. Includes both Magento
     * Open Source and Mage-OS package names, since a Mage-OS lock file has no "magento/*"
     * packages at all.
     */
    private const KEY_PACKAGES = [
        'magento/product-community-edition',
        'magento/product-enterprise-edition',
        'magento/framework',
        'mage-os/product-community-edition',
        'mage-os/framework',
    ];

    /**
     * @param ComposerLockReader $composerLockReader
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ComposerLockReader $composerLockReader,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the composer reporter.
     */
    public function getName(): string
    {
        return 'composer';
    }

    /**
     * Human-readable label for the composer reporter block.
     */
    public function getLabel(): string
    {
        return 'Composer';
    }

    /**
     * One-line summary of what the composer reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'composer.lock hash plus a small watch-list of key platform package versions.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports a hash of composer.lock's raw contents plus the installed version of each package in KEY_PACKAGES.
     */
    public function getStatus(): array
    {
        $contents = $this->composerLockReader->getRawContents();

        if ($contents === null) {
            return [
                'general' => $this->section->facts(
                    'general',
                    'General',
                    '',
                    ['lock_hash' => $this->field->varchar('Lock Hash', '')]
                ),
                'key_packages' => $this->section->facts('key_packages', 'Key Packages', '', []),
            ];
        }

        return [
            'general' => $this->section->facts('general', 'General', '', [
                'lock_hash' => $this->field->varchar('Lock Hash', 'sha256:' . hash('sha256', $contents)),
            ]),
            'key_packages' => $this->section->facts('key_packages', 'Key Packages', '', $this->extractKeyPackages()),
        ];
    }

    /**
     * Resolves the installed version of each package in KEY_PACKAGES that's present in composer.lock.
     *
     * @return array<string, \StackNuts\StackGauge\Api\Field\VarcharField>
     */
    private function extractKeyPackages(): array
    {
        $keyPackages = [];
        $data = $this->composerLockReader->getDecoded();

        foreach ($data['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            if ($name !== null && in_array($name, self::KEY_PACKAGES, true)) {
                $keyPackages[$name] = $this->field->varchar($name, (string)($package['version'] ?? ''));
            }
        }

        return $keyPackages;
    }
}
