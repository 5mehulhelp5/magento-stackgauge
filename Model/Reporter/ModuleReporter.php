<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Every registered module (enabled or not) with its version - the per-module version
 * inventory the whole project is built around.
 *
 * Verified against a real dev instance while building this: most Magento/Mage-OS core
 * modules stopped declaring module.xml's setup_version years ago (versioning moved to
 * Composer), so a naive read of FullModuleList's setup_version comes back null for nearly
 * every core module. Resolution here instead cross-references each module's own
 * composer.json "name" against the installed version recorded in composer.lock, falling
 * back to module.xml's setup_version only for modules that still declare one (mainly
 * custom/third-party modules, including this vendor's own).
 */
class ModuleReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleListInterface $enabledModuleList,
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly Filesystem $filesystem,
        private readonly Json $json
    ) {
    }

    public function getName(): string
    {
        return 'modules';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $lockedVersions = $this->readComposerLockVersions();
        $modules = [];

        foreach ($this->fullModuleList->getAll() as $name => $info) {
            [$version, $source] = $this->resolveVersion($name, $info['setup_version'] ?? null, $lockedVersions);

            $modules[] = [
                'name' => $name,
                'version' => $version,
                'version_source' => $source,
                'enabled' => $this->enabledModuleList->has($name),
            ];
        }

        return ['modules' => $modules];
    }

    /**
     * @param array<string, string> $lockedVersions
     * @return array{0: ?string, 1: string} [version, source]
     */
    private function resolveVersion(string $moduleName, ?string $setupVersion, array $lockedVersions): array
    {
        $packageName = $this->readComposerPackageName($moduleName);
        if ($packageName !== null && isset($lockedVersions[$packageName])) {
            return [$lockedVersions[$packageName], 'composer_lock'];
        }

        if ($setupVersion) {
            return [$setupVersion, 'module_xml'];
        }

        return [null, 'unknown'];
    }

    private function readComposerPackageName(string $moduleName): ?string
    {
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        if ($modulePath === null) {
            return null;
        }

        $composerJsonPath = rtrim($modulePath, '/') . '/composer.json';
        if (!is_readable($composerJsonPath)) {
            return null;
        }

        try {
            $data = $this->json->unserialize((string)file_get_contents($composerJsonPath));
            return $data['name'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function readComposerLockVersions(): array
    {
        try {
            $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
            if (!$root->isExist('composer.lock')) {
                return [];
            }

            $data = $this->json->unserialize($root->readFile('composer.lock'));
        } catch (Throwable) {
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
