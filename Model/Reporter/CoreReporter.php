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
use StackNuts\ViewGento\Api\ReporterInterface;
use Throwable;

class CoreReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

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

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        return [
            'edition' => $this->productMetadata->getEdition(),
            'version' => $this->productMetadata->getVersion(),
            'php_version' => PHP_VERSION,
            'deployment_mode' => $this->appState->getMode(),
            'static_content_deployed' => $this->isStaticContentDeployed(),
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
