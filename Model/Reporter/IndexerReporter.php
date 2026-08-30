<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Indexer\Model\Indexer\CollectionFactory;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

class IndexerReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '2.0';

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'indexers';
    }

    public function getLabel(): string
    {
        return 'Indexers';
    }

    public function getDescription(): string
    {
        return 'Per-indexer status and mode (schedule vs. update-on-save).';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $indexers = [];

        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $indexers[] = Field::array((string)$indexer->getId(), [
                'id' => Field::varchar('ID', (string)$indexer->getId()),
                'title' => Field::varchar('Title', (string)$indexer->getTitle()),
                'status' => Field::varchar('Status', (string)$indexer->getStatus()),
                'mode' => Field::varchar('Mode', $indexer->isScheduled() ? 'schedule' : 'save'),
                'updated_at' => Field::varchar('Updated At', (string)($indexer->getLatestUpdated() ?? '')),
            ]);
        }

        return ['indexers' => Field::array('Indexers', $indexers)];
    }
}
