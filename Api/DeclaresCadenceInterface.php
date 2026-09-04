<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Api;

/**
 * Optional companion to ReporterInterface, letting a reporter opt into the slower "daily"
 * cadence instead of the default hourly one - for data that doesn't change hour to hour
 * (module inventory, patches, security posture) and doesn't need to ride along on every
 * hourly full report. A reporter that doesn't implement this is always collected at hourly
 * cadence - this is deliberately not added to ReporterInterface itself, so none of the
 * existing built-in reporters need touching to preserve their current behaviour.
 */
interface DeclaresCadenceInterface
{
    public const CADENCE_HOURLY = 'hourly';
    public const CADENCE_DAILY = 'daily';

    public function getCadence(): string;
}
