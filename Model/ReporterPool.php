<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model;

use Psr\Log\LoggerInterface;
use StackNuts\ViewGento\Api\ReporterInterface;
use StackNuts\ViewGento\Model\System\Config\Source\ReporterList;
use Throwable;

/**
 * Runs every registered reporter (built-in and third-party, collected via the "reporters"
 * di.xml array argument) and assembles the "reporters" block of the payload. A reporter
 * that throws never blocks the others or aborts the send - its block becomes
 * {"error": "..."} instead, so a broken third-party integration degrades gracefully rather
 * than silently dropping the whole report.
 */
class ReporterPool
{
    /**
     * @param ReporterInterface[] $reporters
     */
    public function __construct(
        private readonly array $reporters,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function collect(): array
    {
        $enabledCodes = $this->config->getEnabledReporterCodes();
        $result = [];

        foreach ($this->reporters as $reporter) {
            if (!$reporter instanceof ReporterInterface) {
                continue;
            }

            $name = $reporter->getName();

            // Only built-in reporter codes are subject to the admin toggle; a third-party
            // reporter's name won't be in ReporterList::CODES, so it always runs.
            if (in_array($name, ReporterList::CODES, true) && !in_array($name, $enabledCodes, true)) {
                continue;
            }

            if (isset($result[$name])) {
                $this->logger->warning(sprintf(
                    'ViewGento: duplicate reporter name "%s" registered - the later one overwrites the earlier block.',
                    $name
                ));
            }

            $result[$name] = $this->collectOne($reporter);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectOne(ReporterInterface $reporter): array
    {
        try {
            return array_merge(['schema_version' => $reporter->getSchemaVersion()], $reporter->getStatus());
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'ViewGento: reporter "%s" failed: %s',
                $reporter->getName(),
                $e->getMessage()
            ), ['exception' => $e]);

            return ['error' => $e->getMessage()];
        }
    }
}
