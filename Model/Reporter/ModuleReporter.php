<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;
use Throwable;

/**
 * Every registered module (enabled or not) with its version.
 *
 * Most Magento/Mage-OS core modules no longer declare module.xml's setup_version
 * (versioning moved to Composer), so a naive read of FullModuleList's setup_version comes
 * back null for nearly every core module. Resolution instead cross-references each module's
 * composer.json "name" against composer.lock, falling back to setup_version only for modules
 * that still declare one.
 */
class ModuleReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param FullModuleList $fullModuleList
     * @param ModuleListInterface $enabledModuleList
     * @param ComponentRegistrar $componentRegistrar
     * @param Json $json
     * @param ComposerLockReader $composerLockReader
     * @param File $filesystemDriver
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleListInterface $enabledModuleList,
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly Json $json,
        private readonly ComposerLockReader $composerLockReader,
        private readonly File $filesystemDriver,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the modules reporter.
     */
    public function getName(): string
    {
        return 'modules';
    }

    /**
     * Human-readable label for the modules reporter block.
     */
    public function getLabel(): string
    {
        return 'Modules';
    }

    /**
     * One-line summary of what the modules reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Every registered module (enabled or not), with its resolved code version.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports every registered module's name, Composer package, resolved version, version source, and enabled flag.
     */
    public function getStatus(): array
    {
        $lockedVersions = $this->readComposerLockVersions();
        $modules = [];

        foreach ($this->fullModuleList->getAll() as $name => $info) {
            $packageName = $this->readComposerPackageName($name);
            [$version, $source] = $this->resolveVersion($packageName, $info['setup_version'] ?? null, $lockedVersions);

            $modules[] = $this->field->array($name, [
                'name' => $this->field->varchar('Name', $name),
                // The Composer package name (e.g. "magento/module-catalog"), not just the
                // Magento module name - needed on the dashboard side to look up the latest
                // available version from Packagist/Marketplace/vendor repositories, which are
                // keyed by package name and have no idea what "Magento_Catalog" is.
                'package' => $this->field->varchar('Composer Package', $packageName ?? ''),
                'version' => $this->field->varchar('Version', $version ?? ''),
                'version_source' => $this->field->varchar('Version Source', $source),
                'enabled' => $this->field->bool('Enabled', $this->enabledModuleList->has($name)),
            ]);
        }

        return [
            'modules' => $this->section->table('modules', 'Installed Modules', $this->getDescription(), $modules),
        ];
    }

    /**
     * Resolves a module's version, preferring composer.lock over module.xml's setup_version.
     *
     * @param string|null $packageName
     * @param string|null $setupVersion
     * @param array<string,string> $lockedVersions
     * @return array{0: ?string, 1: string} [version, source]
     */
    private function resolveVersion(?string $packageName, ?string $setupVersion, array $lockedVersions): array
    {
        if ($packageName !== null && isset($lockedVersions[$packageName])) {
            return [$lockedVersions[$packageName], 'composer_lock'];
        }

        if ($setupVersion) {
            return [$setupVersion, 'module_xml'];
        }

        return [null, 'unknown'];
    }

    /**
     * Reads a module's Composer package name from its own composer.json, via ComponentRegistrar's module path.
     *
     * @param string $moduleName
     */
    private function readComposerPackageName(string $moduleName): ?string
    {
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        if ($modulePath === null) {
            return null;
        }

        $composerJsonPath = rtrim($modulePath, '/') . '/composer.json';
        if (!$this->filesystemDriver->isReadable($composerJsonPath)) {
            return null;
        }

        try {
            $data = $this->json->unserialize((string)$this->filesystemDriver->fileGetContents($composerJsonPath));
            return $data['name'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Indexes composer.lock's packages and packages-dev by package name for O(1) version lookup.
     *
     * @return array<string, string>
     */
    private function readComposerLockVersions(): array
    {
        $data = $this->composerLockReader->getDecoded();
        if ($data === null) {
            return [];
        }

        $versions = [];
        foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
            if (isset($package['name'], $package['version'])) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }
}
