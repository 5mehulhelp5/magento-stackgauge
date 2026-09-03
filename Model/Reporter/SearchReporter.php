<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\OpenSearch\Model\SearchClient;
use Magento\Store\Model\StoreManagerInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use Throwable;

/**
 * Reachability of the configured full-text search engine, via Magento's own
 * Magento\AdvancedSearch\Model\Client\ClientResolver rather than a hand-rolled HTTP call to
 * Elasticsearch/OpenSearch's REST API - this is engine-agnostic for free (works the same
 * whether the store is on Elasticsearch 5/7/8 or OpenSearch, since ClientResolver picks the
 * right client factory), and ClientInterface::testConnection() is a real ping against the
 * cluster, not just "is a hostname configured."
 *
 * Also reports the product index's document count - "reachable" alone doesn't catch the case
 * where the cluster answers fine but the index itself is empty/missing (a real incident this
 * was added for).
 */
class SearchReporter implements ReporterInterface, MetricCatalogInterface
{
    private const SCHEMA_VERSION = '3.0';
    private const METRIC_INDEX_DOCUMENT_COUNT = 'search.index_document_count';

    /**
     * Engines other than Elasticsearch/OpenSearch (chiefly "mysql", still a valid choice on
     * older stores) have no cluster to ping - "pingable" stays false rather than reporting a
     * misleading "reachable: false", since that would suggest something is broken rather
     * than simply not applicable to this engine.
     */
    private const PINGABLE_ENGINES = ['elasticsearch5', 'elasticsearch7', 'elasticsearch8', 'opensearch'];

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly SearchIndexNameResolver $searchIndexNameResolver,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getName(): string
    {
        return 'search';
    }

    public function getLabel(): string
    {
        return 'Search';
    }

    public function getDescription(): string
    {
        return 'Configured search engine and whether it is actually reachable (Elasticsearch/OpenSearch only).';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $engine = $this->clientResolver->getCurrentEngine();
        $pingable = in_array($engine, self::PINGABLE_ENGINES, true);

        $reachable = false;
        if ($pingable) {
            try {
                $reachable = (bool)$this->clientResolver->create()->testConnection();
            } catch (Throwable) {
                $reachable = false;
            }
        }

        $fields = [
            'engine' => Field::varchar('Engine', $engine),
            'pingable' => Field::bool('Pingable', $pingable, criticalWhen: false),
            'reachable' => Field::bool('Reachable', $reachable, criticalWhen: false),
        ];

        // Only meaningful once we know the cluster actually answers - "reachable" already
        // covers the case where the cluster itself is down; this catches the case where it's
        // up but the product index is empty or missing, which "reachable: false" would not.
        if ($reachable) {
            $documentCount = $this->indexDocumentCount();
            if ($documentCount !== null) {
                $fields['index_document_count'] = Field::trackableNumber(
                    'Product Index Document Count',
                    $documentCount,
                    self::METRIC_INDEX_DOCUMENT_COUNT,
                    MetricDefinition::AGGREGATION_LATEST
                );
            }
        }

        return ['general' => Section::facts('general', 'General', $this->getDescription(), $fields)];
    }

    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_INDEX_DOCUMENT_COUNT,
                'Product Index Document Count',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_LT,
                1,
                120
            ),
        ];
    }

    private function indexDocumentCount(): ?int
    {
        try {
            $client = $this->clientResolver->create();

            if (!$client instanceof SearchClient) {
                // Engine-agnostic by design (see class docblock) - only OpenSearch exposes
                // the raw client this needs, so any other engine simply doesn't get this
                // metric rather than a hard failure.
                return null;
            }

            $indexName = $this->searchIndexNameResolver->getIndexName(
                (int)$this->storeManager->getStore()->getId(),
                Fulltext::INDEXER_ID
            );

            $result = $client->getOpenSearchClient()->count(['index' => $indexName]);

            return (int)($result['count'] ?? 0);
        } catch (Throwable $e) {
            // A missing index (the exact "disappeared" scenario this metric exists to catch)
            // throws rather than returning a count of 0 - treat that specific case as a real,
            // reportable 0 rather than silently omitting the field. Any other failure (a
            // transient network blip, auth issue, etc.) stays null/omitted rather than
            // reporting a value that could look like a real alertable state.
            return str_contains(strtolower($e->getMessage()), 'index_not_found') ? 0 : null;
        }
    }
}
