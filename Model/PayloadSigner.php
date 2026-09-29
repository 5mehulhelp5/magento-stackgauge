<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

/**
 * HMAC-SHA256 over the exact raw JSON body that goes over the wire - sign after
 * serialization, not the PHP array, so the dashboard can verify against the literal bytes
 * it received without needing to re-derive Magento's own JSON encoding.
 */
class PayloadSigner
{
    /**
     * Signs $rawBody with $secret - see this class's own docblock for why it signs raw bytes.
     *
     * @param string $rawBody
     * @param string $secret
     */
    public function sign(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }
}
