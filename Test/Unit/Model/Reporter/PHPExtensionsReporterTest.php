<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\ViewGento\Model\Reporter\PHPExtensionsReporter;

class PHPExtensionsReporterTest extends TestCase
{
    public function testGetStatusReturnsExtensions(): void
    {
        $reporter = new PHPExtensionsReporter();
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('php_extensions', $status);
        $this->assertArrayHasKey('php_settings', $status);
    }
}
