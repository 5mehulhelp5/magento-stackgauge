<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StackNuts\ViewGento\Model\Reporter\ThemeReporter;

class ThemeReporterTest extends TestCase
{
    public function testGetStatusReturnsThemes(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['design/theme/theme_id', 'stores', null, 'frontend_theme'],
            ['design/theme/theme_id', null, null, 'admin_theme'],
        ]);

        $reporter = new ThemeReporter($scopeConfig);
        $status = $reporter->getStatus();

        $this->assertArrayHasKey('themes', $status);
    }
}
