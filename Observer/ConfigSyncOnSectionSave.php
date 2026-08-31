<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use StackNuts\ViewGento\Model\ReportSender;
use Throwable;

/**
 * Fires an immediate config-sync whenever this module's own system.xml section is saved (e.g.
 * toggling which reporters are enabled) - the daily cron would eventually pick this up, but
 * there's no reason to wait a day for a change an admin just made deliberately. Bound to the
 * admin_system_config_changed_section_stacknuts_viewgento event, which Magento fires
 * specifically for this section - see etc/adminhtml/events.xml. Uses sendConfigSyncNow()
 * (bypasses the "Enabled" toggle), same mid-setup rationale as the Test Ping button.
 */
class ConfigSyncOnSectionSave implements ObserverInterface
{
    public function __construct(
        private readonly ReportSender $reportSender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        try {
            $this->reportSender->sendConfigSyncNow();
        } catch (Throwable $e) {
            $this->logger->warning(
                'ViewGento: config sync on section save failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
