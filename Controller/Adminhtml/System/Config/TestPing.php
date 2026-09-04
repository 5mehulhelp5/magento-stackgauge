<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Throwable;

/**
 * Verifies the endpoint URL/API key by sending one real full report of *every* cadence tier
 * - a genuine exercise of the same send path the hourly/daily crons use, not a
 * credential-only check. Sending hourly only (ReportSender::sendNow()'s default) would
 * silently omit every daily-cadence reporter (composer, modules, security, ...) from this
 * "prove it all works" click. Runs regardless of the "Enabled" toggle, since an admin
 * testing the connection is likely still mid-setup with it switched off. Also fires a
 * config-sync alongside the full reports, doubling as a manual "sync now" affordance.
 */
class TestPing extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ReportSender $reportSender
        , private readonly HeartbeatSender $heartbeatSender
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->getEndpointUrl() || !$this->config->getApiKey()) {
            return $result->setData([
                'success' => false,
                'message' => __('Enter and save both the Dashboard Endpoint URL and API Key first, then test.')->render(),
            ]);
        }

        try {
            $hourlySent = $this->reportSender->sendNow(DeclaresCadenceInterface::CADENCE_HOURLY);
            $dailySent = $this->reportSender->sendNow(DeclaresCadenceInterface::CADENCE_DAILY);
            $reportSent = $hourlySent && $dailySent;
        } catch (Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Sending the test report threw an error: %1', $e->getMessage())->render(),
            ]);
        }

        // Send a heartbeat too (unconditionally) so the admin sees an immediate
        // lightweight alive ping alongside the full report.
        try {
            $this->heartbeatSender->sendNow();
        } catch (Throwable) {
            // best-effort, don't block the report result on heartbeat
        }

        // Best-effort - a config-sync failure shouldn't mask a successful report test, and
        // the daily cron/section-save observer will catch it up regardless.
        try {
            $this->reportSender->sendConfigSyncNow();
        } catch (Throwable) {
            // Intentionally ignored - see comment above.
        }

        if ($reportSent) {
            return $result->setData([
                'success' => true,
                'message' => __('Success! A full report was sent to the dashboard.')->render(),
            ]);
        }

        return $result->setData([
            'success' => false,
            'message' => __(
                'The dashboard did not accept the report. Check var/log/stacknuts_stackgauge.log for details.'
            )->render(),
        ]);
    }
}
