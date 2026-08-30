<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * MySQL/MariaDB version and connection health. The cheapest possible reporter in this
 * module - every other reporter that touches the database (DbSchemaReporter, CronReporter,
 * RabbitMqReporter's topology lookup) already implies a working connection, so this is just
 * one extra SELECT VERSION() on a connection that's already open.
 */
class DatabaseReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '2.0';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function getLabel(): string
    {
        return 'Database';
    }

    public function getDescription(): string
    {
        return 'MySQL/MariaDB version and reachability.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        try {
            $versionString = (string)$this->resourceConnection->getConnection()->fetchOne('SELECT VERSION()');

            return [
                'reachable' => Field::bool('Reachable', true),
                'version' => Field::varchar('Version', $versionString),
                'distribution' => Field::varchar(
                    'Distribution',
                    stripos($versionString, 'mariadb') !== false ? 'mariadb' : 'mysql'
                ),
            ];
        } catch (Throwable) {
            return [
                'reachable' => Field::bool('Reachable', false),
                'version' => Field::varchar('Version', ''),
                'distribution' => Field::varchar('Distribution', ''),
            ];
        }
    }
}
