<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\ComposerReporter;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ComposerReporterTest extends TestCase
{
    public function testReportsEmptyLockHashAndNoKeyPackagesWhenLockFileIsMissing(): void
    {
        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getRawContents')->willReturn(null);

        $status = (new ComposerReporter($reader, new Field(), new Section()))->getStatus();

        $this->assertSame('', $status['general']->getFields()['lock_hash']->getValue());
        $this->assertSame([], $status['key_packages']->getFields());
    }

    public function testReportsLockHashAndOnlyWatchedKeyPackages(): void
    {
        $contents = json_encode([
            'packages' => [
                ['name' => 'magento/framework', 'version' => '103.0.5'],
                ['name' => 'some/unrelated-package', 'version' => '1.0.0'],
            ],
        ]);

        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getRawContents')->willReturn($contents);
        $reader->method('getDecoded')->willReturn(json_decode($contents, true));

        $status = (new ComposerReporter($reader, new Field(), new Section()))->getStatus();

        $this->assertSame(
            'sha256:' . hash('sha256', $contents),
            $status['general']->getFields()['lock_hash']->getValue()
        );

        $keyPackages = $status['key_packages']->getFields();
        $this->assertArrayHasKey('magento/framework', $keyPackages);
        $this->assertSame('103.0.5', $keyPackages['magento/framework']->getValue());
        $this->assertArrayNotHasKey('some/unrelated-package', $keyPackages);
    }

    public function testFlagsPackagesHostedOutsideTheCommonRegistries(): void
    {
        $contents = json_encode([
            'packages' => [
                [
                    'name' => 'acme/legit-package',
                    'version' => '1.0.0',
                    'dist' => ['url' => 'https://api.github.com/repos/acme/legit-package/zipball/abc'],
                ],
                [
                    'name' => 'vendor/suspicious-package',
                    'version' => '2.0.0',
                    'dist' => ['url' => 'https://totally-not-malicious.example/download.zip'],
                ],
            ],
            'packages-dev' => [
                [
                    'name' => 'acme/private-theme',
                    'version' => '3.0.0',
                    'dist' => ['url' => 'https://acme.repo.packagist.com/download/theme.zip'],
                ],
            ],
        ]);

        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getRawContents')->willReturn($contents);
        $reader->method('getDecoded')->willReturn(json_decode($contents, true));

        $status = (new ComposerReporter($reader, new Field(), new Section()))->getStatus();
        $rows = $status['package_origins']->getRows();

        $this->assertCount(1, $rows);
        $flagged = $rows[0]->getValue();
        $this->assertSame('vendor/suspicious-package', $flagged['package']->getValue());
        $this->assertSame('totally-not-malicious.example', $flagged['host']->getValue());
    }

    public function testReportsNoPackageOriginsWhenLockFileIsMissing(): void
    {
        $reader = $this->createStub(ComposerLockReader::class);
        $reader->method('getRawContents')->willReturn(null);

        $status = (new ComposerReporter($reader, new Field(), new Section()))->getStatus();

        $this->assertSame([], $status['package_origins']->getRows());
    }
}
