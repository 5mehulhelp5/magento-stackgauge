<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\DiskSpaceReporter;

/**
 * disk_free_space()/disk_total_space() are real filesystem calls, not something Filesystem
 * mocking can intercept - so these tests drive volumeField() directly via reflection with
 * controlled inputs, rather than exercising getStatus()'s real I/O path.
 */
class DiskSpaceReporterTest extends TestCase
{
    private function volumeField(string $purpose, bool $measurable, float $freePercent): array
    {
        $reporter = new DiskSpaceReporter($this->createMock(Filesystem::class), new File(), new Field(), new Section());

        $method = new ReflectionMethod(DiskSpaceReporter::class, 'volumeField');

        return $method->invoke($reporter, $purpose, $measurable, 0, 0, $freePercent)->getValue();
    }

    public function testFreePercentIsCriticalBelowTenPercent(): void
    {
        $fields = $this->volumeField('media', true, 9.9);

        $this->assertSame('critical', $fields['free_percent']->jsonSerialize()['severity']);
    }

    public function testFreePercentIsOkAtOrAboveTenPercent(): void
    {
        $fields = $this->volumeField('media', true, 10.0);

        $this->assertSame('ok', $fields['free_percent']->jsonSerialize()['severity']);
    }

    public function testFreePercentHasNoSeverityWhenUnmeasurable(): void
    {
        $fields = $this->volumeField('media', false, 0.0);

        $this->assertArrayNotHasKey('severity', $fields['free_percent']->jsonSerialize());
    }

    public function testNonMediaVolumesGetTheSameSeverityTreatment(): void
    {
        $fields = $this->volumeField('var_log', true, 5.0);

        $this->assertSame('critical', $fields['free_percent']->jsonSerialize()['severity']);
    }

    /**
     * disk_free_space()/disk_total_space() emit a PHP warning (not just a false return) when
     * given a nonexistent path - e.g. var/cache never gets provisioned on disk at all for a
     * site using an external cache backend like Redis. checkVolume() must guard against the
     * path missing entirely rather than relying on the false-fallback handling alone, which
     * doesn't suppress the warning that already fired.
     */
    public function testUnmeasurableWhenTheDirectoryDoesNotExistOnDisk(): void
    {
        $directoryRead = $this->createMock(ReadInterface::class);
        $directoryRead->method('getAbsolutePath')->willReturn('/nonexistent/path/for/stackgauge-test');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::CACHE)->willReturn($directoryRead);

        $reporter = new DiskSpaceReporter($filesystem, new File(), new Field(), new Section());
        $method = new ReflectionMethod(DiskSpaceReporter::class, 'checkVolume');

        set_error_handler(static function (int $errno, string $errstr): never {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING);

        try {
            $fields = $method->invoke($reporter, 'var_cache', DirectoryList::CACHE)->getValue();
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($fields['measurable']->getValue());
        $this->assertArrayNotHasKey('severity', $fields['free_percent']->jsonSerialize());
    }
}
