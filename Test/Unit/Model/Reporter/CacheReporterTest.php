<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use StackNuts\StackGauge\Model\Reporter\CacheReporter;

class CacheReporterTest extends TestCase
{
    public function testFullPageCacheComesBeforeTypes(): void
    {
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([
            'config' => ['status' => 1],
        ]);

        $pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $pageCacheConfig->method('getType')->willReturn(PageCacheConfig::BUILT_IN);
        $pageCacheConfig->method('isEnabled')->willReturn(true);

        $reporter = new CacheReporter($typeList, $pageCacheConfig);
        $status = $reporter->getStatus();

        $this->assertSame(['full_page_cache', 'types'], array_keys($status));
    }

    public function testCacheTypeStatusIsABoolCriticalWhenDisabled(): void
    {
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([
            'config' => ['status' => 1],
            'layout' => ['status' => 0],
        ]);

        $pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $pageCacheConfig->method('getType')->willReturn(PageCacheConfig::BUILT_IN);
        $pageCacheConfig->method('isEnabled')->willReturn(true);

        $reporter = new CacheReporter($typeList, $pageCacheConfig);
        $rows = $reporter->getStatus()['types']->getRows();

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->getValue()['name']->getValue()] = $row->getValue();
        }

        $enabled = $byName['config']['enabled'];
        $disabled = $byName['layout']['enabled'];

        $this->assertTrue($enabled->getValue());
        $this->assertFalse($disabled->getValue());
        $this->assertFalse($enabled->jsonSerialize()['critical_when']);
    }

    public function testAnUnrecognisedTypeIdIsReportedAsCustomWithTheRawIdStillAvailable(): void
    {
        // StackNuts\CloudflareCache\Model\Config::TYPE_CLOUDFLARE = 3 - a real FPC type
        // registered via that module's plugin on Magento's own Application config source,
        // not something this reporter has a built-in label for, so it falls to "custom"
        // here while the raw id stays available via the sibling type_id field.
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([]);

        $pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $pageCacheConfig->method('getType')->willReturn(3);
        $pageCacheConfig->method('isEnabled')->willReturn(true);

        $reporter = new CacheReporter($typeList, $pageCacheConfig);
        $fpc = $reporter->getStatus()['full_page_cache']->getFields();

        $this->assertSame('custom', $fpc['type_label']->getValue());
        $this->assertSame(3, $fpc['type_id']->getValue());
    }
}
