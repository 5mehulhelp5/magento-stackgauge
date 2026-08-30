<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model;

use Psr\Log\LoggerInterface;

/**
 * Orchestrates the full-collection report: build the payload, log it, then send it. Used by
 * Cron\SendReport (gated on the "Enabled" toggle, via send()), the CLI command (dry-run
 * calls buildPayload() directly; --force calls sendNow()), and the admin Test Ping button
 * (always sendNow(), since an explicit admin click should work even before "Enabled" is
 * switched on while the site is still being configured).
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
    public function buildPayload(): array
    {
        return $this->payloadBuilder->build();
    }

    /**
     * Respects the admin "Enabled" toggle - this is what the cron job calls.
     */
    public function send(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->sendNow();
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
    public function sendNow(): bool
    {
        $payload = $this->buildPayload();
        $this->logger->info('ViewGento: full report ' . json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $this->transport->send($payload, 'report');
    }
}
