<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'stacknuts_stackgauge/general/enabled';
    private const XML_PATH_ENDPOINT_URL = 'stacknuts_stackgauge/general/endpoint_url';
    private const XML_PATH_SITE_ID = 'stacknuts_stackgauge/general/site_id';
    private const XML_PATH_API_KEY = 'stacknuts_stackgauge/general/api_key';
    private const XML_PATH_HMAC_SECRET = 'stacknuts_stackgauge/general/hmac_secret';
    private const XML_PATH_REPORTERS = 'stacknuts_stackgauge/general/reporters';
    private const XML_PATH_LOG_LEVEL = 'stacknuts_stackgauge/log/log_level';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getEndpointUrl(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    public function getSiteId(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SITE_ID, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /**
     * The api_key field's backend_model (Magento\Config\Model\Config\Backend\Encrypted) only
     * encrypts on save via the admin form - ScopeConfigInterface returns the raw ciphertext
     * straight from core_config_data/env.php, so it must be decrypted here. Caught live: a
     * passive echo endpoint (e.g. httpbin) never notices an undecrypted value in the
     * Authorization header, since it doesn't validate the token - only a real receiving
     * server checking it against an actual credential exposes this.
     */
    public function getApiKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    public function getHmacSecret(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_HMAC_SECRET, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    /**
     * Built-in reporter codes enabled via the admin "Enabled Reporters" multiselect.
     * Third-party reporters registered through di.xml are not covered by this list -
     * see Api\ReporterInterface for why that's a deliberate v1 boundary.
     *
     * @return string[]
     */
    public function getEnabledReporterCodes(?int $storeId = null): array
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_REPORTERS, ScopeInterface::SCOPE_STORE, $storeId);

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function getLogLevel(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_LOG_LEVEL, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
