<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use StackNuts\ViewGento\Api\Field\ArrayField;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\MetricCatalogInterface;
use StackNuts\ViewGento\Api\MetricDefinition;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Free/total disk space for var/log, var/cache, and media - the three areas the original
 * data-collection list called out by name. Often the same underlying filesystem/mount on a
 * typical install (so values may be identical across all three), but not guaranteed - media
 * in particular is sometimes on a separate volume - so each is checked independently rather
 * than assumed to share one answer. Pure PHP (disk_free_space/disk_total_space), no new
 * dependency and no network call.
 *
 * Media's free-percent is the v1 example of a trackable metric (see MetricCatalogInterface) -
 * it's real, already-collected data, so it proves the alerting pipeline end-to-end without
 * inventing a new reporter just to have something to alert on. Other reporters adopt the same
 * pattern independently as it becomes useful for them.
 */
class DiskSpaceReporter implements ReporterInterface, MetricCatalogInterface
{
    private const SCHEMA_VERSION = '2.0';
    private const METRIC_MEDIA_FREE_PERCENT = 'disk.media.free_percent';

    /**
     * @var array<string, string>
     */
    private const DIRECTORIES = [
        'var_log' => DirectoryList::LOG,
        'var_cache' => DirectoryList::CACHE,
        'media' => DirectoryList::MEDIA,
    ];

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'disk';
    }

    public function getLabel(): string
    {
        return 'Disk Space';
    }

    public function getDescription(): string
    {
        return 'Free/total bytes for var/log, var/cache, and media, checked independently.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $volumes = [];

        foreach (self::DIRECTORIES as $purpose => $directoryCode) {
            $volumes[] = $this->checkVolume($purpose, $directoryCode);
        }

        return ['volumes' => Field::array('Volumes', $volumes)];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MEDIA_FREE_PERCENT,
                'Disk Space: Media Free %',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                10,
                15
            ),
        ];
    }

    private function checkVolume(string $purpose, string $directoryCode): ArrayField
    {
        try {
            $path = $this->filesystem->getDirectoryRead($directoryCode)->getAbsolutePath();
            $free = disk_free_space($path);
            $total = disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                return $this->volumeField($purpose, false, 0, 0, 0.0);
            }

            return $this->volumeField($purpose, true, (int)$free, (int)$total, round(($free / $total) * 100, 1));
        } catch (Throwable) {
            return $this->volumeField($purpose, false, 0, 0, 0.0);
        }
    }

    private function volumeField(
        string $purpose,
        bool $measurable,
        int $freeBytes,
        int $totalBytes,
        float $freePercent
    ): ArrayField {
        $freePercentField = $purpose === 'media'
            ? Field::trackableNumber(
                'Free Percent',
                $freePercent,
                self::METRIC_MEDIA_FREE_PERCENT,
                MetricDefinition::AGGREGATION_LATEST
            )
            : Field::number('Free Percent', $freePercent);

        return Field::array($purpose, [
            'purpose' => Field::varchar('Purpose', $purpose),
            'measurable' => Field::bool('Measurable', $measurable),
            'free_bytes' => Field::number('Free Bytes', $freeBytes),
            'total_bytes' => Field::number('Total Bytes', $totalBytes),
            'free_percent' => $freePercentField,
        ]);
    }
}
