<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

/**
 * Reachability of the configured full-text search engine, via Magento's own
 * Magento\AdvancedSearch\Model\Client\ClientResolver rather than a hand-rolled HTTP call to
 * Elasticsearch/OpenSearch's REST API - this is engine-agnostic for free (works the same
 * whether the store is on Elasticsearch 5/7/8 or OpenSearch, since ClientResolver picks the
 * right client factory), and ClientInterface::testConnection() is a real ping against the
 * cluster, not just "is a hostname configured."
 */
class SearchReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    /**
     * Engines other than Elasticsearch/OpenSearch (chiefly "mysql", still a valid choice on
     * older stores) have no cluster to ping - reachable stays null rather than false, since
     * false would misleadingly suggest something is broken.
     */
    private const PINGABLE_ENGINES = ['elasticsearch5', 'elasticsearch7', 'elasticsearch8', 'opensearch'];

    public function __construct(
        private readonly ClientResolver $clientResolver
    ) {
    }

    public function getName(): string
    {
        return 'search';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $engine = $this->clientResolver->getCurrentEngine();

        if (!in_array($engine, self::PINGABLE_ENGINES, true)) {
            return ['engine' => $engine, 'reachable' => null];
        }

        try {
            $reachable = (bool)$this->clientResolver->create()->testConnection();
        } catch (Throwable) {
            $reachable = false;
        }

        return ['engine' => $engine, 'reachable' => $reachable];
    }
}
