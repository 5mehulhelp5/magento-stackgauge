<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\ModuleReporter;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ModuleReporterTest extends TestCase
{
    private function fieldsFor(array $rows, string $moduleName): array
    {
        foreach ($rows as $row) {
            $fields = $row->getValue();
            if ($fields['name']->getValue() === $moduleName) {
                return $fields;
            }
        }

        $this->fail("No row found for module \"{$moduleName}\".");
    }

    /**
     * A File driver double that only knows about the given path => contents map, so these
     * tests never touch the real filesystem.
     *
     * @param array<string, string> $files
     */
    private function fileDriverWithFiles(array $files): File
    {
        $filesystemDriver = $this->createMock(File::class);
        $filesystemDriver->method('isReadable')->willReturnCallback(
            static fn (string $path): bool => isset($files[$path])
        );
        $filesystemDriver->method('fileGetContents')->willReturnCallback(
            static fn (string $path) => $files[$path] ?? false
        );

        return $filesystemDriver;
    }

    private function reporterWithModuleFiles(string $modulePath, array $files, ?string $setupVersion): ModuleReporter
    {
        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getAll')->willReturn(['Acme_Foo' => ['setup_version' => $setupVersion]]);

        $enabledModuleList = $this->createStub(ModuleListInterface::class);
        $enabledModuleList->method('has')->willReturn(true);

        $componentRegistrar = $this->createStub(ComponentRegistrar::class);
        $componentRegistrar->method('getPath')->willReturn($modulePath);

        $composerLockReader = $this->createStub(ComposerLockReader::class);
        $composerLockReader->method('getDecoded')->willReturn(null);

        return new ModuleReporter(
            $fullModuleList,
            $enabledModuleList,
            $componentRegistrar,
            new Json(),
            $composerLockReader,
            $this->fileDriverWithFiles($files),
            new Field(),
            new Section()
        );
    }

    public function testFallsBackToModuleXmlSetupVersionWhenComposerLockHasNoMatch(): void
    {
        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getAll')->willReturn([
            'Magento_Catalog' => ['setup_version' => '2.4.9'],
        ]);

        $enabledModuleList = $this->createStub(ModuleListInterface::class);
        $enabledModuleList->method('has')->willReturn(true);

        // No module path resolvable - package name always null, so composer.lock (even if it
        // has data) can never match and the resolver must fall through to setup_version.
        $componentRegistrar = $this->createStub(ComponentRegistrar::class);
        $componentRegistrar->method('getPath')->willReturn(null);

        $composerLockReader = $this->createStub(ComposerLockReader::class);
        $composerLockReader->method('getDecoded')->willReturn(['packages' => []]);

        $reporter = new ModuleReporter(
            $fullModuleList,
            $enabledModuleList,
            $componentRegistrar,
            new Json(),
            $composerLockReader,
            new File(),
            new Field(),
            new Section()
        );
        $rows = $reporter->getStatus()['modules']->getRows();

        $fields = $this->fieldsFor($rows, 'Magento_Catalog');
        $this->assertSame('2.4.9', $fields['version']->getValue());
        $this->assertSame('module_xml', $fields['version_source']->getValue());
        $this->assertTrue($fields['enabled']->getValue());
    }

    public function testReportsUnknownVersionWhenNeitherComposerLockNorSetupVersionResolve(): void
    {
        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getAll')->willReturn(['Acme_Foo' => ['setup_version' => null]]);

        $enabledModuleList = $this->createStub(ModuleListInterface::class);
        $enabledModuleList->method('has')->willReturn(false);

        $componentRegistrar = $this->createStub(ComponentRegistrar::class);
        $componentRegistrar->method('getPath')->willReturn(null);

        $composerLockReader = $this->createStub(ComposerLockReader::class);
        $composerLockReader->method('getDecoded')->willReturn(null);

        $reporter = new ModuleReporter(
            $fullModuleList,
            $enabledModuleList,
            $componentRegistrar,
            new Json(),
            $composerLockReader,
            new File(),
            new Field(),
            new Section()
        );
        $rows = $reporter->getStatus()['modules']->getRows();

        $fields = $this->fieldsFor($rows, 'Acme_Foo');
        $this->assertSame('', $fields['version']->getValue());
        $this->assertSame('unknown', $fields['version_source']->getValue());
        $this->assertFalse($fields['enabled']->getValue());
    }

    /**
     * A module's own composer.json "version" field, when explicitly set, outranks
     * module.xml's setup_version - it's a direct version declaration rather than a schema
     * version a developer may or may not remember to bump.
     */
    public function testPrefersComposerJsonVersionOverSetupVersion(): void
    {
        $reporter = $this->reporterWithModuleFiles(
            '/fake/Acme/Foo',
            ['/fake/Acme/Foo/composer.json' => '{"name":"acme/foo","version":"3.2.1"}'],
            setupVersion: '1.0.0'
        );

        $fields = $this->fieldsFor($reporter->getStatus()['modules']->getRows(), 'Acme_Foo');
        $this->assertSame('3.2.1', $fields['version']->getValue());
        $this->assertSame('composer_json', $fields['version_source']->getValue());
    }

    /**
     * A module with no explicit composer.json version (the common case - most Magento modules
     * rely on VCS tags instead) falls back to a dedicated version.json before setup_version.
     */
    public function testFallsBackToVersionJsonBeforeSetupVersion(): void
    {
        $reporter = $this->reporterWithModuleFiles(
            '/fake/Acme/Foo',
            [
                '/fake/Acme/Foo/composer.json' => '{"name":"acme/foo"}',
                '/fake/Acme/Foo/version.json' => '{"version":"2.0.0-beta1"}',
            ],
            setupVersion: '1.0.0'
        );

        $fields = $this->fieldsFor($reporter->getStatus()['modules']->getRows(), 'Acme_Foo');
        $this->assertSame('2.0.0-beta1', $fields['version']->getValue());
        $this->assertSame('version_json', $fields['version_source']->getValue());
    }

    /**
     * With nothing else available - no composer.lock match, no composer.json/version.json
     * version, no setup_version - an "@version" tag in registration.php's own docblock is the
     * last resort before giving up and reporting "unknown".
     */
    public function testFallsBackToRegistrationPhpDocblockAsLastResort(): void
    {
        $reporter = $this->reporterWithModuleFiles(
            '/fake/Acme/Foo',
            [
                '/fake/Acme/Foo/registration.php' => "<?php\n/**\n * @version 0.9.1\n */\n",
            ],
            setupVersion: null
        );

        $fields = $this->fieldsFor($reporter->getStatus()['modules']->getRows(), 'Acme_Foo');
        $this->assertSame('0.9.1', $fields['version']->getValue());
        $this->assertSame('registration_php', $fields['version_source']->getValue());
    }
}
