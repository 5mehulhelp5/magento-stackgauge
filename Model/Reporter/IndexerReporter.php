<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Indexer\Model\Indexer\CollectionFactory;
use StackNuts\ViewGento\Api\ReporterInterface;

class IndexerReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'indexers';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $indexers = [];

        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $indexers[] = [
                'id' => $indexer->getId(),
                'title' => $indexer->getTitle(),
                'status' => $indexer->getStatus(),
                'mode' => $indexer->isScheduled() ? 'schedule' : 'save',
                'updated_at' => $indexer->getLatestUpdated(),
            ];
        }

        return ['indexers' => $indexers];
    }
}
