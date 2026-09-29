<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfigInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DataSectionTrait;
use Throwable;

/**
 * Per-queue depth for every queue routed through the "amqp" connection - via the AMQP
 * protocol's own passive queue_declare (reusing Magento's existing Amqp\Config connection)
 * rather than RabbitMQ's Management HTTP API, which would need a third credential set.
 *
 * "Configured" is checked first: most Community Edition sites never set up RabbitMQ (queues
 * fall back to the "db" connection), so this reporter skips attempting a doomed connection.
 */
class RabbitMqReporter implements ReporterInterface, DeclaresSectionInterface
{
    use DataSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const AMQP_CONNECTION = 'amqp';

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param TopologyConfigInterface $topologyConfig
     * @param AmqpConfig $amqpConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly TopologyConfigInterface $topologyConfig,
        private readonly AmqpConfig $amqpConfig,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the RabbitMQ reporter.
     */
    public function getName(): string
    {
        return 'rabbitmq';
    }

    /**
     * Human-readable label for the RabbitMQ reporter block.
     */
    public function getLabel(): string
    {
        return 'RabbitMQ';
    }

    /**
     * One-line summary of what the RabbitMQ reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Per-queue message/consumer count for every queue routed through the "amqp" connection, if configured.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports whether the amqp connection is configured and reachable, plus per-queue message/consumer counts.
     */
    public function getStatus(): array
    {
        if (!$this->deploymentConfig->get('queue/amqp/host')) {
            return $this->result(false, null, []);
        }

        try {
            // Cheapest proof the broker is reachable before walking every queue - a channel
            // is required either way, so this isn't an extra round trip.
            $this->amqpConfig->getChannel();
        } catch (Throwable) {
            return $this->result(true, false, []);
        }

        $queues = [];
        foreach ($this->topologyConfig->getQueues() as $queueConfigItem) {
            if ($queueConfigItem->getConnection() !== self::AMQP_CONNECTION) {
                continue;
            }

            $queues[] = $this->checkQueue($queueConfigItem->getName());
        }

        return $this->result(true, true, $queues);
    }

    /**
     * Assembles the getStatus() payload from the connection/reachability check and queue rows.
     *
     * @param bool $configured
     * @param bool|null $reachable
     * @param list<ArrayField> $queues
     * @return array<string, \StackNuts\StackGauge\Api\Section\SectionInterface>
     */
    private function result(bool $configured, ?bool $reachable, array $queues): array
    {
        $generalFields = ['configured' => $this->field->bool('Configured', $configured)];
        if ($reachable !== null) {
            $generalFields['reachable'] = $this->field->bool('Reachable', $reachable, criticalWhen: false);
        }

        return [
            'general' => $this->section->facts('general', 'General', '', $generalFields),
            'queues' => $this->section->table('queues', 'Queues', 'Per-queue message/consumer count.', $queues),
        ];
    }

    /**
     * Passively declares $name on the amqp connection and builds its row; a missing queue is not a failure.
     *
     * @param string $name
     */
    private function checkQueue(string $name): ArrayField
    {
        try {
            // A queue declared in Magento's topology may not exist on the broker yet if no
            // consumer has ever run - NOT_FOUND is a normal outcome here, not a failure.
            [, $messageCount, $consumerCount] = $this->amqpConfig->getChannel()->queue_declare($name, true);

            return $this->queueField($name, true, $messageCount, $consumerCount);
        } catch (Throwable) {
            // A failed passive declare (e.g. NOT_FOUND) closes the channel at the protocol
            // level - getChannel() transparently reconnects on its next call, so the next
            // queue in the loop isn't affected.
            return $this->queueField($name, false, 0, 0);
        }
    }

    /**
     * Builds one queue's row.
     *
     * @param string $name
     * @param bool $exists
     * @param int $messages
     * @param int $consumers
     */
    private function queueField(string $name, bool $exists, int $messages, int $consumers): ArrayField
    {
        return $this->field->array($name, [
            'name' => $this->field->varchar('Name', $name),
            // Not criticalWhen:false - NOT_FOUND is a normal outcome for a queue that's
            // never had a consumer run yet (see checkQueue()), not a health signal.
            'exists' => $this->field->bool('Exists', $exists),
            'messages' => $this->field->number('Messages', $messages),
            'consumers' => $this->field->number('Consumers', $consumers),
        ]);
    }
}
