<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use StackNuts\ViewGento\Api\ReporterInterface;

/**
 * "alive" plus last successful run per job code. Magento's cron_schedule table doesn't
 * record which crontab.xml <group> a job belongs to (only its job_code), so this reports
 * per job code rather than per scheduler group - a finer-grained and equally useful unit,
 * without needing to parse and cross-reference the merged cron config at runtime.
 */
class CronReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';
    private const ALIVE_THRESHOLD_MINUTES = 30;

    /**
     * Recent window rather than the whole history table - cron_schedule can carry a long
     * tail of old rows on a busy store, and only recent activity is relevant to "is this
     * running right now."
     */
    private const ROW_LIMIT = 500;

    public function __construct(
        private readonly CollectionFactory $scheduleCollectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'cron';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $collection = $this->scheduleCollectionFactory->create();
        $collection->setOrder('schedule_id', 'DESC')
            ->setPageSize(self::ROW_LIMIT);

        $mostRecentCreatedAt = null;
        $lastSuccessByJob = [];

        foreach ($collection as $schedule) {
            $createdAt = $schedule->getCreatedAt();
            if ($createdAt !== null && ($mostRecentCreatedAt === null || $createdAt > $mostRecentCreatedAt)) {
                $mostRecentCreatedAt = $createdAt;
            }

            if ($schedule->getStatus() === 'success') {
                $jobCode = $schedule->getJobCode();
                $finishedAt = $schedule->getFinishedAt();
                if ($finishedAt !== null
                    && (!isset($lastSuccessByJob[$jobCode]) || $finishedAt > $lastSuccessByJob[$jobCode])
                ) {
                    $lastSuccessByJob[$jobCode] = $finishedAt;
                }
            }
        }

        $alive = $mostRecentCreatedAt !== null
            && (time() - strtotime($mostRecentCreatedAt)) <= self::ALIVE_THRESHOLD_MINUTES * 60;

        return [
            'alive' => $alive,
            'last_schedule_generated_at' => $mostRecentCreatedAt,
            'jobs' => $lastSuccessByJob,
        ];
    }
}
