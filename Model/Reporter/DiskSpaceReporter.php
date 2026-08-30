<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Free/total disk space for var/log, var/cache, and media - the three areas the original
 * data-collection list called out by name. Often the same underlying filesystem/mount on a
 * typical install (so values may be identical across all three), but not guaranteed - media
 * in particular is sometimes on a separate volume - so each is checked independently rather
 * than assumed to share one answer. Pure PHP (disk_free_space/disk_total_space), no new
 * dependency and no network call.
 */
class DiskSpaceReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

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

        return ['volumes' => $volumes];
    }

    /**
     * @return array{purpose: string, free_bytes: ?int, total_bytes: ?int, free_percent: ?float}
     */
    private function checkVolume(string $purpose, string $directoryCode): array
    {
        try {
            $path = $this->filesystem->getDirectoryRead($directoryCode)->getAbsolutePath();
            $free = disk_free_space($path);
            $total = disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                return ['purpose' => $purpose, 'free_bytes' => null, 'total_bytes' => null, 'free_percent' => null];
            }

            return [
                'purpose' => $purpose,
                'free_bytes' => (int)$free,
                'total_bytes' => (int)$total,
                'free_percent' => round(($free / $total) * 100, 1),
            ];
        } catch (Throwable) {
            return ['purpose' => $purpose, 'free_bytes' => null, 'total_bytes' => null, 'free_percent' => null];
        }
    }
}
