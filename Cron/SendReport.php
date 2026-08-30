<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\ViewGento\Model\ReportSender;
use Throwable;

/**
 * Hourly full-collection job (see etc/crontab.xml, "viewgento" cron group). Wraps the whole
 * body in try/catch - a cron job in this module must never throw, since Magento's cron
 * runner treats an uncaught exception here as a failed job, and a broken dashboard/network
 * shouldn't affect any other scheduled task on the site.
 */
class SendReport
{
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->reportSender->send();
        } catch (Throwable $e) {
            $this->logger->critical('ViewGento: SendReport cron job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
