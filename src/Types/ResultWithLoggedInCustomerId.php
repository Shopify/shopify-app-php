<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result with logged in customer ID
 * Used by: verifyAppProxyReq
 */
readonly class ResultWithLoggedInCustomerId
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public ?string $loggedInCustomerId,
        public LogWithReq $log,
        public ResponseInfo $response,
    ) {
    }
}
