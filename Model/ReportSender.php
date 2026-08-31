<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model;

use Psr\Log\LoggerInterface;
use StackNuts\ViewGento\Api\DeclaresCadenceInterface;

/**
 * Orchestrates both the full-collection report and the config-sync send: build the payload,
 * log it, then send it. Used by Cron\SendReport/SendDailyReport (gated on the "Enabled"
 * toggle, via send()/sendConfigSync()), the CLI command (dry-run calls buildPayload()/
 * buildConfigSync() directly; --force calls sendNow()), the admin Test Ping button (always
 * sendNow()/sendConfigSyncNow(), since an explicit admin click should work even before
 * "Enabled" is switched on while the site is still being configured), and
 * Observer\ConfigSyncOnSectionSave (always sendConfigSyncNow(), same mid-setup rationale).
 */
class ReportSender
{
    public function __construct(
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly Transport $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): array
    {
        return $this->payloadBuilder->build($cadence);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConfigSync(): array
    {
        return $this->payloadBuilder->buildConfigSync();
    }

    /**
     * Respects the admin "Enabled" toggle - this is what the hourly/daily cron jobs call.
     */
    public function send(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendNow($cadence);
    }

    /**
     * Respects the admin "Enabled" toggle - this is what Cron\SendConfigSync calls.
     */
    public function sendConfigSync(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendConfigSyncNow();
    }

    /**
     * Sends unconditionally, ignoring the "Enabled" toggle. Still requires an endpoint URL
     * and API key to be configured for the actual HTTP send (enforced by Transport) - but
     * the payload is built and logged either way. With Log Level set to "Info", this makes
     * the hourly cron useful on its own even before a dashboard endpoint exists to send to:
     * var/log/stacknuts_viewgento.log fills up with a full, real snapshot every run, that
     * can be greped/tailed/piped to `jq` directly - a "just enable it and read the log"
     * fallback for the first-instance use case, not just a debugging aid.
     */
    public function sendNow(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): bool
    {
        $payload = $this->buildPayload($cadence);
        $this->logger->info("ViewGento: full report ({$cadence}) " . json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $this->transport->send($payload, "report ({$cadence})");
    }

    /**
     * Sends the config-sync payload unconditionally, ignoring the "Enabled" toggle - same
     * mid-setup rationale as sendNow().
     */
    public function sendConfigSyncNow(): bool
    {
        $payload = $this->buildConfigSync();
        $this->logger->info('ViewGento: config sync ' . json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $this->transport->send($payload, 'config sync');
    }
}
