<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Cron;

use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Model\HeartbeatSender;
use Throwable;

/**
 * 5-minute lightweight heartbeat job (see etc/crontab.xml, "stackgauge" cron group). Same
 * never-throw wrapping as SendReport - see that class's docblock for why.
 */
class SendHeartbeat
{
    /**
     * @param HeartbeatSender $heartbeatSender
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HeartbeatSender $heartbeatSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Runs the 5-minute heartbeat job - see this class's own docblock for why it never throws.
     */
    public function execute(): void
    {
        try {
            $this->heartbeatSender->send();
        } catch (Throwable $e) {
            $this->logger->critical('StackGauge: SendHeartbeat cron job failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
