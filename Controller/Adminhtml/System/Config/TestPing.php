<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use StackNuts\ViewGento\Model\Config;
use StackNuts\ViewGento\Model\ReportSender;
use Throwable;

/**
 * Verifies the configured endpoint URL/API key by sending one real full report - a genuine
 * exercise of the same send path the hourly cron uses, not a credential-only check that
 * could pass while the actual send fails. Runs regardless of the "Enabled" toggle, since an
 * admin testing the connection is very likely still mid-setup with it switched off. Also
 * fires a config-sync alongside the full report, so this doubles as a manual "sync now"
 * affordance - e.g. right after installing a module with new trackable metrics, without
 * waiting on the daily config-sync cron.
 */
class TestPing extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ReportSender $reportSender
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
            $reportSent = $this->reportSender->sendNow();
        } catch (Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Sending the test report threw an error: %1', $e->getMessage())->render(),
            ]);
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
                'The dashboard did not accept the report. Check var/log/stacknuts_viewgento.log for details.'
            )->render(),
        ]);
    }
}
