<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Model\ReporterPool;

/**
 * Options for the admin "Disabled Reporters" multiselect - one per reporter actually
 * registered with Model\ReporterPool, built-in or third-party alike. A third-party module
 * doesn't need a separate registration step to become individually toggleable here: it only
 * ever has to register its reporter the same way every built-in one already does (via the
 * "reporters" di.xml array argument on ReporterPool), and this list picks it up automatically.
 * Nothing checked (the default) means every reporter runs - see
 * Model\Config::getDisabledReporterCodes().
 */
class ReporterList implements OptionSourceInterface
{
    /**
     * @param ReporterPool $reporterPool
     */
    public function __construct(private readonly ReporterPool $reporterPool)
    {
    }

    /**
     * One option per registered reporter, labelled with its own getLabel().
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->reporterPool->getReporters() as $reporter) {
            if ($reporter instanceof ReporterInterface) {
                $options[] = ['value' => $reporter->getName(), 'label' => $reporter->getLabel()];
            }
        }

        return $options;
    }
}
