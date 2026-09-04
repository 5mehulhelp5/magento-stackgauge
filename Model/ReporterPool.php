<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\SectionInterface;
use StackNuts\StackGauge\Model\System\Config\Source\ReporterList;
use Throwable;

/**
 * Runs every registered reporter (built-in and third-party, collected via the "reporters"
 * di.xml array argument) and assembles the "reporters" block of the payload. A reporter
 * that throws - including one that returns something other than a Section for any key,
 * which this class checks explicitly since PHP can't express "array<string, SectionInterface>"
 * as an enforceable native return type - never blocks the others or aborts the send; its
 * block becomes {"error": "..."} instead, so a broken third-party integration degrades
 * gracefully rather than silently dropping the whole report. Shape validation itself (is
 * this section flat? do every table row's columns match?) lives on FactsSection/TableSection
 * now, at construction time - this class no longer guesses at a reporter's intended shape.
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
     * @return ReporterInterface[]
     */
    public function getReporters(): array
    {
        return $this->reporters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function collect(string $cadence = DeclaresCadenceInterface::CADENCE_HOURLY): array
    {
        $enabledCodes = $this->config->getEnabledReporterCodes();
        $result = [];

        foreach ($this->reporters as $reporter) {
            if (!$reporter instanceof ReporterInterface) {
                continue;
            }

            // A reporter with no cadence declaration is always hourly - this is what keeps
            // every existing built-in reporter's behaviour unchanged by this feature.
            $reporterCadence = $reporter instanceof DeclaresCadenceInterface
                ? $reporter->getCadence()
                : DeclaresCadenceInterface::CADENCE_HOURLY;

            if ($reporterCadence !== $cadence) {
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
                    'StackGauge: duplicate reporter name "%s" registered - the later one overwrites the earlier block.',
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
            $sections = $reporter->getStatus();

            foreach ($sections as $key => $section) {
                if (!$section instanceof SectionInterface) {
                    throw new InvalidArgumentException(sprintf(
                        'section "%s" must be a StackNuts\StackGauge\Api\Section\SectionInterface instance, got %s',
                        $key,
                        get_debug_type($section)
                    ));
                }
            }

            return [
                'schema_version' => $reporter->getSchemaVersion(),
                'label' => $reporter->getLabel(),
                'description' => $reporter->getDescription(),
                // An ordered list, not a map - display order matters, and each section already
                // carries its own key (see SectionInterface::getKey()).
                'sections' => array_values($sections),
            ];
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'StackGauge: reporter "%s" failed: %s',
                $reporter->getName(),
                $e->getMessage()
            ), ['exception' => $e]);

            return ['error' => $e->getMessage()];
        }
    }
}
