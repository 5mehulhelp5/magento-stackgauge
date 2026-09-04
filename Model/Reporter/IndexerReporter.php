<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;

class IndexerReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '4.0';

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
                'valid' => Field::bool(
                    'Valid',
                    $indexer->getStatus() === StateInterface::STATUS_VALID,
                    criticalWhen: false
                ),
                'mode' => Field::varchar('Mode', $indexer->isScheduled() ? 'schedule' : 'save'),
                'updated_at' => Field::datetime('Updated At', (string)$indexer->getLatestUpdated()),
            ]);
        }

        return ['indexers' => Section::table('indexers', $this->getLabel(), $this->getDescription(), $indexers, keyName: 'id')];
    }
}
