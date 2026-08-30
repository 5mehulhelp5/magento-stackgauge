<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfigInterface;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Per-queue depth for every queue actually routed through the "amqp" connection - not via
 * RabbitMQ's Management HTTP API (which would mean a third credential set to manage per
 * site, on top of the AMQP broker credentials the app already has), but via the AMQP
 * protocol's own passive queue_declare, reusing the exact same Magento\Framework\Amqp\Config
 * connection the application already uses to publish/consume. No new credentials at all.
 *
 * "Configured" is checked first and reported as its own field - most Community Edition
 * sites never set up RabbitMQ (queues fall back to the "db" connection instead), so this
 * reporter does nothing at all rather than attempting a doomed connection every cycle.
 */
class RabbitMqReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';
    private const AMQP_CONNECTION = 'amqp';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly TopologyConfigInterface $topologyConfig,
        private readonly AmqpConfig $amqpConfig
    ) {
    }

    public function getName(): string
    {
        return 'rabbitmq';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        if (!$this->deploymentConfig->get('queue/amqp/host')) {
            return ['configured' => false];
        }

        try {
            // Cheapest possible proof the broker itself is reachable before walking every
            // queue - a channel is required either way, so this isn't extra round trips.
            $this->amqpConfig->getChannel();
        } catch (Throwable) {
            return ['configured' => true, 'reachable' => false, 'queues' => []];
        }

        $queues = [];
        foreach ($this->topologyConfig->getQueues() as $queueConfigItem) {
            if ($queueConfigItem->getConnection() !== self::AMQP_CONNECTION) {
                continue;
            }

            $queues[] = $this->checkQueue($queueConfigItem->getName());
        }

        return ['configured' => true, 'reachable' => true, 'queues' => $queues];
    }

    /**
     * @return array{name: string, exists: bool, messages: int, consumers: int}
     */
    private function checkQueue(string $name): array
    {
        try {
            // A queue declared in Magento's topology may not exist on the broker yet if no
            // consumer has ever run - NOT_FOUND is a normal outcome here, not a failure.
            [, $messageCount, $consumerCount] = $this->amqpConfig->getChannel()->queue_declare($name, true);

            return ['name' => $name, 'exists' => true, 'messages' => $messageCount, 'consumers' => $consumerCount];
        } catch (Throwable) {
            // A failed passive declare (e.g. NOT_FOUND) closes the channel at the protocol
            // level - getChannel() transparently reconnects on its next call, so the next
            // queue in the loop isn't affected.
            return ['name' => $name, 'exists' => false, 'messages' => 0, 'consumers' => 0];
        }
    }
}
