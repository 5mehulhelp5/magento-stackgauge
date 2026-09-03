<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use Throwable;

class CoreReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '3.0';

    /**
     * ProductMetadataInterface::getEdition() returns "Community" on both vanilla Magento
     * Open Source and Mage-OS - the fork is source-compatible and never overrides it - so
     * the only reliable signal is which composer package actually shipped, same technique
     * ComposerReporter's KEY_PACKAGES watch-list already uses.
     */
    private const MAGE_OS_PACKAGES = [
        'mage-os/product-community-edition',
        'mage-os/framework',
    ];

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly State $appState,
        private readonly Filesystem $filesystem,
        private readonly Json $json
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
            'general' => Section::facts('general', 'General', $this->getDescription(), [
                'edition' => Field::varchar('Edition', $this->detectEdition()),
                'version' => Field::varchar('Magento Version', $this->productMetadata->getVersion()),
                'php_version' => Field::varchar('PHP Version', PHP_VERSION),
                'deployment_mode' => Field::varchar('Deployment Mode', $this->appState->getMode()),
                'static_content_deployed' => Field::bool('Static Content Deployed', $this->isStaticContentDeployed(), criticalWhen: false),
            ]),
        ];
    }

    private function detectEdition(): string
    {
        return $this->isMageOs() ? 'Mage-OS' : $this->productMetadata->getEdition();
    }

    private function isMageOs(): bool
    {
        try {
            $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);

            if (! $root->isExist('composer.lock')) {
                return false;
            }

            $data = $this->json->unserialize($root->readFile('composer.lock'));

            foreach ($data['packages'] ?? [] as $package) {
                if (in_array($package['name'] ?? null, self::MAGE_OS_PACKAGES, true)) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Malformed/unreadable composer.lock - fall back to Magento's own getEdition().
        }

        return false;
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
