<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\ViewGento\Api\DeclaresCadenceInterface;
use StackNuts\ViewGento\Model\ReportSender;
use Throwable;

/**
 * Daily full-collection job for the slower-cadence reporters (module inventory, patches,
 * security posture, composer/db-schema drift - anything a reporter opts into via
 * Api\DeclaresCadenceInterface returning "daily"). See etc/crontab.xml, "viewgento" cron
 * group. Wraps the whole body in try/catch for the same reason as Cron\SendReport - a cron
 * job in this module must never throw.
 */
class SendDailyReport
{
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->reportSender->send(DeclaresCadenceInterface::CADENCE_DAILY);
        } catch (Throwable $e) {
            $this->logger->critical('ViewGento: SendDailyReport cron job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
