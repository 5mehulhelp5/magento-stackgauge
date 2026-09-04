<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;

/**
 * "alive" plus, per job code, when it last succeeded and when it's next due. Magento's
 * cron_schedule table doesn't record which crontab.xml <group> a job belongs to (only its
 * job_code), so this reports per job code rather than per scheduler group - a finer-grained
 * and equally useful unit, without needing to parse and cross-reference the merged cron
 * config at runtime. "Next due" comes from the earliest still-pending schedule row per job
 * (Magento's own cron:run schedules ahead of time - see Config::SCHEDULE_AHEAD_FOR - so a
 * pending row already exists for the next occurrence) rather than parsing the job's cron
 * expression, which would need the merged crontab.xml config this class deliberately avoids.
 * If cron has stopped running altogether, that earliest pending row's scheduled_at is simply
 * in the past - the dashboard computes "overdue" from that, this reporter just states facts.
 */
class CronReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '4.0';
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

    public function getLabel(): string
    {
        return 'Cron';
    }

    public function getDescription(): string
    {
        return 'Whether cron looks alive, plus last success and next due time per job code.';
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
        $nextScheduledByJob = [];

        foreach ($collection as $schedule) {
            $createdAt = $schedule->getCreatedAt();
            if ($createdAt !== null && ($mostRecentCreatedAt === null || $createdAt > $mostRecentCreatedAt)) {
                $mostRecentCreatedAt = $createdAt;
            }

            $jobCode = $schedule->getJobCode();

            if ($schedule->getStatus() === 'success') {
                $finishedAt = $schedule->getFinishedAt();
                if ($finishedAt !== null
                    && (!isset($lastSuccessByJob[$jobCode]) || $finishedAt > $lastSuccessByJob[$jobCode])
                ) {
                    $lastSuccessByJob[$jobCode] = $finishedAt;
                }
            }

            if ($schedule->getStatus() === 'pending') {
                $scheduledAt = $schedule->getScheduledAt();
                if ($scheduledAt !== null
                    && (!isset($nextScheduledByJob[$jobCode]) || $scheduledAt < $nextScheduledByJob[$jobCode])
                ) {
                    $nextScheduledByJob[$jobCode] = $scheduledAt;
                }
            }
        }

        // cron_schedule's timestamps are always UTC (Magento convention) - strtotime() alone
        // would interpret the naive string using PHP's *current* default timezone instead,
        // which framework code elsewhere (Magento\Framework\Stdlib\DateTime\Timezone) can
        // silently mutate process-wide via date_default_timezone_set() as a side effect of
        // resolving the admin/store timezone. Appending " UTC" pins the interpretation
        // regardless of that ambient global state.
        $alive = $mostRecentCreatedAt !== null
            && (time() - strtotime($mostRecentCreatedAt.' UTC')) <= self::ALIVE_THRESHOLD_MINUTES * 60;

        $jobCodes = array_unique(array_merge(array_keys($lastSuccessByJob), array_keys($nextScheduledByJob)));

        $jobRows = [];
        foreach ($jobCodes as $jobCode) {
            $jobRows[] = Field::array($jobCode, [
                'job_code' => Field::varchar('Job', $jobCode),
                'last_success_at' => Field::varchar('Last Success', $lastSuccessByJob[$jobCode] ?? ''),
                'next_scheduled_at' => Field::varchar('Next Scheduled', $nextScheduledByJob[$jobCode] ?? ''),
            ]);
        }

        return [
            'general' => Section::facts('general', 'General', 'Whether cron looks alive, and when the schedule was last generated.', [
                'alive' => Field::bool('Alive', $alive, criticalWhen: false),
                'last_schedule_generated_at' => Field::varchar('Last Schedule Generated At', $mostRecentCreatedAt ?? ''),
            ]),
            'jobs' => Section::table('jobs', 'Jobs', 'Per-job last success and next scheduled time.', $jobRows, keyName: 'job_code'),
        ];
    }
}
