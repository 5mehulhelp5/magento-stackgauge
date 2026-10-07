<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter\Concern;

use StackNuts\StackGauge\Api\DeclaresSectionInterface;

/**
 * Default DeclaresSectionInterface::getSection() for reporters in the Security domain
 * category.
 */
trait SecuritySectionTrait
{
    /**
     * Always DeclaresSectionInterface::SECTION_SECURITY.
     */
    public function getSection(): string
    {
        return DeclaresSectionInterface::SECTION_SECURITY;
    }
}
