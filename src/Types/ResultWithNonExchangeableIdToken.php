<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result with non-exchangeable ID token
 * Used by: verifyCheckoutUIExtReq, verifyCustomerAccountUIExtReq
 */
readonly class ResultWithNonExchangeableIdToken
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public ?IdToken $idToken,
        public LogWithReq $log,
        public ResponseInfo $response,
    ) {
    }
}
