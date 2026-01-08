<?php

declare(strict_types=1);

namespace Shopify\App\Types;

/**
 * Result from token exchange operations
 * Used by: exchangeUsingTokenExchange, refreshTokenExchangedAccessToken
 */
readonly class TokenExchangeResult
{
    public function __construct(
        public bool $ok,
        public ?string $shop,
        public ?TokenExchangeAccessToken $accessToken,
        public Log $log,
        public array $httpLogs,
        public ResponseInfo $response,
    ) {
    }
}
