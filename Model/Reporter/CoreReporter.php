<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

class CoreReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '2.0';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly State $appState,
        private readonly Filesystem $filesystem
    ) {
    }

    public function getName(): string
    {
        return 'core';
    }

    public function getLabel(): string
    {
        return 'Core';
    }

    public function getDescription(): string
    {
        return 'Edition, version, PHP version, deployment mode, and static content deploy state.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        return [
            'edition' => Field::varchar('Edition', $this->productMetadata->getEdition()),
            'version' => Field::varchar('Magento Version', $this->productMetadata->getVersion()),
            'php_version' => Field::varchar('PHP Version', PHP_VERSION),
            'deployment_mode' => Field::varchar('Deployment Mode', $this->appState->getMode()),
            'static_content_deployed' => Field::bool('Static Content Deployed', $this->isStaticContentDeployed()),
        ];
    }

    /**
     * Proxy for "static content deployed ahead of time" vs "generated on the fly": the
     * deploy marker file setup:static-content:deploy writes to pub/static. Checked directly
     * rather than inferred from deployment mode alone, since developer-mode stores can still
     * have deployed static content and vice versa.
     */
    private function isStaticContentDeployed(): bool
    {
        try {
            return $this->filesystem
                ->getDirectoryRead(DirectoryList::STATIC_VIEW)
                ->isExist('deployed_version.txt');
        } catch (Throwable) {
            return false;
        }
    }
}
